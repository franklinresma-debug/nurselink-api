<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceScanTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'status' => 'active',
        ]);

        DB::table('nurselink_reviewer_access')->insert([
            'user_id' => $user->id,
            'role' => 'admin',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function activeMembership(): array
    {
        $member = User::factory()->create([
            'email_verified_at' => now(),
            'status' => 'active',
        ]);

        $id = DB::table('nurselink_memberships')->insertGetId([
            'user_id' => $member->id,
            'status' => 'approved',
            'standing' => 'active',
            'member_number' => 'NL-2026-009999',
            'verification_code' => 'test-service-scan-verification-code',
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$member, $id];
    }

    public function test_staff_can_resolve_and_record_active_member_service_use(): void
    {
        $staff = $this->staff();
        [, $membershipId] = $this->activeMembership();
        $url = 'https://app.amsertech.com/nurselink-member-verify.html?code=test-service-scan-verification-code';

        $this->actingAs($staff)
            ->postJson('/api/nurselink/admin/service-scans/resolve', ['value' => $url])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.member_number', 'NL-2026-009999');

        $this->actingAs($staff)
            ->postJson('/api/nurselink/admin/service-scans', [
                'value' => $url,
                'purpose' => 'workshop',
                'reference_type' => 'workshop',
                'reference_label' => 'Emergency Nursing Workshop',
            ])
            ->assertCreated()
            ->assertJsonPath('data.member.member_number', 'NL-2026-009999')
            ->assertJsonPath('data.scan.purpose', 'workshop');

        $this->assertDatabaseHas('nurselink_service_scans', [
            'membership_id' => $membershipId,
            'purpose' => 'workshop',
            'reference_label' => 'Emergency Nursing Workshop',
            'recorded_by' => $staff->id,
        ]);
    }

    public function test_duplicate_scan_is_rejected_and_inactive_member_cannot_be_recorded(): void
    {
        $staff = $this->staff();
        [, $membershipId] = $this->activeMembership();

        $payload = [
            'value' => 'NL-2026-009999',
            'purpose' => 'benefit',
            'reference_label' => 'Pilot Member Benefit',
        ];

        $this->actingAs($staff)->postJson('/api/nurselink/admin/service-scans', $payload)->assertCreated();
        $this->actingAs($staff)->postJson('/api/nurselink/admin/service-scans', $payload)
            ->assertStatus(409)
            ->assertJsonPath('duplicate', true);

        DB::table('nurselink_memberships')->where('id', $membershipId)->update(['standing' => 'suspended']);

        $this->actingAs($staff)
            ->postJson('/api/nurselink/admin/service-scans/resolve', ['value' => 'NL-2026-009999'])
            ->assertStatus(422);
    }
}
