<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class EnterpriseEnrollmentController extends Controller
{
    private const INVITATIONS =
        'nurselink_enterprise_cohort_invitations';

    private const COHORTS =
        'nurselink_enterprise_cohorts';

    private const COHORT_MEMBERS =
        'nurselink_enterprise_cohort_members';

    private const ORGANIZATIONS =
        'nurselink_partner_organizations';

    public function memberInvitations(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $this->expireOutstandingInvitations(
            $userId
        );

        $rows = DB::table(
            self::INVITATIONS . ' as i'
        )
            ->join(
                self::COHORTS . ' as c',
                'c.id',
                '=',
                'i.cohort_id'
            )
            ->join(
                self::ORGANIZATIONS . ' as o',
                'o.id',
                '=',
                'c.partner_organization_id'
            )
            ->where(
                'i.user_id',
                $userId
            )
            ->orderByRaw(
                "CASE i.status
                    WHEN 'invited' THEN 1
                    WHEN 'accepted' THEN 2
                    WHEN 'declined' THEN 3
                    WHEN 'expired' THEN 4
                    ELSE 5 END"
            )
            ->orderByDesc(
                'i.updated_at'
            )
            ->select([
                'i.id',
                'i.status',
                'i.message',
                'i.expires_at',
                'i.responded_at',
                'i.created_at',
                'c.id as cohort_id',
                'c.name as cohort_name',
                'c.code as cohort_code',
                'c.description as cohort_description',
                'c.status as cohort_status',
                'c.starts_at',
                'c.ends_at',
                'o.id as organization_id',
                'o.name as organization_name',
                'o.organization_type',
                'o.country',
                'o.city',
            ])
            ->get();

        return response()->json([
            'data' => $rows,
            'governance' => [
                'invitation_is_employment_offer'
                    => false,
                'invitation_is_credential_verification'
                    => false,
                'invitation_is_regulatory_action'
                    => false,
                'acceptance_creates_nurselink_cohort_assignment'
                    => true,
                'message' =>
                    'Enterprise cohort invitations are NurseLink coordination invitations. Accepting an invitation creates a NurseLink cohort assignment only and does not create employment, licensure, credential verification, regulatory standing or official CPD status.',
            ],
        ]);
    }

    public function memberRespond(
        Request $request,
        int $invitationId
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $data = $request->validate([
            'action' => [
                'required',
                Rule::in([
                    'accept',
                    'decline',
                ]),
            ],
        ]);

        $invitation = DB::table(
            self::INVITATIONS
        )
            ->where(
                'id',
                $invitationId
            )
            ->where(
                'user_id',
                $userId
            )
            ->first();

        abort_unless(
            $invitation,
            404
        );

        if (
            $invitation->status
            !== 'invited'
        ) {
            abort(
                409,
                'This invitation is no longer awaiting a response.'
            );
        }

        if (
            $invitation->expires_at
            && CarbonImmutable::parse(
                $invitation->expires_at
            )->isPast()
        ) {
            DB::table(
                self::INVITATIONS
            )
                ->where(
                    'id',
                    $invitationId
                )
                ->update([
                    'status' =>
                        'expired',
                    'responded_at' =>
                        now(),
                    'updated_at' =>
                        now(),
                ]);

            abort(
                409,
                'This invitation has expired.'
            );
        }

        $cohort = DB::table(
            self::COHORTS . ' as c'
        )
            ->join(
                self::ORGANIZATIONS . ' as o',
                'o.id',
                '=',
                'c.partner_organization_id'
            )
            ->where(
                'c.id',
                $invitation->cohort_id
            )
            ->first([
                'c.*',
                'o.name as organization_name',
                'o.status as organization_status',
            ]);

        abort_unless(
            $cohort
            && $cohort->organization_status
                === 'verified',
            409,
            'This enterprise cohort is not currently available for enrollment.'
        );

        $before = $invitation;

        if (
            $data['action']
            === 'decline'
        ) {
            DB::table(
                self::INVITATIONS
            )
                ->where(
                    'id',
                    $invitationId
                )
                ->update([
                    'status' =>
                        'declined',
                    'responded_at' =>
                        now(),
                    'updated_at' =>
                        now(),
                ]);

            $after = DB::table(
                self::INVITATIONS
            )
                ->where(
                    'id',
                    $invitationId
                )
                ->first();

            $this->audit(
                $request,
                'enterprise.invitation_declined',
                'enterprise_cohort_invitation',
                (string) $invitationId,
                $before,
                $after
            );

            return response()->json([
                'message' =>
                    'Enterprise cohort invitation declined.',
                'data' => $after,
            ]);
        }

        DB::transaction(
            function () use (
                $invitation,
                $userId,
                $invitationId
            ): void {
                $assignment = DB::table(
                    self::COHORT_MEMBERS
                )
                    ->where(
                        'cohort_id',
                        $invitation->cohort_id
                    )
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->first();

                if (! $assignment) {
                    DB::table(
                        self::COHORT_MEMBERS
                    )->insert([
                        'cohort_id' =>
                            $invitation->cohort_id,
                        'user_id' =>
                            $userId,
                        'status' =>
                            'active',
                        'joined_at' =>
                            now(),
                        'completed_at' =>
                            null,
                        'inactive_at' =>
                            null,
                        'internal_note' =>
                            null,
                        'created_at' =>
                            now(),
                        'updated_at' =>
                            now(),
                    ]);
                } elseif (
                    $assignment->status
                    === 'inactive'
                ) {
                    DB::table(
                        self::COHORT_MEMBERS
                    )
                        ->where(
                            'id',
                            $assignment->id
                        )
                        ->update([
                            'status' =>
                                'active',
                            'joined_at' =>
                                $assignment
                                    ->joined_at
                                ?: now(),
                            'completed_at' =>
                                null,
                            'inactive_at' =>
                                null,
                            'updated_at' =>
                                now(),
                        ]);
                }

                DB::table(
                    self::INVITATIONS
                )
                    ->where(
                        'id',
                        $invitationId
                    )
                    ->update([
                        'status' =>
                            'accepted',
                        'responded_at' =>
                            now(),
                        'updated_at' =>
                            now(),
                    ]);
            }
        );

        $after = DB::table(
            self::INVITATIONS
        )
            ->where(
                'id',
                $invitationId
            )
            ->first();

        $this->audit(
            $request,
            'enterprise.invitation_accepted',
            'enterprise_cohort_invitation',
            (string) $invitationId,
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Enterprise cohort invitation accepted. Your NurseLink cohort assignment is now available.',
            'data' => $after,
        ]);
    }

    public function adminInvitations(
        Request $request,
        int $cohortId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $this->expireOutstandingInvitations();

        $cohort = $this->requireCohort(
            $cohortId
        );

        $rows = DB::table(
            self::INVITATIONS . ' as i'
        )
            ->join(
                'users as u',
                'u.id',
                '=',
                'i.user_id'
            )
            ->leftJoin(
                'nurselink_memberships as m',
                'm.user_id',
                '=',
                'i.user_id'
            )
            ->where(
                'i.cohort_id',
                $cohortId
            )
            ->orderByDesc(
                'i.created_at'
            )
            ->select([
                'i.id',
                'i.user_id',
                'i.status',
                'i.message',
                'i.expires_at',
                'i.responded_at',
                'i.created_at',
                'u.name',
                'u.email',
                'm.member_number',
                'm.status as membership_status',
                'm.standing',
            ])
            ->get();

        return response()->json([
            'data' => [
                'cohort' =>
                    $cohort,
                'invitations' =>
                    $rows,
            ],
            'privacy' => [
                'administrator_only_detail'
                    => true,
                'partner_access' =>
                    false,
                'home_address_included'
                    => false,
                'phone_included' =>
                    false,
                'documents_included' =>
                    false,
                'credential_numbers_included'
                    => false,
            ],
        ]);
    }

    public function adminInvite(
        Request $request,
        int $cohortId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $cohort = $this->requireCohort(
            $cohortId
        );

        $organization = DB::table(
            self::ORGANIZATIONS
        )
            ->where(
                'id',
                $cohort
                    ->partner_organization_id
            )
            ->first();

        abort_unless(
            $organization
            && $organization->status
                === 'verified',
            422,
            'Enterprise invitations require a verified partner organization.'
        );

        $data = $request->validate([
            'email' => [
                'nullable',
                'email',
                'max:190',
            ],
            'member_number' => [
                'nullable',
                'string',
                'max:80',
            ],
            'message' => [
                'nullable',
                'string',
                'max:3000',
            ],
            'expires_at' => [
                'nullable',
                'date',
                'after:now',
            ],
        ]);

        abort_if(
            empty(
                $data['email']
            )
            && empty(
                $data['member_number']
            ),
            422,
            'Provide member email or member number.'
        );

        $member = DB::table(
            'users as u'
        )
            ->join(
                'nurselink_memberships as m',
                'm.user_id',
                '=',
                'u.id'
            )
            ->when(
                ! empty(
                    $data['email']
                ),
                fn ($q) =>
                    $q->where(
                        'u.email',
                        $data['email']
                    )
            )
            ->when(
                empty(
                    $data['email']
                )
                && ! empty(
                    $data['member_number']
                ),
                fn ($q) =>
                    $q->where(
                        'm.member_number',
                        $data[
                            'member_number'
                        ]
                    )
            )
            ->where(
                'm.status',
                'approved'
            )
            ->where(
                'm.standing',
                'active'
            )
            ->first([
                'u.id',
                'u.name',
                'u.email',
                'm.member_number',
            ]);

        abort_unless(
            $member,
            422,
            'Only an Approved + Active NurseLink member can be invited to an enterprise cohort.'
        );

        $existingAssignment = DB::table(
            self::COHORT_MEMBERS
        )
            ->where(
                'cohort_id',
                $cohortId
            )
            ->where(
                'user_id',
                $member->id
            )
            ->whereIn(
                'status',
                [
                    'active',
                    'completed',
                ]
            )
            ->exists();

        abort_if(
            $existingAssignment,
            409,
            'This member is already assigned to the enterprise cohort.'
        );

        $existing = DB::table(
            self::INVITATIONS
        )
            ->where(
                'cohort_id',
                $cohortId
            )
            ->where(
                'user_id',
                $member->id
            )
            ->first();

        $values = [
            'status' =>
                'invited',
            'invited_by' =>
                (string)
                    $request->user()->getKey(),
            'message' =>
                $data['message']
                ?? null,
            'expires_at' =>
                $data['expires_at']
                ?? now()->addDays(14),
            'responded_at' =>
                null,
            'updated_at' =>
                now(),
        ];

        if ($existing) {
            DB::table(
                self::INVITATIONS
            )
                ->where(
                    'id',
                    $existing->id
                )
                ->update(
                    $values
                );

            $id =
                (int) $existing->id;
        } else {
            $id = DB::table(
                self::INVITATIONS
            )->insertGetId([
                'cohort_id' =>
                    $cohortId,
                'user_id' =>
                    $member->id,
                ...$values,
                'created_at' =>
                    now(),
            ]);
        }

        $after = DB::table(
            self::INVITATIONS
        )
            ->where(
                'id',
                $id
            )
            ->first();

        $this->notifyMember(
            (string) $member->id,
            $organization->name,
            $cohort->name
        );

        $this->audit(
            $request,
            'enterprise.invitation_sent',
            'enterprise_cohort_invitation',
            (string) $id,
            $existing,
            $after
        );

        return response()->json([
            'message' =>
                'Enterprise cohort invitation sent.',
            'data' => [
                'id' => $id,
                'name' =>
                    $member->name,
                'email' =>
                    $member->email,
                'member_number' =>
                    $member->member_number,
                'status' =>
                    'invited',
                'expires_at' =>
                    $after->expires_at,
            ],
        ], $existing ? 200 : 201);
    }

    public function adminCancelInvitation(
        Request $request,
        int $invitationId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $before = DB::table(
            self::INVITATIONS
        )
            ->where(
                'id',
                $invitationId
            )
            ->first();

        abort_unless(
            $before,
            404
        );

        abort_unless(
            $before->status
            === 'invited',
            409,
            'Only a pending invitation can be cancelled.'
        );

        DB::table(
            self::INVITATIONS
        )
            ->where(
                'id',
                $invitationId
            )
            ->update([
                'status' =>
                    'cancelled',
                'responded_at' =>
                    now(),
                'updated_at' =>
                    now(),
            ]);

        $after = DB::table(
            self::INVITATIONS
        )
            ->where(
                'id',
                $invitationId
            )
            ->first();

        $this->audit(
            $request,
            'enterprise.invitation_cancelled',
            'enterprise_cohort_invitation',
            (string) $invitationId,
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Enterprise cohort invitation cancelled.',
            'data' => $after,
        ]);
    }

    public function adminOrganizationReport(
        Request $request
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $this->expireOutstandingInvitations();

        $rows = DB::table(
            self::ORGANIZATIONS
        )
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'organization_type',
                'country',
                'city',
                'status',
            ])
            ->map(
                fn ($org): array =>
                    $this->organizationReportRow(
                        $org
                    )
            )
            ->values();

        return response()->json([
            'data' => [
                'organizations' =>
                    $rows,
                'generated_at' =>
                    now()->toIso8601String(),
            ],
            'privacy' => [
                'aggregate_reporting'
                    => true,
                'member_private_notes_included'
                    => false,
                'documents_included' =>
                    false,
                'credential_numbers_included'
                    => false,
            ],
        ]);
    }

    public function partnerOrganizationReport(
        Request $request
    ): JsonResponse {
        $scope = $this->authorizePartner(
            $request
        );

        $this->expireOutstandingInvitations();

        $row =
            $this->organizationReportRow(
                $scope['organization'],
                true
            );

        return response()->json([
            'data' => [
                'organization' =>
                    $row,
            ],
            'privacy' => [
                'aggregate_only' =>
                    true,
                'member_identity_included'
                    => false,
                'member_contact_details_included'
                    => false,
                'member_notes_included'
                    => false,
                'documents_included' =>
                    false,
                'credential_numbers_included'
                    => false,
                'small_cohort_metrics_suppressed'
                    => true,
                'minimum_aggregate_cohort_size'
                    => 3,
            ],
        ]);
    }

    private function organizationReportRow(
        object $organization,
        bool $partnerView = false
    ): array {
        $cohortIds = DB::table(
            self::COHORTS
        )
            ->where(
                'partner_organization_id',
                $organization->id
            )
            ->pluck('id')
            ->all();

        $cohortCount =
            count($cohortIds);

        $assignments = $cohortIds
            ? DB::table(
                self::COHORT_MEMBERS
            )->whereIn(
                'cohort_id',
                $cohortIds
            )
            : null;

        $invitations = $cohortIds
            ? DB::table(
                self::INVITATIONS
            )->whereIn(
                'cohort_id',
                $cohortIds
            )
            : null;

        $population =
            $assignments
                ? (clone $assignments)
                    ->whereIn(
                        'status',
                        [
                            'active',
                            'completed',
                        ]
                    )
                    ->distinct()
                    ->count(
                        'user_id'
                    )
                : 0;

        $threshold = 3;
        $suppressed =
            $partnerView
            && $population
                < $threshold;

        $report = [
            'organization_id' =>
                (int) $organization->id,
            'organization_name' =>
                $organization->name,
            'organization_type' =>
                $organization
                    ->organization_type,
            'country' =>
                $organization->country,
            'city' =>
                $organization->city,
            'status' =>
                $organization->status,
            'cohorts_total' =>
                $cohortCount,
            'cohorts_active' =>
                $cohortIds
                    ? DB::table(
                        self::COHORTS
                    )
                        ->whereIn(
                            'id',
                            $cohortIds
                        )
                        ->where(
                            'status',
                            'active'
                        )
                        ->count()
                    : 0,
            'assignments_total' =>
                $assignments
                    ? (clone $assignments)
                        ->count()
                    : 0,
            'assignments_active' =>
                $assignments
                    ? (clone $assignments)
                        ->where(
                            'status',
                            'active'
                        )
                        ->count()
                    : 0,
            'assignments_completed' =>
                $assignments
                    ? (clone $assignments)
                        ->where(
                            'status',
                            'completed'
                        )
                        ->count()
                    : 0,
            'invitations_total' =>
                $invitations
                    ? (clone $invitations)
                        ->count()
                    : 0,
            'invitations_pending' =>
                $invitations
                    ? (clone $invitations)
                        ->where(
                            'status',
                            'invited'
                        )
                        ->count()
                    : 0,
            'invitations_accepted' =>
                $invitations
                    ? (clone $invitations)
                        ->where(
                            'status',
                            'accepted'
                        )
                        ->count()
                    : 0,
            'invitations_declined' =>
                $invitations
                    ? (clone $invitations)
                        ->where(
                            'status',
                            'declined'
                        )
                        ->count()
                    : 0,
            'invitations_expired' =>
                $invitations
                    ? (clone $invitations)
                        ->where(
                            'status',
                            'expired'
                        )
                        ->count()
                    : 0,
            'invitations_cancelled' =>
                $invitations
                    ? (clone $invitations)
                        ->where(
                            'status',
                            'cancelled'
                        )
                        ->count()
                    : 0,
            'privacy_threshold' =>
                $threshold,
            'metrics_suppressed' =>
                $suppressed,
        ];

        if ($suppressed) {
            $report[
                'assignments_active'
            ] = null;
            $report[
                'assignments_completed'
            ] = null;
            $report[
                'invitations_pending'
            ] = null;
            $report[
                'invitations_accepted'
            ] = null;
            $report[
                'invitations_declined'
            ] = null;
            $report[
                'invitations_expired'
            ] = null;
            $report[
                'invitations_cancelled'
            ] = null;
        }

        return $report;
    }

    private function expireOutstandingInvitations(
        ?string $userId = null
    ): void {
        $query = DB::table(
            self::INVITATIONS
        )
            ->where(
                'status',
                'invited'
            )
            ->whereNotNull(
                'expires_at'
            )
            ->where(
                'expires_at',
                '<',
                now()
            );

        if (
            $userId !== null
        ) {
            $query->where(
                'user_id',
                $userId
            );
        }

        $query->update([
            'status' =>
                'expired',
            'responded_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);
    }

    private function notifyMember(
        string $userId,
        string $organizationName,
        string $cohortName
    ): void {
        if (
            ! Schema::hasTable(
                'nurselink_notifications'
            )
        ) {
            return;
        }

        DB::table(
            'nurselink_notifications'
        )->insert([
            'user_id' =>
                $userId,
            'type' =>
                'enterprise.cohort.invitation',
            'severity' =>
                'info',
            'title' =>
                'Enterprise cohort invitation',
            'message' =>
                $organizationName
                . ' has a NurseLink enterprise cohort invitation for '
                . $cohortName
                . '. Review the invitation before accepting or declining.',
            'action_url' =>
                '/nurselink-enterprise-invitations.html',
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);
    }

    private function requireCohort(
        int $cohortId
    ): object {
        $cohort = DB::table(
            self::COHORTS
        )
            ->where(
                'id',
                $cohortId
            )
            ->first();

        abort_unless(
            $cohort,
            404
        );

        return $cohort;
    }

    private function authorizePartner(
        Request $request
    ): array {
        $user = $request->user();
        abort_unless(
            $user,
            401
        );

        $access = DB::table(
            'nurselink_partner_access'
        )
            ->where(
                'user_id',
                $user->getKey()
            )
            ->where(
                'active',
                true
            )
            ->first();

        abort_unless(
            $access,
            403,
            'NurseLink partner access is required.'
        );

        $organization = DB::table(
            self::ORGANIZATIONS
        )
            ->where(
                'id',
                $access
                    ->partner_organization_id
            )
            ->where(
                'status',
                'verified'
            )
            ->first();

        abort_unless(
            $organization,
            403,
            'Verified partner organization access is required.'
        );

        $role = strtolower(
            (string) $access->role
        );

        abort_unless(
            in_array(
                $role,
                [
                    'viewer',
                    'recruiter',
                    'manager',
                ],
                true
            ),
            403
        );

        return [
            'role' =>
                $role,
            'organization' =>
                $organization,
        ];
    }

    private function requireAdministratorSession(
        Request $request
    ): void {
        $user = $request->user();
        abort_unless(
            $user,
            401
        );

        $userId =
            (string) $user->getKey();

        $elevatedUserId =
            (string)
                $request->session()->get(
                    'nurselink_admin_elevated_user_id',
                    ''
                );

        $expiresAt =
            (int)
                $request->session()->get(
                    'nurselink_admin_expires_at',
                    0
                );

        abort_unless(
            $elevatedUserId !== ''
            && hash_equals(
                $elevatedUserId,
                $userId
            )
            && $expiresAt
                >= time(),
            403,
            'A separate NurseLink Administrator Portal sign-in is required.'
        );

        $role =
            Schema::hasTable(
                'nurselink_reviewer_access'
            )
                ? strtolower(
                    (string) (
                        DB::table(
                            'nurselink_reviewer_access'
                        )
                            ->where(
                                'user_id',
                                $userId
                            )
                            ->where(
                                'active',
                                true
                            )
                            ->value(
                                'role'
                            )
                        ?? ''
                    )
                )
                : '';

        $super =
            Schema::hasTable(
                'nurselink_super_admin_access'
            )
            && DB::table(
                'nurselink_super_admin_access'
            )
                ->where(
                    'user_id',
                    $userId
                )
                ->where(
                    'active',
                    true
                )
                ->exists();

        abort_unless(
            $super
            || in_array(
                $role,
                [
                    'admin',
                    'super_admin',
                ],
                true
            )
            || (bool) (
                $user->is_admin
                ?? false
            )
            || (bool) (
                $user
                    ->is_super_admin
                ?? false
            ),
            403,
            'Administrator access is required for enterprise enrollment management.'
        );
    }

    private function audit(
        Request $request,
        string $action,
        string $targetType,
        string $targetId,
        $before,
        $after
    ): void {
        if (
            ! Schema::hasTable(
                'nurselink_review_audit'
            )
        ) {
            return;
        }

        DB::table(
            'nurselink_review_audit'
        )->insert([
            'reviewer_user_id' =>
                (string)
                    $request->user()->getKey(),
            'action' =>
                $action,
            'target_type' =>
                $targetType,
            'target_id' =>
                $targetId,
            'before_state' =>
                $before !== null
                    ? json_encode(
                        $before,
                        JSON_UNESCAPED_UNICODE
                    )
                    : null,
            'after_state' =>
                $after !== null
                    ? json_encode(
                        $after,
                        JSON_UNESCAPED_UNICODE
                    )
                    : null,
            'created_at' =>
                now(),
        ]);
    }
}
