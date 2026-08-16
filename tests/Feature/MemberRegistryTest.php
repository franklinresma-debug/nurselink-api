<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['email_verified_at'=>now(),'status'=>'active']);
        $r = Role::query()->where('code',$role)->firstOrFail();
        $user->roles()->attach($r->id,['assigned_at'=>now()]);
        return $user;
    }

    public function test_applicant_can_start_only_one_application(): void
    {
        $user=$this->userWithRole('applicant');
        $first=$this->actingAs($user)->postJson('/api/applications')->assertCreated()->json('data.application_no');
        $second=$this->actingAs($user)->postJson('/api/applications')->assertOk()->json('data.application_no');
        $this->assertSame($first,$second);
        $this->assertStringStartsWith('NLA-', $first);
    }

    public function test_approval_creates_member_and_changes_role(): void
    {
        $applicant=$this->userWithRole('applicant');
        $officer=$this->userWithRole('membership_officer');
        $application=Application::query()->create([
            'application_no'=>'NLA-2026-000001','user_id'=>$applicant->id,'status'=>'under_review','progress_percent'=>100,
            'profile_data'=>['first_name'=>'Maria','last_name'=>'Santos','professional_title'=>'Registered Nurse']
        ]);

        $this->actingAs($officer)->postJson("/api/admin/applications/{$application->id}/approve")->assertOk()->assertJsonPath('data.status','active');
        $this->assertDatabaseHas('members',['user_id'=>$applicant->id]);
        $this->assertTrue($applicant->fresh()->hasRole('member'));
        $this->assertFalse($applicant->fresh()->hasRole('applicant'));
    }

    public function test_returned_application_can_be_resubmitted(): void
    {
        $applicant=$this->userWithRole('applicant');
        $app=Application::query()->create(['application_no'=>'NLA-2026-000002','user_id'=>$applicant->id,'status'=>'returned_for_information','progress_percent'=>80,'profile_data'=>[]]);
        $this->actingAs($applicant)->postJson("/api/applications/{$app->id}/resubmit")->assertOk()->assertJsonPath('data.status','resubmitted');
    }
}
