<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('nurselink:make-super-admin {email}', function (string $email) {
    $email = mb_strtolower(trim($email));
    $role = Role::query()->where('code', 'super_administrator')->firstOrFail();
    $user = User::query()->where('email', $email)->first();

    if (!$user) {
        $password = Str::password(20);
        $user = User::query()->create([
            'name' => 'NurseLink Super Administrator',
            'email' => $email,
            'password' => Hash::make($password),
            'status' => 'active',
            'mfa_required' => true,
        ]);
        $this->warn('Temporary password: '.$password);
        $this->warn('Change this password immediately after first login.');
    }

    $user->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now()]]);
    $user->forceFill(['mfa_required' => true])->save();

    $this->info('Super Administrator role applied to '.$email.'. Email verification and MFA are required.');
})->purpose('Create or promote the initial NurseLink Super Administrator');


Schedule::command('nurselink:credentials-refresh --queue-due')->dailyAt('01:15')->withoutOverlapping();

Schedule::command('nurselink:communications-dispatch --ingest')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('nurselink:analytics-snapshot', function (\App\Services\Analytics\AnalyticsSnapshotService $service) {
    $snapshot = $service->capture();
    $this->info('Captured executive analytics snapshot for '.$snapshot->snapshot_date->toDateString());
})->purpose('Capture the daily NurseLink executive analytics snapshot');

Artisan::command('nurselink:ops-readiness', function (\App\Services\Operations\ReadinessCheckService $service) {
    $result = $service->check(true);
    $this->line(json_encode($result, JSON_PRETTY_PRINT));
    return $result['status'] === 'fail' ? 1 : 0;
})->purpose('Run and persist NurseLink production readiness checks');

Artisan::command('nurselink:reports-prune', function () {
    $cutoff = now()->subDays(config('analytics.export_retention_days',14));
    $jobs = \App\Models\ReportExportJob::query()->where('created_at','<',$cutoff)->get();
    foreach ($jobs as $job) {
        if ($job->storage_disk && $job->storage_path) \Illuminate\Support\Facades\Storage::disk($job->storage_disk)->delete($job->storage_path);
        $job->delete();
    }
    $this->info('Pruned '.$jobs->count().' expired report exports.');
})->purpose('Remove expired NurseLink report exports');

Schedule::command('nurselink:analytics-snapshot')->dailyAt('02:05')->withoutOverlapping();
Schedule::command('nurselink:reports-prune')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('nurselink:ops-readiness')->everyThirtyMinutes()->withoutOverlapping();

// NL-011 Integrated Staging & UAT
Artisan::command('nurselink:scan-documents {--limit=100}', function () {
    $scanner = app(\App\Services\Security\ClamAvScanner::class);
    $limit = max(1, min(1000, (int) $this->option('limit')));
    $processed = 0;
    $errors = 0;

    $batches = [
        [\App\Models\ApplicationDocument::class, 'malware_scan_status', 'disk', 'path', 'application'],
        [\App\Models\MemberDocument::class, 'security_status', 'storage_disk', 'storage_path', 'member'],
        [\App\Models\InitiativeDocument::class, 'security_status', 'storage_disk', 'storage_path', 'initiative'],
        [\App\Models\PolicyDocument::class, 'security_status', 'storage_disk', 'storage_path', 'policy'],
    ];

    foreach ($batches as [$model, $statusColumn, $diskColumn, $pathColumn, $label]) {
        if ($processed >= $limit) break;
        $remaining = $limit - $processed;
        $records = $model::query()->where($statusColumn, 'pending')->limit($remaining)->get();
        foreach ($records as $record) {
            try {
                $result = $scanner->scan((string) $record->{$diskColumn}, (string) $record->{$pathColumn});
                $record->{$statusColumn} = $result['status'] === 'clean' ? 'clean' : ($label === 'application' ? 'infected' : 'quarantined');
                if ($label === 'application') $record->malware_scanned_at = now();
                $record->save();
                $this->line(sprintf('%s %s: %s', $label, $record->getKey(), $record->{$statusColumn}));
            } catch (\Throwable $e) {
                $errors++;
                $this->error(sprintf('%s %s: %s', $label, $record->getKey(), $e->getMessage()));
            }
            $processed++;
        }
    }

    $this->info("Processed {$processed} pending document(s); errors={$errors}.");
    return $errors > 0 ? 2 : 0;
})->purpose('Scan pending NurseLink documents with the configured ClamAV service');

Artisan::command('nurselink:integration-check {--deep}', function () {
    $checks = [];
    $record = function (string $name, bool $ok, string $detail = '') use (&$checks) {
        $checks[] = ['check' => $name, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail];
    };

    try { \Illuminate\Support\Facades\DB::select('select 1'); $record('database', true, 'query ok'); }
    catch (\Throwable $e) { $record('database', false, $e->getMessage()); }

    foreach (['users','roles','applications','members','professional_credentials','qualification_frameworks','inbox_messages','events','initiatives','policy_records','analytics_snapshots'] as $table) {
        try { $record('table:'.$table, \Illuminate\Support\Facades\Schema::hasTable($table)); }
        catch (\Throwable $e) { $record('table:'.$table, false, $e->getMessage()); }
    }

    try {
        \Illuminate\Support\Facades\Cache::put('nurselink:nl0111:probe', 'ok', 60);
        $ok = \Illuminate\Support\Facades\Cache::get('nurselink:nl0111:probe') === 'ok';
        \Illuminate\Support\Facades\Cache::forget('nurselink:nl0111:probe');
        $record('cache', $ok, config('cache.default'));
    } catch (\Throwable $e) { $record('cache', false, $e->getMessage()); }

    try {
        $disk = config('smart_registration.private_disk', 'private');
        $probe = 'integration/nl011-'.(string) \Illuminate\Support\Str::uuid().'.txt';
        \Illuminate\Support\Facades\Storage::disk($disk)->put($probe, 'NurseLink NL-011.1 storage probe');
        $ok = \Illuminate\Support\Facades\Storage::disk($disk)->exists($probe);
        \Illuminate\Support\Facades\Storage::disk($disk)->delete($probe);
        $record('private-storage', $ok, $disk);
    } catch (\Throwable $e) { $record('private-storage', false, $e->getMessage()); }

    $record('environment:online', app()->environment(['production','staging']), 'APP_ENV='.app()->environment());
    $record('queue:persistent', config('queue.default') !== 'sync', 'queue='.config('queue.default'));
    $record('session:not-file', config('session.driver') !== 'file', 'session='.config('session.driver'));
    $record('private-disk:configured', (string) config('smart_registration.private_disk', 'private') === 'private', 'disk='.config('smart_registration.private_disk', 'private'));

    try {
        $record('seed:roles', \App\Models\Role::query()->count() >= 10, 'roles='.\App\Models\Role::query()->count());
        $record('seed:frameworks', \App\Models\QualificationFramework::query()->count() >= 2, 'frameworks='.\App\Models\QualificationFramework::query()->count());
    } catch (\Throwable $e) { $record('seed-data', false, $e->getMessage()); }

    if ($this->option('deep')) {
        if ((string) config('security_scanning.driver') === 'disabled') {
            $record('malware-scanner', true, 'Disabled for this deployment; uploaded documents remain pending until a scanner/manual review workflow is configured.');
        } else {
            try {
                $host = (string) config('security_scanning.clamav.host');
                $port = (int) config('security_scanning.clamav.port');
                $socket = @fsockopen($host, $port, $errno, $errstr, 3);
                $record('clamav', (bool) $socket, $socket ? "{$host}:{$port}" : "{$errstr} ({$errno})");
                if ($socket) fclose($socket);
            } catch (\Throwable $e) { $record('clamav', false, $e->getMessage()); }
        }
    }

    $failed = collect($checks)->where('status', 'fail')->count();
    foreach ($checks as $c) {
        $this->line(sprintf('[%s] %s%s', strtoupper($c['status']), $c['check'], $c['detail'] ? ' — '.$c['detail'] : ''));
    }
    $this->newLine();
    $this->line(json_encode(['build'=>'NL-011.2-cpanel','status'=>$failed ? 'fail' : 'pass','failed'=>$failed,'checks'=>$checks], JSON_PRETTY_PRINT));
    return $failed ? 1 : 0;
})->purpose('Run cross-module NurseLink staging integration checks');

Schedule::command('nurselink:scan-documents --limit=100')->everyFiveMinutes()->withoutOverlapping();
