<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CoreMembershipActivationService
{
    public function sync(string $userId, string $memberNumber): Member
    {
        $member = Member::query()->updateOrCreate(
            ['user_id' => $userId],
            ['member_no' => $memberNumber, 'status' => 'active', 'joined_at' => now()]
        );

        $profile = DB::table('nurselink_smart_registration_profiles')
            ->where('user_id', $userId)
            ->first();

        if ($profile) {
            MemberProfile::query()->updateOrCreate(['member_id' => $member->id], [
                'first_name' => $profile->first_name,
                'middle_name' => $profile->middle_name,
                'last_name' => $profile->last_name,
                'date_of_birth' => $profile->birth_date,
                'nationality' => $profile->nationality,
                'mobile_phone' => $profile->phone,
                'city' => $profile->city,
                'region' => $profile->province,
                'country' => $profile->country,
                'professional_title' => $profile->professional_title,
                'current_position' => $profile->current_position,
                'current_employer' => $profile->current_employer,
                'years_experience' => $profile->years_experience,
                'profile_meta' => ['source' => 'smart_registration'],
            ]);
        }

        $user = User::query()->findOrFail($userId);
        $memberRole = Role::query()->where('code', 'member')->firstOrFail();
        $applicantRole = Role::query()->where('code', 'applicant')->first();
        $user->roles()->syncWithoutDetaching([$memberRole->id => ['assigned_at' => now()]]);
        if ($applicantRole) $user->roles()->detach($applicantRole->id);

        return $member->refresh();
    }
}
