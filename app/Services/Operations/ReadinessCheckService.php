<?php
namespace App\Services\Operations;

use App\Models\OperationalCheckRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReadinessCheckService
{
    public function check(bool $persist = false): array
    {
        $checks = [];

        $checks['database'] = $this->probe(fn () => DB::select('select 1'), 'Database query successful.');

        $checks['cache'] = $this->probe(function () {
            Cache::put('nurselink:ready', now()->timestamp, 10);
            $value = Cache::get('nurselink:ready');
            Cache::forget('nurselink:ready');
            if ($value === null) throw new \RuntimeException('Cache probe value could not be read back.');
        }, 'Cache read/write successful via '.config('cache.default').'.');

        $privateDisk = (string) config('smart_registration.private_disk', env('NURSELINK_PRIVATE_DISK', 'private'));
        $checks['private_storage'] = $this->probe(function () use ($privateDisk) {
            $path = 'health/'.uniqid('nl-', true).'.txt';
            Storage::disk($privateDisk)->put($path, 'ok');
            if (!Storage::disk($privateDisk)->exists($path)) {
                throw new \RuntimeException('Private storage probe was written but could not be read back.');
            }
            Storage::disk($privateDisk)->delete($path);
        }, 'Private disk: '.$privateDisk.'.');

        $queue = (string) config('queue.default');
        $checks['queue'] = [
            'status' => $queue === 'sync' ? 'warning' : 'ok',
            'detail' => $queue === 'sync'
                ? 'Queue driver is sync; a persistent queue is required for staging/production.'
                : 'Queue driver: '.$queue.'.',
        ];

        $checks['configuration'] = $this->configurationCheck();

        $overall = collect($checks)->contains(fn ($c) => $c['status'] === 'fail')
            ? 'fail'
            : (collect($checks)->contains(fn ($c) => $c['status'] === 'warning') ? 'warning' : 'ok');

        $data = [
            'status' => $overall,
            'release' => config('operations.release'),
            'environment' => config('operations.environment_label'),
            'checks' => $checks,
            'checked_at' => now()->toIso8601String(),
        ];

        if ($persist) {
            OperationalCheckRun::query()->create([
                'environment' => $data['environment'],
                'release' => $data['release'],
                'status' => $overall,
                'checks' => $checks,
                'checked_at' => now(),
            ]);
        }

        return $data;
    }

    private function configurationCheck(): array
    {
        $errors = [];
        $warnings = [];

        if (app()->environment('production') && config('app.debug')) $errors[] = 'APP_DEBUG must be false in production.';
        if (app()->environment('production') && !config('app.key')) $errors[] = 'APP_KEY is missing.';
        if ((string) config('queue.default') === 'sync') $warnings[] = 'QUEUE_CONNECTION is sync.';
        if ((string) config('session.driver') === 'file' && !app()->environment('local')) $warnings[] = 'SESSION_DRIVER=file is not recommended for shared staging/production.';
        if ((string) config('security_scanning.driver') === 'disabled') $warnings[] = 'Malware scanner is disabled.';

        return [
            'status' => $errors ? 'fail' : ($warnings ? 'warning' : 'ok'),
            'detail' => implode(' ', array_merge($errors, $warnings)) ?: 'Critical runtime configuration is present.',
        ];
    }

    private function probe(callable $fn, string $successDetail = ''): array
    {
        try {
            $fn();
            return ['status' => 'ok', 'detail' => $successDetail];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'detail' => mb_substr($e->getMessage(), 0, 300)];
        }
    }
}
