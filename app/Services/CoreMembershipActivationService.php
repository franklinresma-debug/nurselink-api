<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\PortfolioEmployment;
use App\Models\PortfolioSummary;
use App\Models\Application;
use App\Models\ApplicationStatusEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\Credentials\MemberDocumentImportService;
use Illuminate\Support\Facades\DB;

class CoreMembershipActivationService
{
    public function __construct(
        private readonly MemberDocumentImportService $documentImport
    ) {}

    public function sync(string $userId, string $memberNumber): Member
    {
        $application = Application::query()
            ->where('user_id', $userId)
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->first();

        $this->syncApprovedApplication($application);

        $member = Member::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'member_no' => $memberNumber,
                'status' => 'active',
                'joined_at' => now(),
                'approved_from_application_id' => $application?->id,
            ]
        );

        $profile = DB::table('nurselink_smart_registration_profiles')
            ->where('user_id', $userId)
            ->first();

        $applicationProfile = is_array($application?->profile_data)
            ? $application->profile_data
            : [];

        $memberProfile = null;
        if ($profile || $applicationProfile !== []) {
            $memberProfile = MemberProfile::query()->updateOrCreate(['member_id' => $member->id], [
                'first_name' => $applicationProfile['first_name'] ?? $profile?->first_name,
                'middle_name' => $applicationProfile['middle_name'] ?? $profile?->middle_name,
                'last_name' => $applicationProfile['last_name'] ?? $profile?->last_name,
                'date_of_birth' => $applicationProfile['date_of_birth'] ?? $profile?->birth_date,
                'nationality' => $applicationProfile['nationality'] ?? $profile?->nationality,
                'mobile_phone' => $applicationProfile['mobile_phone'] ?? $profile?->phone,
                'city' => $applicationProfile['city'] ?? $profile?->city,
                'region' => $applicationProfile['region'] ?? $profile?->province,
                'country' => $applicationProfile['country'] ?? $profile?->country,
                'professional_title' => $applicationProfile['professional_title'] ?? $profile?->professional_title,
                'current_position' => $applicationProfile['current_position'] ?? $profile?->current_position,
                'current_employer' => $applicationProfile['current_employer'] ?? $profile?->current_employer,
                'years_experience' => $applicationProfile['years_experience'] ?? $profile?->years_experience,
                'profile_meta' => [
                    'source' => 'smart_registration',
                    'application_id' => $application?->id,
                ],
            ]);
        }

        if ($memberProfile) {
            PortfolioSummary::query()->updateOrCreate(['member_id' => $member->id], [
                'professional_headline' => $memberProfile->current_position
                    ?: $memberProfile->professional_title
                    ?: 'Registered Nurse',
                'years_experience' => $memberProfile->years_experience,
                'current_country' => is_string($memberProfile->country)
                    && strlen($memberProfile->country) === 2
                        ? strtoupper($memberProfile->country)
                        : null,
                'completion_percent' => 0,
            ]);

            if ($memberProfile->current_position && $memberProfile->current_employer) {
                PortfolioEmployment::query()->firstOrCreate([
                    'member_id' => $member->id,
                    'position_title' => $memberProfile->current_position,
                    'employer' => $memberProfile->current_employer,
                    'is_current' => true,
                ], ['status' => 'member_confirmed']);
            }
        }

        if ($application) {
            $this->documentImport->fromApprovedApplication($member, $application);
        }

        $user = User::query()->findOrFail($userId);
        $memberRole = Role::query()->where('code', 'member')->firstOrFail();
        $applicantRole = Role::query()->where('code', 'applicant')->first();
        $user->roles()->syncWithoutDetaching([$memberRole->id => ['assigned_at' => now()]]);
        if ($applicantRole) $user->roles()->detach($applicantRole->id);

        return $member->refresh();
    }

    private function syncApprovedApplication(?Application $application): void
    {
        if (! $application || in_array($application->status, ['approved', 'rejected'], true)) {
            return;
        }

        $fromStatus = (string) $application->status;
        $approvedAt = now();

        $application->forceFill([
            'status' => 'approved',
            'review_started_at' => $application->review_started_at ?: $approvedAt,
            'approved_at' => $application->approved_at ?: $approvedAt,
            'lock_version' => (int) $application->lock_version + 1,
        ])->save();

        ApplicationStatusEvent::query()->create([
            'application_id' => $application->id,
            'actor_user_id' => request()->user()?->getKey(),
            'from_status' => $fromStatus,
            'to_status' => 'approved',
            'note' => null,
            'metadata' => [
                'source' => 'governed_membership_approval',
                'synchronized' => true,
            ],
        ]);
    }
}
