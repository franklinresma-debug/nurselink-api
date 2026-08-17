<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PolicyConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_user_can_explicitly_accept_current_policies(): void
    {
        config()->set('registration.terms_version', 'terms-v2');
        config()->set('registration.privacy_version', 'privacy-v2');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/policy-consent')
            ->assertOk()
            ->assertJsonPath('data.current', false);

        $this->postJson('/api/policy-consent', [
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ])->assertOk()
            ->assertJsonPath('data.current', true)
            ->assertJsonPath('data.terms_version', 'terms-v2')
            ->assertJsonPath('data.privacy_version', 'privacy-v2');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'terms_version' => 'terms-v2',
            'privacy_version' => 'privacy-v2',
        ]);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.policy_consent_accepted')->where('actor_user_id', $user->id)->exists());
    }

    public function test_policy_acceptance_cannot_be_partially_submitted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/policy-consent', [
            'terms_accepted' => true,
            'privacy_accepted' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('privacy_accepted');

        $this->assertNull($user->refresh()->terms_accepted_at);
    }
}
