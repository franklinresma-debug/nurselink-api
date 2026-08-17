<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NL010AnalyticsProductionTest extends TestCase
{
    public function test_liveness_endpoint_reports_current_build(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('build', 'NL-011.2-cpanel');
    }

    public function test_readiness_endpoint_exists(): void
    {
        $response = $this->getJson('/api/health/ready');

        $this->assertContains($response->getStatusCode(), [200, 503]);
    }

    public function test_readiness_cache_probe_does_not_collide_with_an_existing_probe(): void
    {
        Cache::put('nurselink:ready', 'existing-probe', 60);

        try {
            $this->getJson('/api/health/ready')->assertOk();
            $this->assertSame('existing-probe', Cache::get('nurselink:ready'));
        } finally {
            Cache::forget('nurselink:ready');
        }
    }
}
