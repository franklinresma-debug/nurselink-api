<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordRecoveryThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_response_does_not_disclose_account_existence(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'known-account@example.com']);

        $known = $this->postJson('/forgot-password', [
            'email' => 'known-account@example.com',
        ])->assertSuccessful();

        $unknown = $this->postJson('/forgot-password', [
            'email' => 'unknown-account@example.com',
        ])->assertSuccessful();

        $this->assertSame($known->json(), $unknown->json());
        $known->assertJsonPath(
            'message',
            'If an account exists for this email, a password reset link has been sent.'
        );
    }

    public function test_reset_link_requests_are_rate_limited_by_email(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/forgot-password', [
                'email' => 'recovery-limit@example.com',
            ])->assertSuccessful();
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
