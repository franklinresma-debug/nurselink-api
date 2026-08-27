<?php
namespace Tests\Feature;
use App\Models\Member; use App\Models\Role; use App\Models\User; use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class ProfessionalPortfolioTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed(\Database\Seeders\RolePermissionSeeder::class);}
    private function memberUser():User{$u=User::factory()->create(['email_verified_at'=>now()]);$u->roles()->attach(Role::where('code','member')->first());$memberNo='NL-2026-'.str_pad((string)(Member::count()+1),6,'0',STR_PAD_LEFT);
Member::create(['user_id'=>$u->id,'member_no'=>$memberNo,'status'=>'active','joined_at'=>now()]);
$membershipId=\Illuminate\Support\Facades\DB::table('nurselink_memberships')->insertGetId([
'user_id'=>$u->id,'status'=>'approved','standing'=>'active','member_number'=>$memberNo,
'verification_code'=>'portfolio-test-'.$u->id,'approved_at'=>now(),'created_at'=>now(),'updated_at'=>now()
]);
$membership=\Illuminate\Support\Facades\DB::table('nurselink_memberships')->where('id',$membershipId)->first();
app(\App\Services\MembershipLifecycleService::class)->ensureOnboarding($membership);
\Illuminate\Support\Facades\DB::table('nurselink_membership_onboarding')->where('membership_id',$membershipId)->update([
'status'=>'completed','orientation_started_at'=>now(),'orientation_completed_at'=>now(),'completed_at'=>now(),'updated_at'=>now()
]);
return $u;}
    public function test_member_can_update_portfolio_summary():void{$u=$this->memberUser();$this->actingAs($u)->patchJson('/api/portfolio/me/summary',['professional_headline'=>'Critical Care Nurse','professional_summary'=>'Experienced Filipino nurse.','primary_specialty'=>'Critical Care','years_experience'=>12,'current_country'=>'PH'])->assertOk()->assertJsonPath('data.professional_headline','Critical Care Nurse');}
    public function test_member_can_add_education():void{$u=$this->memberUser();$this->actingAs($u)->postJson('/api/portfolio/education',['qualification'=>'BS Nursing','institution'=>'Sample University','country'=>'PH'])->assertCreated()->assertJsonPath('data.status','self_declared');}
    public function test_member_cannot_edit_another_members_education():void{$u=$this->memberUser();$other=$this->memberUser();$row=Member::where('user_id',$other->id)->first()->education()->create(['qualification'=>'BS Nursing','institution'=>'Other University']);$this->actingAs($u)->patchJson('/api/portfolio/education/'.$row->id,['qualification'=>'Changed','institution'=>'Other University'])->assertForbidden();}
    public function test_completion_score_increases_as_sections_are_completed():void{$u=$this->memberUser();$this->actingAs($u)->patchJson('/api/portfolio/me/summary',['professional_headline'=>'RN','professional_summary'=>'Professional summary'])->assertOk();$this->actingAs($u)->postJson('/api/portfolio/education',['qualification'=>'BS Nursing','institution'=>'University'])->assertCreated();$this->actingAs($u)->getJson('/api/portfolio/me')->assertOk()->assertJsonPath('data.portfolio_summary.completion_percent',30);}
}
