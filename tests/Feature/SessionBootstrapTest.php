<?php

namespace Tests\Feature;

use Tests\TestCase;

class SessionBootstrapTest extends TestCase
{
    public function test_session_bootstrap_rejects_non_frontend_origins(): void
    {
        config(['app.frontend_url' => 'https://lbs.amsertech.com']);
        $this->postJson('/api/nurselink/session-bootstrap', [], [
            'Origin' => 'https://example.com',
        ])->assertForbidden();
    }

    public function test_session_bootstrap_expires_host_and_configured_domain_cookies(): void
    {
        config([
            'app.frontend_url' => 'https://lbs.amsertech.com',
            'session.domain' => 'lbs.amsertech.com',
            'session.secure' => true,
            'session.cookie' => 'lbs_nurselink_session',
            'sanctum.stateful' => ['lbs.amsertech.com'],
        ]);
        $response = $this->withSession(['old_login_marker' => 'stale'])->postJson('/api/nurselink/session-bootstrap', [], [
            'Origin' => 'https://lbs.amsertech.com',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('bootstrap', true);

        $response->assertSessionMissing('old_login_marker');

        $cookies = $response->headers->getCookies();

        $this->assertCount(8, $cookies);
        foreach ($cookies as $cookie) {
            $this->assertContains($cookie->getDomain(), [null, '', 'lbs.amsertech.com']);
            $this->assertTrue($cookie->isSecure());
            // Stateful middleware issues a fresh session and CSRF cookie after reset.
            $fresh = $cookie->getDomain() === 'lbs.amsertech.com'
                && in_array($cookie->getName(), ['lbs_nurselink_session', 'XSRF-TOKEN'], true);
            if ($fresh) {
                $this->assertGreaterThan(time(), $cookie->getExpiresTime());
            } else {
                $this->assertLessThan(time(), $cookie->getExpiresTime());
            }
            $this->assertSame($cookie->getName() !== 'XSRF-TOKEN', $cookie->isHttpOnly());
        }
    }

    public function test_local_http_bootstrap_uses_host_only_non_secure_cookies(): void
    {
        config([
            'app.frontend_url' => 'http://127.0.0.1:5173',
            'session.domain' => null,
            'session.secure' => false,
            'session.cookie' => 'lbs_nurselink_local_session',
            'sanctum.stateful' => ['127.0.0.1:5173'],
        ]);
        $response = $this->postJson('/api/nurselink/session-bootstrap', [], [
            'Origin' => 'http://127.0.0.1:5173',
        ])->assertOk();

        $cookies = $response->headers->getCookies();
        $this->assertCount(4, $cookies);
        foreach ($cookies as $cookie) {
            $this->assertEmpty($cookie->getDomain());
            $this->assertFalse($cookie->isSecure());
        }
    }

    public function test_bootstrap_requires_post_and_an_origin(): void
    {
        $this->getJson('/api/nurselink/session-bootstrap')->assertStatus(405);
        $this->postJson('/api/nurselink/session-bootstrap')->assertForbidden();
    }
}
