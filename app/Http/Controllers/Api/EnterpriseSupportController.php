<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class EnterpriseSupportController extends Controller
{
    private const CHECKINS =
        'nurselink_enterprise_support_checkins';

    private const COHORTS =
        'nurselink_enterprise_cohorts';

    private const COHORT_MEMBERS =
        'nurselink_enterprise_cohort_members';

    private const ORGANIZATIONS =
        'nurselink_partner_organizations';

    public function memberIndex(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $rows = DB::table(
            self::CHECKINS . ' as s'
        )
            ->join(
                self::COHORTS . ' as c',
                'c.id',
                '=',
                's.cohort_id'
            )
            ->join(
                self::ORGANIZATIONS . ' as o',
                'o.id',
                '=',
                'c.partner_organization_id'
            )
            ->where(
                's.user_id',
                $userId
            )
            ->orderByDesc(
                's.submitted_at'
            )
            ->select([
                's.id',
                's.cohort_id',
                's.checkin_type',
                's.support_level',
                's.status',
                's.member_sentiment',
                's.member_note',
                's.submitted_at',
                's.acknowledged_at',
                's.resolved_at',
                'c.name as cohort_name',
                'c.code as cohort_code',
                'o.name as organization_name',
            ])
            ->get();

        $assignments = DB::table(
            self::COHORT_MEMBERS . ' as cm'
        )
            ->join(
                self::COHORTS . ' as c',
                'c.id',
                '=',
                'cm.cohort_id'
            )
            ->join(
                self::ORGANIZATIONS . ' as o',
                'o.id',
                '=',
                'c.partner_organization_id'
            )
            ->where(
                'cm.user_id',
                $userId
            )
            ->whereIn(
                'cm.status',
                [
                    'active',
                    'completed',
                ]
            )
            ->orderBy('o.name')
            ->orderBy('c.name')
            ->get([
                'c.id',
                'c.name',
                'c.code',
                'o.name as organization_name',
            ]);

        return response()->json([
            'data' => [
                'checkins' =>
                    $rows,
                'available_cohorts' =>
                    $assignments,
            ],
            'privacy' => [
                'member_own_records_only'
                    => true,
                'administrator_notes_included'
                    => false,
                'partner_access_to_member_notes'
                    => false,
            ],
            'governance' => [
                'support_record_is_employment_action'
                    => false,
                'support_record_is_disciplinary_action'
                    => false,
                'support_record_is_clinical_record'
                    => false,
                'support_record_is_regulatory_record'
                    => false,
                'message' =>
                    'Enterprise check-ins are NurseLink coordination records for cohort support and follow-up. They are not employment, disciplinary, clinical, credentialing or regulatory records.',
            ],
        ]);
    }

    public function memberStore(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $data = $request->validate([
            'cohort_id' => [
                'required',
                'integer',
            ],
            'checkin_type' => [
                'required',
                Rule::in([
                    'general',
                    'progress',
                    'support',
                    'resource',
                    'other',
                ]),
            ],
            'support_level' => [
                'required',
                Rule::in([
                    'none',
                    'question',
                    'support_requested',
                    'priority',
                ]),
            ],
            'member_sentiment' => [
                'nullable',
                Rule::in([
                    'on_track',
                    'unsure',
                    'needs_attention',
                ]),
            ],
            'member_note' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ]);

        $assignment = DB::table(
            self::COHORT_MEMBERS
        )
            ->where(
                'cohort_id',
                $data['cohort_id']
            )
            ->where(
                'user_id',
                $userId
            )
            ->whereIn(
                'status',
                [
                    'active',
                    'completed',
                ]
            )
            ->first();

        abort_unless(
            $assignment,
            422,
            'You can submit a check-in only for one of your NurseLink enterprise cohort assignments.'
        );

        $id = DB::table(
            self::CHECKINS
        )->insertGetId([
            'cohort_id' =>
                $data['cohort_id'],
            'user_id' =>
                $userId,
            'checkin_type' =>
                $data['checkin_type'],
            'support_level' =>
                $data['support_level'],
            'status' =>
                'open',
            'member_sentiment' =>
                $data['member_sentiment']
                ?? null,
            'member_note' =>
                $data['member_note']
                ?? null,
            'admin_note' =>
                null,
            'assigned_to' =>
                null,
            'submitted_at' =>
                now(),
            'acknowledged_at' =>
                null,
            'resolved_at' =>
                null,
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);

        $after = DB::table(
            self::CHECKINS
        )
            ->where(
                'id',
                $id
            )
            ->first();

        $this->audit(
            $request,
            'enterprise.support_checkin_submitted',
            'enterprise_support_checkin',
            (string) $id,
            null,
            $after
        );

        return response()->json([
            'message' =>
                'Enterprise cohort check-in submitted.',
            'data' =>
                $after,
        ], 201);
    }

    public function adminIndex(
        Request $request
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $query = DB::table(
            self::CHECKINS . ' as s'
        )
            ->join(
                self::COHORTS . ' as c',
                'c.id',
                '=',
                's.cohort_id'
            )
            ->join(
                self::ORGANIZATIONS . ' as o',
                'o.id',
                '=',
                'c.partner_organization_id'
            )
            ->join(
                'users as u',
                'u.id',
                '=',
                's.user_id'
            )
            ->leftJoin(
                'nurselink_memberships as m',
                'm.user_id',
                '=',
                's.user_id'
            );

        if (
            $request->filled(
                'status'
            )
        ) {
            $query->where(
                's.status',
                (string) $request->input(
                    'status'
                )
            );
        }

        if (
            $request->filled(
                'cohort_id'
            )
        ) {
            $query->where(
                's.cohort_id',
                (int) $request->input(
                    'cohort_id'
                )
            );
        }

        $rows = $query
            ->orderByRaw(
                "CASE s.support_level
                    WHEN 'priority' THEN 1
                    WHEN 'support_requested' THEN 2
                    WHEN 'question' THEN 3
                    ELSE 4 END"
            )
            ->orderByDesc(
                's.submitted_at'
            )
            ->limit(500)
            ->get([
                's.id',
                's.cohort_id',
                's.user_id',
                's.checkin_type',
                's.support_level',
                's.status',
                's.member_sentiment',
                's.member_note',
                's.admin_note',
                's.assigned_to',
                's.submitted_at',
                's.acknowledged_at',
                's.resolved_at',
                'c.name as cohort_name',
                'c.code as cohort_code',
                'o.name as organization_name',
                'u.name',
                'u.email',
                'm.member_number',
                'm.standing',
            ]);

        return response()->json([
            'data' =>
                $rows,
            'privacy' => [
                'administrator_only_detail'
                    => true,
                'partner_access_to_member_detail'
                    => false,
                'home_address_included'
                    => false,
                'phone_included'
                    => false,
                'documents_included'
                    => false,
                'credential_numbers_included'
                    => false,
            ],
        ]);
    }

    public function adminUpdate(
        Request $request,
        int $checkinId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $before = DB::table(
            self::CHECKINS
        )
            ->where(
                'id',
                $checkinId
            )
            ->first();

        abort_unless(
            $before,
            404
        );

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'open',
                    'acknowledged',
                    'in_follow_up',
                    'resolved',
                    'closed',
                ]),
            ],
            'support_level' => [
                'required',
                Rule::in([
                    'none',
                    'question',
                    'support_requested',
                    'priority',
                ]),
            ],
            'admin_note' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'assigned_to' => [
                'nullable',
                'string',
                'max:191',
            ],
        ]);

        $acknowledgedAt =
            $before->acknowledged_at;

        if (
            $acknowledgedAt === null
            && in_array(
                $data['status'],
                [
                    'acknowledged',
                    'in_follow_up',
                    'resolved',
                    'closed',
                ],
                true
            )
        ) {
            $acknowledgedAt =
                now();
        }

        $resolvedAt =
            in_array(
                $data['status'],
                [
                    'resolved',
                    'closed',
                ],
                true
            )
                ? ($before->resolved_at ?: now())
                : null;

        DB::table(
            self::CHECKINS
        )
            ->where(
                'id',
                $checkinId
            )
            ->update([
                'status' =>
                    $data['status'],
                'support_level' =>
                    $data['support_level'],
                'admin_note' =>
                    $data['admin_note']
                    ?? null,
                'assigned_to' =>
                    $data['assigned_to']
                    ?? null,
                'acknowledged_at' =>
                    $acknowledgedAt,
                'resolved_at' =>
                    $resolvedAt,
                'updated_at' =>
                    now(),
            ]);

        $after = DB::table(
            self::CHECKINS
        )
            ->where(
                'id',
                $checkinId
            )
            ->first();

        $this->audit(
            $request,
            'enterprise.support_checkin_updated',
            'enterprise_support_checkin',
            (string) $checkinId,
            $before,
            $after
        );

        $this->notifyMember(
            (string) $after->user_id,
            (int) $after->cohort_id,
            $after->status
        );

        return response()->json([
            'message' =>
                'Enterprise cohort support record updated.',
            'data' =>
                $after,
        ]);
    }

    public function partnerSummary(
        Request $request
    ): JsonResponse {
        $scope = $this->authorizePartner(
            $request
        );

        $organization =
            $scope['organization'];

        $cohorts = DB::table(
            self::COHORTS
        )
            ->where(
                'partner_organization_id',
                $organization->id
            )
            ->orderBy('name')
            ->get();

        $rows = $cohorts->map(
            function ($cohort): array {
                $memberIds = DB::table(
                    self::COHORT_MEMBERS
                )
                    ->where(
                        'cohort_id',
                        $cohort->id
                    )
                    ->whereIn(
                        'status',
                        [
                            'active',
                            'completed',
                        ]
                    )
                    ->distinct()
                    ->pluck(
                        'user_id'
                    )
                    ->all();

                $threshold = 3;
                $suppressed =
                    count($memberIds)
                    < $threshold;

                $row = [
                    'cohort_id' =>
                        (int) $cohort->id,
                    'cohort_name' =>
                        $cohort->name,
                    'cohort_code' =>
                        $cohort->code,
                    'member_count' =>
                        count($memberIds),
                    'privacy_threshold' =>
                        $threshold,
                    'metrics_suppressed' =>
                        $suppressed,
                    'support' =>
                        null,
                ];

                if ($suppressed) {
                    return $row;
                }

                $checkins = DB::table(
                    self::CHECKINS
                )
                    ->where(
                        'cohort_id',
                        $cohort->id
                    )
                    ->whereIn(
                        'user_id',
                        $memberIds
                    );

                $row['support'] = [
                    'checkins_total' =>
                        (clone $checkins)
                            ->count(),
                    'open' =>
                        (clone $checkins)
                            ->where(
                                'status',
                                'open'
                            )
                            ->count(),
                    'acknowledged' =>
                        (clone $checkins)
                            ->where(
                                'status',
                                'acknowledged'
                            )
                            ->count(),
                    'in_follow_up' =>
                        (clone $checkins)
                            ->where(
                                'status',
                                'in_follow_up'
                            )
                            ->count(),
                    'resolved' =>
                        (clone $checkins)
                            ->whereIn(
                                'status',
                                [
                                    'resolved',
                                    'closed',
                                ]
                            )
                            ->count(),
                    'support_requested' =>
                        (clone $checkins)
                            ->whereIn(
                                'support_level',
                                [
                                    'support_requested',
                                    'priority',
                                ]
                            )
                            ->count(),
                ];

                return $row;
            }
        )->values();

        return response()->json([
            'data' => [
                'organization' => [
                    'id' =>
                        (int) $organization->id,
                    'name' =>
                        $organization->name,
                ],
                'cohorts' =>
                    $rows,
            ],
            'privacy' => [
                'aggregate_only'
                    => true,
                'member_identity_included'
                    => false,
                'member_contact_details_included'
                    => false,
                'member_notes_included'
                    => false,
                'administrator_notes_included'
                    => false,
                'assigned_staff_included'
                    => false,
                'documents_included'
                    => false,
                'credential_numbers_included'
                    => false,
                'small_cohort_metrics_suppressed'
                    => true,
                'minimum_aggregate_cohort_size'
                    => 3,
            ],
            'governance' => [
                'employment_action_metrics'
                    => false,
                'disciplinary_action_metrics'
                    => false,
                'clinical_record_metrics'
                    => false,
                'regulatory_record_metrics'
                    => false,
            ],
        ]);
    }

    private function notifyMember(
        string $userId,
        int $cohortId,
        string $status
    ): void {
        if (
            ! Schema::hasTable(
                'nurselink_notifications'
            )
        ) {
            return;
        }

        $cohort = DB::table(
            self::COHORTS
        )
            ->where(
                'id',
                $cohortId
            )
            ->first();

        if (! $cohort) {
            return;
        }

        DB::table(
            'nurselink_notifications'
        )->insert([
            'user_id' =>
                $userId,
            'type' =>
                'enterprise.support.update',
            'severity' =>
                'info',
            'title' =>
                'Enterprise support check-in updated',
            'message' =>
                'Your NurseLink enterprise check-in for '
                . $cohort->name
                . ' is now '
                . str_replace(
                    '_',
                    ' ',
                    $status
                )
                . '.',
            'action_url' =>
                '/nurselink-enterprise-support.html',
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);
    }

    private function authorizePartner(
        Request $request
    ): array {
        $user = $request->user();
        abort_unless($user, 401);

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
                $access->partner_organization_id
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
        abort_unless($user, 401);

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
            && $expiresAt >= time(),
            403,
            'A separate NurseLink Administrator Portal sign-in is required.'
        );

        $role =
            Schema::hasTable(
                'nurselink_reviewer_access'
            )
                ? strtolower((string) (
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
                        ->value('role')
                    ?? ''
                ))
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
                $user->is_super_admin
                ?? false
            ),
            403,
            'Administrator access is required for enterprise support management.'
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
                (string) $request->user()->getKey(),
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
