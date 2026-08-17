<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordRecoveryThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_requests_are_rate_limited_by_email(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/forgot-password', [
                'email' => 'recovery-limit@example.com',
            ])->assertUnprocessable();
        }

        $this->postJson('/forgot-password', [
            'email' => 'recovery-limit@example.com',
        ])->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many password recovery attempts. Please wait before trying again.');
    }

    public function test_reset_submissions_are_rate_limited_by_email(): void
    {
        $payload = [
            'token' => 'invalid-token',
            'email' => 'reset-limit@example.com',
            'password' => 'Very-Strong-Password-2026!',
            'password_confirmation' => 'Very-Strong-Password-2026!',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/reset-password', $payload)->assertUnprocessable();
        }

        $this->postJson('/reset-password', $payload)
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }
}
