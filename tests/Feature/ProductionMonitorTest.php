<?php

namespace Tests\Feature;

use App\Notifications\OperationsMonitorAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Http\Client\Request;
use Tests\TestCase;

class ProductionMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthy_monitor_checks_and_test_alert_succeed(): void
    {
        $root = sys_get_temp_dir().'/nurselink-monitor-'.bin2hex(random_bytes(6));
        $backup = $root.'/2026-08-17_000000Z';
        mkdir($backup, 0700, true);
        $files = ['database.sql.gz', 'nurselink-api.tar.gz', 'nurselink-web.tar.gz', 'manifest.json'];
        foreach ($files as $file) file_put_contents($backup.'/'.$file, 'monitor-test-'.$file);
        $lines = array_map(fn (string $file): string => hash_file('sha256', $backup.'/'.$file).'  '.$file, $files);
        file_put_contents($backup.'/SHA256SUMS', implode("\n", $lines)."\n");

        config()->set('operations.backup.root', $root);
        config()->set('operations.monitoring.minimum_disk_free_percent', 0);
        config()->set('operations.monitoring.alert_email', 'operations@example.test');
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        Notification::fake();

        try {
            $this->artisan('nurselink:monitor-production', ['--force-alert' => true])->assertSuccessful();
            Notification::assertSentOnDemand(OperationsMonitorAlert::class);
        } finally {
            foreach (glob($backup.'/*') ?: [] as $file) @unlink($file);
            @rmdir($backup);
            @rmdir($root);
        }
    }

    public function test_monitor_recovers_from_a_transient_readiness_failure(): void
    {
        $root = sys_get_temp_dir().'/nurselink-monitor-'.bin2hex(random_bytes(6));
        $backup = $root.'/2026-08-17_000000Z';
        mkdir($backup, 0700, true);
        $files = ['database.sql.gz', 'nurselink-api.tar.gz', 'nurselink-web.tar.gz', 'manifest.json'];
        foreach ($files as $file) file_put_contents($backup.'/'.$file, 'monitor-test-'.$file);
        $lines = array_map(fn (string $file): string => hash_file('sha256', $backup.'/'.$file).'  '.$file, $files);
        file_put_contents($backup.'/SHA256SUMS', implode("\n", $lines)."\n");

        config()->set('operations.backup.root', $root);
        config()->set('operations.monitoring.minimum_disk_free_percent', 0);
        config()->set('operations.monitoring.http_attempts', 2);
        config()->set('operations.monitoring.http_retry_delay_ms', 0);
        $readinessAttempts = 0;
        Http::fake(function (Request $request) use (&$readinessAttempts) {
            if (str_contains($request->url(), '/api/health/ready')) {
                $readinessAttempts++;

                return $readinessAttempts === 1
                    ? Http::response(['status' => 'unavailable'], 503)
                    : Http::response(['status' => 'ok'], 200);
            }

            return Http::response(['status' => 'ok'], 200);
        });
        Notification::fake();

        try {
            $this->artisan('nurselink:monitor-production')
                ->expectsOutputToContain('after 2 attempts')
                ->assertSuccessful();
            $this->assertSame(2, $readinessAttempts);
            Notification::assertNothingSent();
        } finally {
            foreach (glob($backup.'/*') ?: [] as $file) @unlink($file);
            @rmdir($backup);
            @rmdir($root);
        }
    }
}
