<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotifyPolicyConsentCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_reminds_active_users_with_outdated_consent_and_is_idempotent(): void
    {
        config()->set('registration.terms_version', 'terms-v2');
        config()->set('registration.privacy_version', 'privacy-v2');

        $pending = User::factory()->create(['status' => 'active']);
        User::factory()->create([
            'status' => 'active',
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
            'terms_version' => 'terms-v2',
            'privacy_version' => 'privacy-v2',
        ]);
        User::factory()->create(['status' => 'inactive']);

        $this->artisan('nurselink:notify-policy-consent --send')->assertSuccessful();
        $this->artisan('nurselink:notify-policy-consent --send')->assertSuccessful();

        $this->assertDatabaseCount('nurselink_notifications', 1);
        $this->assertDatabaseHas('nurselink_notifications', [
            'user_id' => $pending->id,
            'type' => 'policy_consent_required',
            'action_url' => '/policy-center',
        ]);
        $this->assertNull($pending->refresh()->terms_accepted_at);
        $this->assertNull($pending->privacy_accepted_at);
    }

    public function test_dry_run_does_not_create_notifications(): void
    {
        User::factory()->create(['status' => 'active']);

        $this->artisan('nurselink:notify-policy-consent')->assertSuccessful();

        $this->assertDatabaseCount('nurselink_notifications', 0);
    }
}
