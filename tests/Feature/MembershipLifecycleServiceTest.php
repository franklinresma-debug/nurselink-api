<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Services\CoreMembershipActivationService;
use App\Services\MembershipLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MembershipLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $status = 'approved', string $standing = 'active'): array
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'status' => 'active',
        ]);

        $membershipId = DB::table('nurselink_memberships')->insertGetId([
            'user_id' => $user->id,
            'status' => $status,
            'standing' => $standing,
            'member_number' => $status === 'approved' ? 'NL-2026-TEST-' . $user->id : null,
            'verification_code' => $status === 'approved'
                ? 'verification-' . $user->id
                : null,
            'approved_at' => $status === 'approved' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membership = DB::table('nurselink_memberships')
            ->where('id', $membershipId)
            ->first();

        return [$user, $membership];
    }

    public function test_approved_membership_creates_onboarding_record(): void
    {
        [, $membership] = $this->member();

        $created = app(MembershipLifecycleService::class)
            ->ensureOnboarding($membership);

        $this->assertTrue($created);

        $this->assertDatabaseHas('nurselink_membership_onboarding', [
            'membership_id' => $membership->id,
            'user_id' => $membership->user_id,
            'status' => 'pending',
        ]);
    }

    public function test_onboarding_creation_is_idempotent(): void
    {
        [, $membership] = $this->member();

        $service = app(MembershipLifecycleService::class);

        $this->assertTrue($service->ensureOnboarding($membership));
        $this->assertFalse($service->ensureOnboarding($membership));

        $this->assertSame(
            1,
            DB::table('nurselink_membership_onboarding')
                ->where('membership_id', $membership->id)
                ->count()
        );
    }

    public function test_non_approved_membership_does_not_create_onboarding(): void
    {
        [, $membership] = $this->member('submitted', 'active');

        $created = app(MembershipLifecycleService::class)
            ->ensureOnboarding($membership);

        $this->assertFalse($created);

        $this->assertDatabaseMissing('nurselink_membership_onboarding', [
            'membership_id' => $membership->id,
        ]);
    }

    public function test_membership_transition_history_is_recorded(): void
    {
        [$actor, $membership] = $this->member();

        app(MembershipLifecycleService::class)->recordTransition(
            $membership,
            'ready_for_approval',
            'approved',
            (string) $actor->id,
            'administrator',
            'Membership approved during lifecycle test.',
            ['test' => true]
        );

        $this->assertDatabaseHas('nurselink_membership_status_history', [
            'membership_id' => $membership->id,
            'from_status' => 'ready_for_approval',
            'to_status' => 'approved',
            'actor_user_id' => $actor->id,
            'actor_type' => 'administrator',
        ]);
    }

    public function test_onboarding_progress_does_not_change_membership_standing(): void
    {
        [$user, $membership] = $this->member('approved', 'active');

        app(MembershipLifecycleService::class)
            ->ensureOnboarding($membership);

        $this->actingAs($user)
            ->postJson('/api/membership/onboarding/progress', [
                'action' => 'orientation_completed',
            ])
            ->assertOk()
            ->assertJsonPath('data.action', 'orientation_completed');

        $after = DB::table('nurselink_memberships')
            ->where('id', $membership->id)
            ->first();

        $this->assertSame('approved', $after->status);
        $this->assertSame('active', $after->standing);

        $onboarding = DB::table('nurselink_membership_onboarding')
            ->where('membership_id', $membership->id)
            ->first();

        $this->assertNotNull($onboarding->orientation_started_at);
        $this->assertNotNull($onboarding->orientation_completed_at);
    }

    public function test_date_only_profile_field_serializes_without_timezone_shift(): void
    {
        $user = User::factory()->create();
        $member = Member::query()->create([
            'user_id' => $user->id,
            'member_no' => 'NL-2026-DATE-TEST',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $profile = MemberProfile::query()->create([
            'member_id' => $member->id,
            'first_name' => 'Date',
            'last_name' => 'Test',
            'date_of_birth' => '1990-01-01',
        ]);

        $this->assertSame('1990-01-01', $profile->fresh()->toArray()['date_of_birth']);
    }

    public function test_core_activation_initializes_portfolio_records_idempotently(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'status' => 'active',
        ]);

        DB::table('nurselink_smart_registration_profiles')->insert([
            'user_id' => $user->id,
            'first_name' => 'Lifecycle',
            'last_name' => 'Test',
            'country' => 'Philippines',
            'professional_title' => 'Registered Nurse',
            'years_experience' => 5,
            'current_position' => 'Workflow Test Nurse',
            'current_employer' => 'Synthetic Test Hospital',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(CoreMembershipActivationService::class);
        $member = $service->sync($user->id, 'NL-2026-CORE-TEST');
        $service->sync($user->id, 'NL-2026-CORE-TEST');

        $this->assertDatabaseCount('portfolio_summaries', 1);
        $this->assertDatabaseHas('portfolio_employment', [
            'member_id' => $member->id,
            'position_title' => 'Workflow Test Nurse',
            'employer' => 'Synthetic Test Hospital',
            'is_current' => true,
        ]);
        $this->assertSame(
            1,
            DB::table('portfolio_employment')->where('member_id', $member->id)->count()
        );
    }
}
