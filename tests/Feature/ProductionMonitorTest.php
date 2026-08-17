<?php

namespace Tests\Feature;

use App\Notifications\OperationsMonitorAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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
}
