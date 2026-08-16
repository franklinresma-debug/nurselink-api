<?php

namespace Tests\Feature;

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
}
