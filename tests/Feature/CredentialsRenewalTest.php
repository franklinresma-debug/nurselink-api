<?php

namespace Tests\Feature;

use App\Models\CredentialReminderEvent;
use App\Models\Member;
use App\Models\ProfessionalCredential;
use App\Models\Role;
use App\Models\User;
use App\Services\Credentials\CredentialReminderService;
use App\Services\Credentials\CredentialStatusService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialsRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function memberUser(): array
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->roles()->attach(Role::where('code', 'member')->first());
        $m = Member::create(['user_id' => $u->id, 'member_no' => 'NL-2026-'.str_pad((string) (Member::count() + 1), 6, '0', STR_PAD_LEFT), 'status' => 'active', 'joined_at' => now()]);

        return [$u, $m];
    }

    public function test_member_can_create_credential_and_number_is_not_returned_in_plaintext(): void
    {
        [$u,$m] = $this->memberUser();
        $r = $this->actingAs($u)->postJson('/api/credentials', ['category' => 'license', 'credential_type' => 'prc_rn', 'title' => 'PRC Registered Nurse', 'credential_number' => 'RN-1234567', 'issuing_authority' => 'PRC', 'country' => 'PH', 'issued_on' => '2026-01-01', 'expires_on' => '2028-01-01']);
        $r->assertCreated()->assertJsonMissing(['credential_number' => 'RN-1234567']);
        $this->assertDatabaseHas('professional_credentials', ['member_id' => $m->id, 'credential_number_last4' => '4567']);
    }

    public function test_expiring_credential_is_classified_and_reminders_are_created(): void
    {
        [$u,$m] = $this->memberUser();
        $c = ProfessionalCredential::create(['member_id' => $m->id, 'category' => 'license', 'credential_type' => 'prc_rn', 'title' => 'PRC RN', 'credential_status' => 'active', 'verification_status' => 'unverified', 'expires_on' => '2026-10-01']);
        $today = CarbonImmutable::parse('2026-08-09');
        app(CredentialStatusService::class)->refresh($c, $today);
        $this->assertSame('expiring_soon', $c->fresh()->credential_status);
        app(CredentialReminderService::class)->rebuild($c->fresh());
        $this->assertSame(4, CredentialReminderEvent::where('credential_id', $c->id)->count());
    }

    public function test_member_cannot_update_another_members_credential(): void
    {
        [$u,$m] = $this->memberUser();
        [$other,$otherMember] = $this->memberUser();
        $c = ProfessionalCredential::create(['member_id' => $otherMember->id, 'category' => 'license', 'credential_type' => 'rn', 'title' => 'Other RN', 'credential_status' => 'active', 'verification_status' => 'unverified']);
        $this->actingAs($u)->patchJson('/api/credentials/'.$c->id, ['category' => 'license', 'credential_type' => 'rn', 'title' => 'Changed'])->assertForbidden();
    }

    public function test_dashboard_reports_credential_health(): void
    {
        [$u,$m] = $this->memberUser();
        ProfessionalCredential::create(['member_id' => $m->id, 'category' => 'license', 'credential_type' => 'rn', 'title' => 'RN', 'credential_status' => 'active', 'verification_status' => 'verified', 'does_not_expire' => true]);
        $this->actingAs($u)->getJson('/api/credentials/dashboard')->assertOk()->assertJsonPath('data.health.counts.total', 1)->assertJsonPath('data.health.counts.verified', 1);
    }
}
