<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdministrationOperationsCenterController extends Controller
{
    private const RELEASE = '5.5.8';

    public function summary(
        Request $request
    ): JsonResponse {
        $access =
            $this->requireElevatedSession(
                $request
            );

        $membershipPending =
            $this->countWhereIn(
                'nurselink_memberships',
                'status',
                [
                    'submitted',
                    'under_review',
                    'needs_information',
                    'ready_for_approval',
                ]
            );

        $membersApproved =
            $this->countWhere(
                'nurselink_memberships',
                'status',
                'approved'
            );

        $credentialsPending =
            $this->countWhereIn(
                'nurselink_credentials_registry',
                'verification_status',
                [
                    'pending',
                    'submitted',
                    'under_review',
                ]
            );

        $organizationsPending =
            $this->countWhere(
                'nurselink_partner_organizations',
                'status',
                'pending'
            );

        $jobsActive =
            $this->countWhere(
                'nurselink_job_opportunities',
                'status',
                'active'
            );

        $jobApplications =
            Schema::hasTable(
                'nurselink_job_applications'
            )
                ? DB::table(
                    'nurselink_job_applications'
                )->count()
                : 0;

        $eventsUpcoming =
            Schema::hasTable(
                'nurselink_events'
            )
                ? DB::table(
                    'nurselink_events'
                )
                    ->where(
                        'starts_at',
                        '>=',
                        now()
                    )
                    ->count()
                : 0;

        $supportOpen =
            $this->countWhereIn(
                'nurselink_support_cases',
                'status',
                [
                    'open',
                    'in_progress',
                    'waiting_member',
                    'waiting_internal',
                ]
            );

        $unreadNotifications =
            Schema::hasTable(
                'nurselink_notifications'
            )
                ? DB::table(
                    'nurselink_notifications'
                )
                    ->whereNull(
                        'read_at'
                    )
                    ->count()
                : 0;

        $termsVersion = (string) config('registration.terms_version');
        $privacyVersion = (string) config('registration.privacy_version');
        $activeUsers = DB::table('users')->where('status', 'active');
        $policyConsentCurrent = (clone $activeUsers)
            ->whereNotNull('terms_accepted_at')
            ->whereNotNull('privacy_accepted_at')
            ->where('terms_version', $termsVersion)
            ->where('privacy_version', $privacyVersion)
            ->count();
        $policyConsentPendingQuery = (clone $activeUsers)->where(function ($query) use ($termsVersion, $privacyVersion): void {
            $query->whereNull('terms_accepted_at')
                ->orWhereNull('privacy_accepted_at')
                ->orWhereNull('terms_version')
                ->orWhereNull('privacy_version')
                ->orWhere('terms_version', '!=', $termsVersion)
                ->orWhere('privacy_version', '!=', $privacyVersion);
        });
        $policyConsentPending = (clone $policyConsentPendingQuery)->count();
        $policyConsentPendingAccounts = (clone $policyConsentPendingQuery)
            ->orderBy('created_at')
            ->limit(50)
            ->get(['id', 'name', 'email', 'terms_version', 'privacy_version', 'created_at'])
            ->map(fn ($user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'terms_version' => $user->terms_version,
                'privacy_version' => $user->privacy_version,
                'created_at' => $user->created_at,
            ])->values();

        return response()->json([
            'data' => [
                'release' =>
                    self::RELEASE,
                'metrics' => [
                    'pending_membership_applications' =>
                        $membershipPending,
                    'approved_members' =>
                        $membersApproved,
                    'pending_verifications' =>
                        $credentialsPending,
                    'pending_organizations' =>
                        $organizationsPending,
                    'active_opportunities' =>
                        $jobsActive,
                    'job_applications' =>
                        $jobApplications,
                    'upcoming_events' =>
                        $eventsUpcoming,
                    'open_support_cases' =>
                        $supportOpen,
                    'unread_member_notifications' =>
                        $unreadNotifications,
                    'policy_consent_current' =>
                        $policyConsentCurrent,
                    'policy_consent_pending' =>
                        $policyConsentPending,
                ],
                'policy_consent' => [
                    'terms_version' => $termsVersion,
                    'privacy_version' => $privacyVersion,
                    'active_accounts' => (clone $activeUsers)->count(),
                    'current' => $policyConsentCurrent,
                    'pending' => $policyConsentPending,
                    'pending_accounts' => $policyConsentPendingAccounts,
                ],
                'capabilities' => [
                    'role' =>
                        $access['role'],
                    'can_review' =>
                        $access['is_reviewer'],
                    'can_administer' =>
                        $access['is_admin'],
                    'can_manage_access' =>
                        $access['is_super_admin'],
                    'can_send_communications' =>
                        $access['is_admin'],
                    'can_manage_support_cases' =>
                        $access['is_admin'],
                ],
                'architecture' => [
                    'administrator_entry_point' =>
                        '/nurselink-admin-login.html',
                    'administrator_portal' =>
                        '/nurselink-admin-dashboard.html',
                    'raw_database_administration' =>
                        false,
                    'workflow_api_required' =>
                        true,
                ],
            ],
        ]);
    }

    public function supportCases(
        Request $request
    ): JsonResponse {
        $access =
            $this->requireElevatedSession(
                $request
            );

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required for Support Cases.'
        );

        $data = $request->validate([
            'status' => [
                'nullable',
                Rule::in([
                    'open',
                    'in_progress',
                    'waiting_member',
                    'waiting_internal',
                    'resolved',
                    'closed',
                ]),
            ],
            'priority' => [
                'nullable',
                Rule::in([
                    'low',
                    'normal',
                    'high',
                    'urgent',
                ]),
            ],
            'search' => [
                'nullable',
                'string',
                'max:190',
            ],
            'assignment' => [
                'nullable',
                Rule::in([
                    'all',
                    'mine',
                    'assigned',
                    'unassigned',
                ]),
            ],
        ]);

        $query = DB::table(
            'nurselink_support_cases'
        );

        if (
            ! empty(
                $data['status']
            )
        ) {
            $query->where(
                'status',
                $data['status']
            );
        }

        if (
            ! empty(
                $data['priority']
            )
        ) {
            $query->where(
                'priority',
                $data['priority']
            );
        }

        $assignment =
            $data['assignment']
            ?? 'all';

        if (
            $assignment === 'mine'
        ) {
            $query->where(
                'assigned_admin_user_id',
                (string)
                    $request
                        ->user()
                        ->getKey()
            );
        } elseif (
            $assignment === 'assigned'
        ) {
            $query->whereNotNull(
                'assigned_admin_user_id'
            );
        } elseif (
            $assignment === 'unassigned'
        ) {
            $query->whereNull(
                'assigned_admin_user_id'
            );
        }

        $rows = $query
            ->orderByRaw(
                "CASE priority
                    WHEN 'urgent' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'normal' THEN 3
                    WHEN 'low' THEN 4
                    ELSE 5 END"
            )
            ->orderByRaw(
                "CASE status
                    WHEN 'open' THEN 1
                    WHEN 'in_progress' THEN 2
                    WHEN 'waiting_member' THEN 3
                    WHEN 'waiting_internal' THEN 4
                    WHEN 'resolved' THEN 5
                    WHEN 'closed' THEN 6
                    ELSE 7 END"
            )
            ->orderByDesc(
                'last_activity_at'
            )
            ->orderByDesc(
                'id'
            )
            ->limit(750)
            ->get();

        $userIds = $rows
            ->pluck(
                'member_user_id'
            )
            ->merge(
                $rows->pluck(
                    'assigned_admin_user_id'
                )
            )
            ->filter()
            ->map(
                fn ($value): string =>
                    (string) $value
            )
            ->all();

        $users =
            $this->userMap(
                $userIds
            );

        $organizationIds =
            $rows
                ->pluck(
                    'organization_id'
                )
                ->filter()
                ->unique()
                ->values()
                ->all();

        $organizations =
            Schema::hasTable(
                'nurselink_partner_organizations'
            )
                ? DB::table(
                    'nurselink_partner_organizations'
                )
                    ->whereIn(
                        'id',
                        $organizationIds
                    )
                    ->get([
                        'id',
                        'name',
                        'status',
                    ])
                    ->keyBy(
                        fn ($row): string =>
                            (string) $row->id
                    )
                : collect();

        $search = strtolower(
            trim(
                (string) (
                    $data['search']
                    ?? ''
                )
            )
        );

        $presented = $rows
            ->map(
                function (
                    $row
                ) use (
                    $users,
                    $organizations
                ): array {
                    $member =
                        ! empty(
                            $row->member_user_id
                        )
                            ? (
                                $users[
                                    (string)
                                        $row->member_user_id
                                ] ?? null
                            )
                            : null;

                    $assigned =
                        ! empty(
                            $row
                                ->assigned_admin_user_id
                        )
                            ? (
                                $users[
                                    (string)
                                        $row
                                            ->assigned_admin_user_id
                                ] ?? null
                            )
                            : null;

                    $organization =
                        ! empty(
                            $row->organization_id
                        )
                            ? (
                                $organizations->get(
                                    (string)
                                        $row->organization_id
                                )
                            )
                            : null;

                    return [
                        'id' =>
                            (int) $row->id,
                        'case_number' =>
                            $row->case_number,
                        'source' =>
                            $row->source,
                        'category' =>
                            $row->category,
                        'priority' =>
                            $row->priority,
                        'status' =>
                            $row->status,
                        'subject' =>
                            $row->subject,
                        'description' =>
                            $row->description,
                        'member' =>
                            $member,
                        'organization' =>
                            $organization
                                ? [
                                    'id' =>
                                        (int)
                                            $organization->id,
                                    'name' =>
                                        $organization->name,
                                    'status' =>
                                        $organization->status,
                                ]
                                : null,
                        'assigned_admin' =>
                            $assigned,
                        'assigned_admin_user_id' =>
                            $row
                                ->assigned_admin_user_id,
                        'internal_note' =>
                            $row->internal_note,
                        'resolution_summary' =>
                            $row
                                ->resolution_summary,
                        'last_activity_at' =>
                            $row->last_activity_at,
                        'resolved_at' =>
                            $row->resolved_at,
                        'closed_at' =>
                            $row->closed_at,
                        'created_at' =>
                            $row->created_at,
                        'updated_at' =>
                            $row->updated_at,
                    ];
                }
            );

        if (
            $search !== ''
        ) {
            $presented =
                $presented->filter(
                    function (
                        array $row
                    ) use (
                        $search
                    ): bool {
                        $haystack = strtolower(
                            (
                                $row[
                                    'case_number'
                                ] ?? ''
                            )
                            . ' '
                            . (
                                $row[
                                    'subject'
                                ] ?? ''
                            )
                            . ' '
                            . (
                                $row[
                                    'member'
                                ]['name']
                                ?? ''
                            )
                            . ' '
                            . (
                                $row[
                                    'member'
                                ]['email']
                                ?? ''
                            )
                            . ' '
                            . (
                                $row[
                                    'organization'
                                ]['name']
                                ?? ''
                            )
                        );

                        return str_contains(
                            $haystack,
                            $search
                        );
                    }
                );
        }

        return response()->json([
            'data' =>
                $presented->values(),
        ]);
    }

    public function storeSupportCase(
        Request $request
    ): JsonResponse {
        $access =
            $this->requireElevatedSession(
                $request
            );

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required to create Support Cases.'
        );

        $data =
            $this->validateSupportCase(
                $request,
                false
            );

        $memberUserId =
            $this->resolveMemberUserId(
                $data[
                    'member_identifier'
                ] ?? null
            );

        $organizationId =
            $data[
                'organization_id'
            ] ?? null;

        if (
            $organizationId
            && Schema::hasTable(
                'nurselink_partner_organizations'
            )
        ) {
            abort_unless(
                DB::table(
                    'nurselink_partner_organizations'
                )
                    ->where(
                        'id',
                        $organizationId
                    )
                    ->exists(),
                422,
                'Organization does not exist.'
            );
        }

        $assignedAdminId = trim(
            (string) (
                $data[
                    'assigned_admin_user_id'
                ] ?? ''
            )
        );

        if (
            $assignedAdminId !== ''
        ) {
            abort_unless(
                $this->isActiveAdministrator(
                    $assignedAdminId
                ),
                422,
                'Assigned case owner must be an active Administrator or Super Administrator.'
            );
        }

        $caseNumber =
            'NLCASE-'
            . now()->format(
                'Ymd'
            )
            . '-'
            . Str::upper(
                Str::random(
                    8
                )
            );

        $id = DB::table(
            'nurselink_support_cases'
        )->insertGetId([
            'case_number' =>
                $caseNumber,
            'member_user_id' =>
                $memberUserId,
            'organization_id' =>
                $organizationId,
            'source' =>
                $data['source'],
            'category' =>
                $data['category'],
            'priority' =>
                $data['priority'],
            'status' =>
                'open',
            'subject' =>
                $data['subject'],
            'description' =>
                $data['description']
                ?? null,
            'assigned_admin_user_id' =>
                $assignedAdminId !== ''
                    ? $assignedAdminId
                    : null,
            'created_by_user_id' =>
                (string)
                    $request
                        ->user()
                        ->getKey(),
            'internal_note' =>
                $data['internal_note']
                ?? null,
            'resolution_summary' =>
                null,
            'last_activity_at' =>
                now(),
            'resolved_at' =>
                null,
            'closed_at' =>
                null,
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);

        $after = DB::table(
            'nurselink_support_cases'
        )
            ->where(
                'id',
                $id
            )
            ->first();

        $this->audit(
            $request,
            'support.case_created',
            'support_case',
            (string) $id,
            null,
            $after
        );

        if (
            $memberUserId
        ) {
            $this->notifyMember(
                $memberUserId,
                'support.case_created',
                'NurseLink support case opened',
                'A NurseLink support case was opened for you: '
                . $caseNumber
                . '.',
                '/notifications'
            );
        }

        return response()->json([
            'message' =>
                'Support case created.',
            'data' =>
                $after,
        ], 201);
    }

    public function updateSupportCase(
        Request $request,
        int $caseId
    ): JsonResponse {
        $access =
            $this->requireElevatedSession(
                $request
            );

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required to update Support Cases.'
        );

        $before = DB::table(
            'nurselink_support_cases'
        )
            ->where(
                'id',
                $caseId
            )
            ->first();

        abort_unless(
            $before,
            404
        );

        $data =
            $this->validateSupportCase(
                $request,
                true
            );

        $assignedAdminId = trim(
            (string) (
                $data[
                    'assigned_admin_user_id'
                ] ?? ''
            )
        );

        if (
            $assignedAdminId !== ''
        ) {
            abort_unless(
                $this->isActiveAdministrator(
                    $assignedAdminId
                ),
                422,
                'Assigned case owner must be an active Administrator or Super Administrator.'
            );
        }

        $status =
            $data['status'];

        $resolvedAt =
            in_array(
                $status,
                [
                    'resolved',
                    'closed',
                ],
                true
            )
                ? (
                    $before->resolved_at
                    ?: now()
                )
                : null;

        $closedAt =
            $status === 'closed'
                ? (
                    $before->closed_at
                    ?: now()
                )
                : null;

        DB::table(
            'nurselink_support_cases'
        )
            ->where(
                'id',
                $caseId
            )
            ->update([
                'category' =>
                    $data['category'],
                'priority' =>
                    $data['priority'],
                'status' =>
                    $status,
                'subject' =>
                    $data['subject'],
                'description' =>
                    $data['description']
                    ?? null,
                'assigned_admin_user_id' =>
                    $assignedAdminId !== ''
                        ? $assignedAdminId
                        : null,
                'internal_note' =>
                    $data['internal_note']
                    ?? null,
                'resolution_summary' =>
                    $data[
                        'resolution_summary'
                    ] ?? null,
                'last_activity_at' =>
                    now(),
                'resolved_at' =>
                    $resolvedAt,
                'closed_at' =>
                    $closedAt,
                'updated_at' =>
                    now(),
            ]);

        $after = DB::table(
            'nurselink_support_cases'
        )
            ->where(
                'id',
                $caseId
            )
            ->first();

        $this->audit(
            $request,
            'support.case_updated',
            'support_case',
            (string) $caseId,
            $before,
            $after
        );

        if (
            ! empty(
                $after->member_user_id
            )
            && (
                $before->status
                !== $after->status
            )
        ) {
            $this->notifyMember(
                (string)
                    $after
                        ->member_user_id,
                'support.case_status_changed',
                'NurseLink support case updated',
                'Support case '
                . $after->case_number
                . ' is now '
                . str_replace(
                    '_',
                    ' ',
                    $after->status
                )
                . '.',
                '/notifications'
            );
        }

        return response()->json([
            'message' =>
                'Support case updated.',
            'data' =>
                $after,
        ]);
    }

    public function sendCommunication(
        Request $request
    ): JsonResponse {
        $access =
            $this->requireElevatedSession(
                $request
            );

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required to send member communications.'
        );

        $data = $request->validate([
            'member_identifier' => [
                'required',
                'string',
                'max:190',
            ],
            'severity' => [
                'required',
                Rule::in([
                    'info',
                    'success',
                    'warning',
                    'error',
                ]),
            ],
            'title' => [
                'required',
                'string',
                'max:190',
            ],
            'message' => [
                'required',
                'string',
                'max:5000',
            ],
            'action_url' => [
                'nullable',
                'string',
                'max:512',
            ],
        ]);

        $userId =
            $this->resolveMemberUserId(
                $data[
                    'member_identifier'
                ]
            );

        abort_unless(
            $userId,
            422,
            'Member could not be resolved by email, member number or user ID.'
        );

        $actionUrl =
            trim(
                (string) (
                    $data[
                        'action_url'
                    ] ?? ''
                )
            );

        if (
            $actionUrl !== ''
        ) {
            abort_unless(
                str_starts_with(
                    $actionUrl,
                    '/'
                )
                && ! str_starts_with(
                    $actionUrl,
                    '//'
                ),
                422,
                'Communication action URL must be a relative NurseLink path.'
            );
        }

        $id = DB::table(
            'nurselink_notifications'
        )->insertGetId([
            'user_id' =>
                $userId,
            'type' =>
                'admin.communication',
            'severity' =>
                $data['severity'],
            'title' =>
                $data['title'],
            'message' =>
                $data['message'],
            'action_url' =>
                $actionUrl !== ''
                    ? $actionUrl
                    : '/notifications',
            'read_at' =>
                null,
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);

        $this->audit(
            $request,
            'communication.member_notification_sent',
            'notification',
            (string) $id,
            null,
            [
                'member_user_id' =>
                    $userId,
                'severity' =>
                    $data['severity'],
                'title' =>
                    $data['title'],
                'action_url' =>
                    $actionUrl !== ''
                        ? $actionUrl
                        : '/notifications',
                'message_body_excluded_from_audit'
                    => true,
            ]
        );

        return response()->json([
            'message' =>
                'Member communication sent.',
            'data' => [
                'notification_id' =>
                    (int) $id,
                'member_user_id' =>
                    $userId,
                'message_body_returned' =>
                    false,
            ],
        ], 201);
    }

    public function auditLog(
        Request $request
    ): JsonResponse {
        $access =
            $this->requireElevatedSession(
                $request
            );

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required for Audit Logs.'
        );

        if (
            ! Schema::hasTable(
                'nurselink_review_audit'
            )
        ) {
            return response()->json([
                'data' => [],
            ]);
        }

        $rows = DB::table(
            'nurselink_review_audit'
        )
            ->orderByDesc(
                'id'
            )
            ->limit(300)
            ->get([
                'id',
                'reviewer_user_id',
                'action',
                'target_type',
                'target_id',
                'created_at',
            ]);

        $users =
            $this->userMap(
                $rows
                    ->pluck(
                        'reviewer_user_id'
                    )
                    ->filter()
                    ->map(
                        fn ($value): string =>
                            (string) $value
                    )
                    ->all()
            );

        return response()->json([
            'data' =>
                $rows->map(
                    function (
                        $row
                    ) use (
                        $users
                    ): array {
                        $actor =
                            $users[
                                (string)
                                    $row
                                        ->reviewer_user_id
                            ] ?? null;

                        return [
                            'id' =>
                                (int) $row->id,
                            'action' =>
                                $row->action,
                            'target_type' =>
                                $row->target_type,
                            'target_id' =>
                                (string)
                                    $row->target_id,
                            'actor' =>
                                $actor,
                            'created_at' =>
                                $row->created_at,
                            'raw_before_state_included'
                                => false,
                            'raw_after_state_included'
                                => false,
                        ];
                    }
                )->values(),
        ]);
    }

    public function systemHealth(
        Request $request
    ): JsonResponse {
        $access =
            $this->requireElevatedSession(
                $request
            );

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required for System Health.'
        );

        $requiredTables = [
            'users',
            'nurselink_memberships',
            'nurselink_credentials_registry',
            'nurselink_partner_organizations',
            'nurselink_job_opportunities',
            'nurselink_job_applications',
            'nurselink_events',
            'nurselink_notifications',
            'nurselink_review_audit',
            'nurselink_support_cases',
        ];

        $tables = [];

        foreach (
            $requiredTables as $table
        ) {
            $tables[$table] =
                Schema::hasTable(
                    $table
                );
        }

        return response()->json([
            'data' => [
                'release' =>
                    self::RELEASE,
                'app_environment' =>
                    app()->environment(),
                'database_connected' =>
                    true,
                'storage_writable' =>
                    is_writable(
                        storage_path()
                    ),
                'tables' =>
                    $tables,
                'all_required_tables_present' =>
                    ! in_array(
                        false,
                        $tables,
                        true
                    ),
                'raw_environment_values_exposed'
                    => false,
                'database_credentials_exposed'
                    => false,
            ],
        ]);
    }

    public function settings(
        Request $request
    ): JsonResponse {
        $access =
            $this->requireElevatedSession(
                $request
            );

        return response()->json([
            'data' => [
                'release' =>
                    self::RELEASE,
                'role' =>
                    $access['role'],
                'entry_points' => [
                    'member_login' =>
                        '/login',
                    'member_portal' =>
                        '/dashboard',
                    'administrator_login' =>
                        '/nurselink-admin-login.html',
                    'administrator_portal' =>
                        '/nurselink-admin-dashboard.html',
                ],
                'governance' => [
                    'raw_database_administration' =>
                        false,
                    'workflow_controller_required'
                        => true,
                    'reviewer_can_make_final_membership_decision'
                        => false,
                    'administrator_can_manage_membership'
                        => true,
                    'super_administrator_required_for_privileged_role_changes'
                        => true,
                    'last_super_administrator_protected'
                        => true,
                    'self_revoke_protected'
                        => true,
                ],
            ],
        ]);
    }

    private function validateSupportCase(
        Request $request,
        bool $updating
    ): array {
        $rules = [
            'category' => [
                'required',
                Rule::in([
                    'membership',
                    'verification',
                    'employment',
                    'training',
                    'technical',
                    'organization',
                    'communications',
                    'other',
                ]),
            ],
            'priority' => [
                'required',
                Rule::in([
                    'low',
                    'normal',
                    'high',
                    'urgent',
                ]),
            ],
            'subject' => [
                'required',
                'string',
                'max:190',
            ],
            'description' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'assigned_admin_user_id' => [
                'nullable',
                'string',
                'max:191',
            ],
            'internal_note' => [
                'nullable',
                'string',
                'max:10000',
            ],
        ];

        if ($updating) {
            $rules['status'] = [
                'required',
                Rule::in([
                    'open',
                    'in_progress',
                    'waiting_member',
                    'waiting_internal',
                    'resolved',
                    'closed',
                ]),
            ];

            $rules[
                'resolution_summary'
            ] = [
                'nullable',
                'string',
                'max:10000',
            ];
        } else {
            $rules['source'] = [
                'required',
                Rule::in([
                    'member',
                    'admin',
                    'partner',
                    'system',
                ]),
            ];

            $rules[
                'member_identifier'
            ] = [
                'nullable',
                'string',
                'max:190',
            ];

            $rules[
                'organization_id'
            ] = [
                'nullable',
                'integer',
                'min:1',
            ];
        }

        return $request->validate(
            $rules
        );
    }

    private function resolveMemberUserId(
        ?string $identifier
    ): ?string {
        $identifier = trim(
            (string) $identifier
        );

        if (
            $identifier === ''
        ) {
            return null;
        }

        if (
            Schema::hasColumn(
                'users',
                'email'
            )
        ) {
            $userId = DB::table(
                'users'
            )
                ->where(
                    'email',
                    $identifier
                )
                ->value(
                    'id'
                );

            if ($userId !== null) {
                return (string)
                    $userId;
            }
        }

        $userId = DB::table(
            'users'
        )
            ->where(
                'id',
                $identifier
            )
            ->value(
                'id'
            );

        if ($userId !== null) {
            return (string)
                $userId;
        }

        if (
            Schema::hasTable(
                'nurselink_memberships'
            )
        ) {
            $userId = DB::table(
                'nurselink_memberships'
            )
                ->where(
                    'member_number',
                    $identifier
                )
                ->value(
                    'user_id'
                );

            if ($userId !== null) {
                return (string)
                    $userId;
            }
        }

        return null;
    }

    private function countWhere(
        string $table,
        string $column,
        string $value
    ): int {
        if (
            ! Schema::hasTable(
                $table
            )
            || ! Schema::hasColumn(
                $table,
                $column
            )
        ) {
            return 0;
        }

        return DB::table(
            $table
        )
            ->where(
                $column,
                $value
            )
            ->count();
    }

    private function countWhereIn(
        string $table,
        string $column,
        array $values
    ): int {
        if (
            ! Schema::hasTable(
                $table
            )
            || ! Schema::hasColumn(
                $table,
                $column
            )
        ) {
            return 0;
        }

        return DB::table(
            $table
        )
            ->whereIn(
                $column,
                $values
            )
            ->count();
    }

    private function notifyMember(
        string $userId,
        string $type,
        string $title,
        string $message,
        string $actionUrl
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
                $type,
            'severity' =>
                'info',
            'title' =>
                $title,
            'message' =>
                $message,
            'action_url' =>
                $actionUrl,
            'read_at' =>
                null,
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);
    }

    private function userMap(
        array $ids
    ): array {
        $ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        fn ($id): string =>
                            (string) $id,
                        $ids
                    )
                )
            )
        );

        if (
            $ids === []
        ) {
            return [];
        }

        $columns = [
            'id',
        ];

        foreach (
            [
                'email',
                'name',
                'first_name',
                'last_name',
            ] as $column
        ) {
            if (
                Schema::hasColumn(
                    'users',
                    $column
                )
            ) {
                $columns[] =
                    $column;
            }
        }

        $rows = DB::table(
            'users'
        )
            ->whereIn(
                'id',
                $ids
            )
            ->get(
                $columns
            );

        $map = [];

        foreach (
            $rows as $row
        ) {
            $name = trim(
                (string) (
                    $row->name
                    ?? ''
                )
            );

            if (
                $name === ''
            ) {
                $name = trim(
                    (string) (
                        $row->first_name
                        ?? ''
                    )
                    . ' '
                    . (string) (
                        $row->last_name
                        ?? ''
                    )
                );
            }

            $map[
                (string) $row->id
            ] = [
                'id' =>
                    (string) $row->id,
                'name' =>
                    $name !== ''
                        ? $name
                        : (string) (
                            $row->email
                            ?? $row->id
                        ),
                'email' =>
                    (string) (
                        $row->email
                        ?? ''
                    ),
            ];
        }

        return $map;
    }

    private function isActiveAdministrator(
        string $userId
    ): bool {
        $reviewerAccess =
            Schema::hasTable(
                'nurselink_reviewer_access'
            )
            && DB::table(
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
                ->whereIn(
                    'role',
                    [
                        'admin',
                        'super_admin',
                    ]
                )
                ->exists();

        $superAccess =
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

        return $reviewerAccess
            || $superAccess;
    }

    private function requireElevatedSession(
        Request $request
    ): array {
        $user =
            $request->user();

        abort_unless(
            $user,
            401
        );

        $userId =
            (string)
                $user->getKey();

        $sessionUserId =
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
            $sessionUserId !== ''
            && hash_equals(
                $sessionUserId,
                $userId
            )
            && $expiresAt >= time(),
            403,
            'A separate NurseLink Administrator Portal sign-in is required.'
        );

        $reviewRole =
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

        $modelRole = strtolower(
            trim(
                (string) (
                    $user->role
                    ?? $user->user_role
                    ?? $user->user_type
                    ?? ''
                )
            )
        );

        $isSuper =
            $super
            || $reviewRole
                === 'super_admin'
            || (bool) (
                $user->is_super_admin
                ?? false
            )
            || in_array(
                $modelRole,
                [
                    'super_admin',
                    'super-administrator',
                    'super_administrator',
                ],
                true
            );

        $isAdmin =
            $isSuper
            || $reviewRole
                === 'admin'
            || (bool) (
                $user->is_admin
                ?? false
            )
            || in_array(
                $modelRole,
                [
                    'admin',
                    'administrator',
                ],
                true
            );

        $isReviewer =
            $isAdmin
            || $reviewRole
                === 'reviewer';

        abort_unless(
            $isReviewer,
            403,
            'Reviewer or Administrator access is required.'
        );

        return [
            'role' =>
                $isSuper
                    ? 'super_admin'
                    : (
                        $isAdmin
                            ? 'admin'
                            : 'reviewer'
                    ),
            'is_reviewer' =>
                $isReviewer,
            'is_admin' =>
                $isAdmin,
            'is_super_admin' =>
                $isSuper,
        ];
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
                    $request
                        ->user()
                        ->getKey(),
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
