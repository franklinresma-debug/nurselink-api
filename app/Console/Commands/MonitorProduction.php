<?php

namespace App\Console\Commands;

use App\Notifications\OperationsMonitorAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Throwable;

class MonitorProduction extends Command
{
    protected $signature = 'nurselink:monitor-production
        {--force-alert : Send a healthy test notification}
        {--incident= : Record an external service failure and alert operations}';
    protected $description = 'Check NurseLink production health and notify operations on state changes';

    public function handle(): int
    {
        $checks = [
            'web' => $this->httpCheck((string) config('operations.monitoring.web_url')),
            'api_readiness' => $this->httpCheck((string) config('operations.monitoring.readiness_url'), true),
            'failed_jobs' => $this->failedJobsCheck(),
            'queue_lag' => $this->queueLagCheck(),
            'disk' => $this->diskCheck(),
            'backup' => $this->backupCheck(),
        ];
        if ($incident = trim((string) $this->option('incident'))) {
            $checks['external_incident'] = ['status' => 'fail', 'detail' => $incident];
        }
        $state = array_filter($checks, fn (array $check): bool => $check['status'] !== 'ok') === [] ? 'healthy' : 'failed';
        $previous = (string) Cache::get('nurselink:production-monitor:last-state', 'unknown');
        $forceAlert = (bool) $this->option('force-alert');

        if ($forceAlert || ($state === 'failed' && $previous !== 'failed') || ($state === 'healthy' && $previous === 'failed')) {
            $this->sendAlert($state, $checks, $forceAlert);
        }

        Cache::put('nurselink:production-monitor:last-state', $state, now()->addDays(30));
        foreach ($checks as $name => $check) {
            $this->line(sprintf('[%s] %s: %s', strtoupper($check['status']), $name, $check['detail']));
        }

        return $state === 'healthy' ? self::SUCCESS : self::FAILURE;
    }

    private function httpCheck(string $url, bool $expectReady = false): array
    {
        try {
            $response = Http::acceptJson()->timeout(10)->get($url);
            $ready = $response->successful() && (! $expectReady || $response->json('status') === 'ok');
            return ['status' => $ready ? 'ok' : 'fail', 'detail' => sprintf('%s returned HTTP %d%s.', $url, $response->status(), $expectReady ? ' with status '.($response->json('status') ?? 'missing') : '')];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => $url.' failed: '.mb_substr($e->getMessage(), 0, 220)];
        }
    }

    private function failedJobsCheck(): array
    {
        $count = DB::table('failed_jobs')->count();
        return ['status' => $count === 0 ? 'ok' : 'fail', 'detail' => $count.' failed queue job(s).'];
    }

    private function queueLagCheck(): array
    {
        $minutes = (int) config('operations.monitoring.queue_lag_minutes', 15);
        $stale = DB::table('jobs')->where('available_at', '<=', now()->subMinutes($minutes)->timestamp)->count();
        return ['status' => $stale === 0 ? 'ok' : 'fail', 'detail' => $stale.' queued job(s) available for more than '.$minutes.' minutes.'];
    }

    private function diskCheck(): array
    {
        $total = @disk_total_space('/');
        $free = @disk_free_space('/');
        $percent = $total && $free !== false ? round(($free / $total) * 100, 1) : null;
        $minimum = (float) config('operations.monitoring.minimum_disk_free_percent', 15);
        return ['status' => $percent !== null && $percent >= $minimum ? 'ok' : 'fail', 'detail' => $percent === null ? 'Disk capacity could not be read.' : $percent.'% disk space free (minimum '.$minimum.'%).'];
    }

    private function backupCheck(): array
    {
        $root = (string) config('operations.backup.root');
        $maximumAge = (int) config('operations.monitoring.maximum_backup_age_hours', 26);
        $directories = is_dir($root) ? glob($root.'/*', GLOB_ONLYDIR) ?: [] : [];
        $directories = array_values(array_filter($directories, fn (string $path): bool => ! str_starts_with(basename($path), '.')));
        usort($directories, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $latest = $directories[0] ?? null;

        if (! $latest) return ['status' => 'fail', 'detail' => 'No completed backup directory was found in '.$root.'.'];

        $ageHours = round((time() - filemtime($latest)) / 3600, 1);
        foreach (['database.sql.gz', 'nurselink-api.tar.gz', 'nurselink-web.tar.gz', 'manifest.json', 'SHA256SUMS'] as $file) {
            if (! is_file($latest.'/'.$file) || filesize($latest.'/'.$file) === 0) return ['status' => 'fail', 'detail' => basename($latest).' is missing '.$file.'.'];
        }
        if (! $this->checksumsMatch($latest)) return ['status' => 'fail', 'detail' => basename($latest).' failed SHA-256 verification.'];

        return ['status' => $ageHours <= $maximumAge ? 'ok' : 'fail', 'detail' => basename($latest).' is '.$ageHours.' hours old; checksums verified (maximum '.$maximumAge.' hours).'];
    }

    private function checksumsMatch(string $directory): bool
    {
        $lines = file($directory.'/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if (! preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', trim($line), $matches)) return false;
            $path = $directory.'/'.basename($matches[2]);
            if (! is_file($path) || ! hash_equals(strtolower($matches[1]), hash_file('sha256', $path))) return false;
        }
        return count($lines) >= 4;
    }

    private function sendAlert(string $state, array $checks, bool $test): void
    {
        $recipient = (string) config('operations.monitoring.alert_email');
        $subject = $test ? '[NurseLink] Monitoring test — '.$state : ($state === 'healthy' ? '[NurseLink] Production recovered' : '[NurseLink] Production alert');
        $body = "NurseLink production monitoring\nState: ".strtoupper($state)."\nChecked: ".now()->toIso8601String()."\n\n";
        foreach ($checks as $name => $check) $body .= sprintf("[%s] %s: %s\n", strtoupper($check['status']), $name, $check['detail']);
        Notification::route('mail', $recipient)->notify(new OperationsMonitorAlert($subject, $body));
        $this->info('Operational alert sent to '.$recipient.'.');
    }
}
