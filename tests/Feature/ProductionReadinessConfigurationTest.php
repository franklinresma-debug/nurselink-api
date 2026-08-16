<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ProductionReadinessController;
use ReflectionMethod;
use Tests\TestCase;

class ProductionReadinessConfigurationTest extends TestCase
{
    public function test_backup_age_accepts_archive_files_at_backup_root(): void
    {
        $root = sys_get_temp_dir().'/nurselink-readiness-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);

        $archive = $root.'/nurselink-backup.tar.gz';
        file_put_contents($archive, 'test-backup');
        touch($archive, time() - 3600);

        try {
            $method = new ReflectionMethod(
                ProductionReadinessController::class,
                'latestBackupAgeHours'
            );

            $age = $method->invoke(
                app(ProductionReadinessController::class),
                $root
            );

            $this->assertNotNull($age);
            $this->assertGreaterThanOrEqual(1.0, $age);
            $this->assertLessThan(1.1, $age);
        } finally {
            @unlink($archive);
            @rmdir($root);
        }
    }

    public function test_missing_backup_root_returns_unknown_age(): void
    {
        $method = new ReflectionMethod(
            ProductionReadinessController::class,
            'latestBackupAgeHours'
        );

        $age = $method->invoke(
            app(ProductionReadinessController::class),
            '/path/that/does/not/exist'
        );

        $this->assertNull($age);
    }
}
