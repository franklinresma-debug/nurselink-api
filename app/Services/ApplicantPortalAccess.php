<?php
namespace App\Services;

use App\Models\Member;
use App\Models\MemberProfile;
use Illuminate\Support\Facades\DB;

class ApplicantPortalAccess
{
    public const PERMISSIONS=['privacy.manage.own','message.view.own','notification_preferences.manage.own','event.view','event.register.own','program.view','policy.view','profile.manage.own','directory.view','credential.manage.own','document.manage.own','document.extract.own','professional_development.manage.own','qualification.view','qualification.assessment.manage.own','application.manage.own'];

    public function enabled():bool{return (bool)config('nurselink.applicant_access_without_approval',false);}
    public function eligible($user):bool{return $this->enabled() && $user && $user->status==='active' && ($user->hasRole('applicant') || $user->hasRole('member'));}
    public function ownRecord($user):?Member
    {
        if(!$this->eligible($user))return null;
        return DB::transaction(function()use($user){
            // Serialize first-time provisioning for this applicant; never assign a staff role.
            DB::table('users')->where('id',$user->getKey())->lockForUpdate()->first();
            $record=Member::firstOrCreate(['user_id'=>$user->getKey()],['member_no'=>'NLA-'.substr(strtoupper(str_replace('-','',(string)$user->getKey())),0,28),'status'=>'active','joined_at'=>now()]);
            MemberProfile::firstOrCreate(['member_id'=>$record->id],['first_name'=>$user->first_name ?? null,'last_name'=>$user->last_name ?? null]);
            return $record;
        });
    }
}
