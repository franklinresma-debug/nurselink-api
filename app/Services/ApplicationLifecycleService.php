<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationStatusEvent;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\PortfolioSummary;
use App\Models\PortfolioEmployment;
use App\Models\Role;
use App\Models\User;
use App\Services\Credentials\MemberDocumentImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ApplicationLifecycleService
{
    public function __construct(private readonly IdentifierService $ids, private readonly AuditLogger $audit, private readonly MemberDocumentImportService $documentImport) {}

    public function transition(Application $application, User $actor, string $to, ?string $note = null, array $metadata = []): Application
    {
        $allowed = [
            'draft' => ['in_progress'],
            'in_progress' => ['ready_to_submit'],
            'ready_to_submit' => ['submitted'],
            'submitted' => ['under_review'],
            'resubmitted' => ['under_review'],
            'under_review' => ['returned_for_information', 'approved', 'rejected'],
            'returned_for_information' => ['resubmitted'],
        ];

        $from = $application->status;
        if (!in_array($to, $allowed[$from] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "Invalid application transition: {$from} → {$to}."]);
        }

        $application->status = $to;
        if ($to === 'submitted') $application->submitted_at = now();
        if ($to === 'under_review' && !$application->review_started_at) $application->review_started_at = now();
        if ($to === 'returned_for_information') { $application->returned_at = now(); $application->return_reason = $note; }
        if ($to === 'resubmitted') $application->resubmitted_at = now();
        if ($to === 'approved') $application->approved_at = now();
        if ($to === 'rejected') { $application->rejected_at = now(); $application->rejection_reason = $note; }
        $application->lock_version++;
        $application->save();

        if (in_array($to, ['submitted', 'resubmitted'], true)) {
            $this->syncMembershipReviewQueue($application, $to === 'resubmitted');
        }

        ApplicationStatusEvent::query()->create([
            'application_id' => $application->id,
            'actor_user_id' => $actor->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'metadata' => $metadata,
        ]);

        $this->audit->write('application.status_changed', $actor, 'application', $application->id, [
            'application_no' => $application->application_no,
            'from' => $from,
            'to' => $to,
            'note' => $note,
        ], request());

        return $application->refresh();
    }

    private function syncMembershipReviewQueue(Application $application, bool $isResubmission): void
    {
        if (! Schema::hasTable('nurselink_memberships')) {
            return;
        }

        $existing = DB::table('nurselink_memberships')
            ->where('user_id', $application->user_id)
            ->first();

        // Never reopen a membership after an Administrator has made a final decision.
        if ($existing && in_array((string) $existing->status, ['approved', 'declined'], true)) {
            return;
        }

        $now = now();
        $values = [
            'status' => 'submitted',
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('nurselink_memberships', 'submitted_at')) {
            $values['submitted_at'] = $existing?->submitted_at ?: ($application->submitted_at ?: $now);
        }
        if ($isResubmission && Schema::hasColumn('nurselink_memberships', 'resubmitted_at')) {
            $values['resubmitted_at'] = $application->resubmitted_at ?: $now;
        }
        if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_at')) {
            $values['last_status_changed_at'] = $now;
        }
        if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_by')) {
            $values['last_status_changed_by'] = (string) $application->user_id;
        }

        if ($existing) {
            DB::table('nurselink_memberships')->where('id', $existing->id)->update($values);
            return;
        }

        DB::table('nurselink_memberships')->insert([
            'user_id' => $application->user_id,
            'created_at' => $now,
            ...$values,
        ]);
    }

    public function approve(Application $application, User $actor): Member
    {
        return DB::transaction(function () use ($application, $actor) {
            $application = Application::query()->lockForUpdate()->findOrFail($application->id);
            if ($application->status !== 'under_review') {
                throw ValidationException::withMessages(['status' => 'Only an application under review can be approved.']);
            }

            $this->transition($application, $actor, 'approved');

            $member = Member::query()->firstOrCreate(
                ['user_id' => $application->user_id],
                [
                    'member_no' => $this->ids->next('member', 'NL'),
                    'approved_from_application_id' => $application->id,
                    'status' => 'active',
                    'joined_at' => now(),
                ]
            );

            $p = $application->profile_data ?? [];
            MemberProfile::query()->updateOrCreate(['member_id' => $member->id], [
                'first_name' => $p['first_name'] ?? null,
                'middle_name' => $p['middle_name'] ?? null,
                'last_name' => $p['last_name'] ?? null,
                'suffix' => $p['suffix'] ?? null,
                'date_of_birth' => $p['date_of_birth'] ?? null,
                'nationality' => $p['nationality'] ?? null,
                'mobile_phone' => $p['mobile_phone'] ?? null,
                'city' => $p['city'] ?? null,
                'region' => $p['region'] ?? null,
                'country' => $p['country'] ?? null,
                'professional_title' => $p['professional_title'] ?? 'Registered Nurse',
                'current_position' => $p['current_position'] ?? null,
                'current_employer' => $p['current_employer'] ?? null,
                'years_experience' => $p['years_experience'] ?? null,
            ]);

            PortfolioSummary::query()->updateOrCreate(['member_id' => $member->id], [
                'professional_headline' => $p['current_position'] ?? $p['professional_title'] ?? 'Registered Nurse',
                'years_experience' => $p['years_experience'] ?? null,
                'current_country' => isset($p['country']) && is_string($p['country']) && strlen($p['country']) === 2 ? strtoupper($p['country']) : null,
                'completion_percent' => 0,
            ]);

            $this->documentImport->fromApprovedApplication($member, $application);

            if (!empty($p['current_position']) && !empty($p['current_employer'])) {
                PortfolioEmployment::query()->firstOrCreate([
                    'member_id' => $member->id,
                    'position_title' => $p['current_position'],
                    'employer' => $p['current_employer'],
                    'is_current' => true,
                ], ['status' => 'member_confirmed']);
            }

            $memberRole = Role::query()->where('code', 'member')->firstOrFail();
            $applicantRole = Role::query()->where('code', 'applicant')->first();
            $application->user->roles()->syncWithoutDetaching([$memberRole->id => ['assigned_at' => now()]]);
            if ($applicantRole) $application->user->roles()->detach($applicantRole->id);

            $this->audit->write('member.created', $actor, 'member', $member->id, [
                'member_no' => $member->member_no,
                'application_no' => $application->application_no,
            ], request());

            return $member->load('profile', 'user');
        }, 3);
    }
}
