<?php

namespace Tests\Feature;

use Tests\TestCase;

class SessionBootstrapTest extends TestCase
{
    public function test_session_bootstrap_rejects_non_frontend_origins(): void
    {
        $this->getJson('/api/nurselink/session-bootstrap', [
            'Origin' => 'https://example.com',
        ])->assertForbidden();
    }

    public function test_session_bootstrap_accepts_frontend_and_expires_cookie_variants(): void
    {
        $response = $this->getJson('/api/nurselink/session-bootstrap', [
            'Origin' => 'https://app.amsertech.com',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('bootstrap', true);

        $cookies = $response->headers->getCookies();

        $this->assertCount(9, $cookies);
        $this->assertTrue(collect($cookies)->contains(
            fn ($cookie): bool => $cookie->getName() === 'XSRF-TOKEN'
                && ! $cookie->isHttpOnly()
        ));
    }
}
