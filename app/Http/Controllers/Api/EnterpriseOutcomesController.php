<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class EnterpriseOutcomesController extends Controller
{
    private const OUTCOMES =
        'nurselink_enterprise_cohort_outcomes';

    private const COHORTS =
        'nurselink_enterprise_cohorts';

    private const COHORT_MEMBERS =
        'nurselink_enterprise_cohort_members';

    private const ORGANIZATIONS =
        'nurselink_partner_organizations';

    private const GOALS =
        'nurselink_enterprise_cohort_goals';

    private const PROGRESS =
        'nurselink_enterprise_cohort_progress';

    public function memberIndex(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $rows = DB::table(
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
            ->leftJoin(
                self::OUTCOMES . ' as x',
                function ($join) use ($userId): void {
                    $join->on(
                        'x.cohort_id',
                        '=',
                        'cm.cohort_id'
                    )->where(
                        'x.user_id',
                        '=',
                        $userId
                    )->where(
                        'x.member_visible',
                        '=',
                        true
                    );
                }
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
                    'inactive',
                ]
            )
            ->orderByDesc(
                'cm.updated_at'
            )
            ->select([
                'cm.cohort_id',
                'cm.status as assignment_status',
                'cm.joined_at',
                'cm.completed_at as assignment_completed_at',
                'c.name as cohort_name',
                'c.code as cohort_code',
                'c.status as cohort_status',
                'c.starts_at',
                'c.ends_at',
                'o.name as organization_name',
                'o.organization_type',
                'o.country',
                'o.city',
                'x.id as outcome_id',
                'x.outcome_status',
                'x.completion_basis',
                'x.member_summary',
                'x.completed_at as outcome_completed_at',
                'x.reviewed_at',
            ])
            ->get()
            ->map(
                function ($row) use ($userId): array {
                    return [
                        'cohort_id' =>
                            (int) $row->cohort_id,
                        'cohort_name' =>
                            $row->cohort_name,
                        'cohort_code' =>
                            $row->cohort_code,
                        'cohort_status' =>
                            $row->cohort_status,
                        'assignment_status' =>
                            $row->assignment_status,
                        'joined_at' =>
                            $row->joined_at,
                        'assignment_completed_at' =>
                            $row->assignment_completed_at,
                        'starts_at' =>
                            $row->starts_at,
                        'ends_at' =>
                            $row->ends_at,
                        'organization_name' =>
                            $row->organization_name,
                        'organization_type' =>
                            $row->organization_type,
                        'country' =>
                            $row->country,
                        'city' =>
                            $row->city,
                        'outcome' => [
                            'id' =>
                                $row->outcome_id
                                ? (int) $row->outcome_id
                                : null,
                            'status' =>
                                $row->outcome_status,
                            'completion_basis' =>
                                $row->completion_basis,
                            'member_summary' =>
                                $row->member_summary,
                            'completed_at' =>
                                $row->outcome_completed_at,
                            'reviewed_at' =>
                                $row->reviewed_at,
                        ],
                        'goal_progress' =>
                            $this->goalProgressSummary(
                                (int) $row->cohort_id,
                                $userId
                            ),
                    ];
                }
            )
            ->values();

        return response()->json([
            'data' =>
                $rows,
            'privacy' => [
                'internal_notes_included'
                    => false,
                'other_member_outcomes_included'
                    => false,
                'partner_only_data_included'
                    => false,
            ],
            'governance' => [
                'nurselink_internal_outcome'
                    => true,
                'official_certificate'
                    => false,
                'official_credential'
                    => false,
                'official_cpd_record'
                    => false,
                'employment_determination'
                    => false,
                'regulatory_determination'
                    => false,
                'message' =>
                    'Enterprise cohort outcomes are NurseLink internal coordination records. A completed outcome is not an official certificate, professional credential, CPD record, employment determination, licensure decision or regulatory determination.',
            ],
        ]);
    }

    public function adminCohortOutcomes(
        Request $request,
        int $cohortId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

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
                $cohortId
            )
            ->first([
                'c.*',
                'o.name as organization_name',
                'o.status as organization_status',
            ]);

        abort_unless(
            $cohort,
            404
        );

        $rows = DB::table(
            self::COHORT_MEMBERS . ' as cm'
        )
            ->join(
                'users as u',
                'u.id',
                '=',
                'cm.user_id'
            )
            ->leftJoin(
                'nurselink_memberships as m',
                'm.user_id',
                '=',
                'cm.user_id'
            )
            ->leftJoin(
                self::OUTCOMES . ' as x',
                function ($join): void {
                    $join->on(
                        'x.cohort_id',
                        '=',
                        'cm.cohort_id'
                    )->on(
                        'x.user_id',
                        '=',
                        'cm.user_id'
                    );
                }
            )
            ->where(
                'cm.cohort_id',
                $cohortId
            )
            ->orderBy('u.email')
            ->select([
                'cm.user_id',
                'cm.status as assignment_status',
                'u.name',
                'u.email',
                'm.member_number',
                'm.standing',
                'x.id as outcome_id',
                'x.outcome_status',
                'x.completion_basis',
                'x.member_summary',
                'x.internal_note',
                'x.member_visible',
                'x.completed_at',
                'x.reviewed_at',
            ])
            ->get()
            ->map(
                function ($row) use ($cohortId): array {
                    return [
                        'user_id' =>
                            (string) $row->user_id,
                        'name' =>
                            $row->name,
                        'email' =>
                            $row->email,
                        'member_number' =>
                            $row->member_number,
                        'standing' =>
                            $row->standing,
                        'assignment_status' =>
                            $row->assignment_status,
                        'outcome' => [
                            'id' =>
                                $row->outcome_id
                                ? (int) $row->outcome_id
                                : null,
                            'status' =>
                                $row->outcome_status
                                ?: 'in_progress',
                            'completion_basis' =>
                                $row->completion_basis
                                ?: 'admin_review',
                            'member_summary' =>
                                $row->member_summary,
                            'internal_note' =>
                                $row->internal_note,
                            'member_visible' =>
                                $row->outcome_id
                                ? (bool) $row->member_visible
                                : true,
                            'completed_at' =>
                                $row->completed_at,
                            'reviewed_at' =>
                                $row->reviewed_at,
                        ],
                        'goal_progress' =>
                            $this->goalProgressSummary(
                                $cohortId,
                                (string) $row->user_id
                            ),
                    ];
                }
            )
            ->values();

        return response()->json([
            'data' => [
                'cohort' => [
                    'id' =>
                        (int) $cohort->id,
                    'name' =>
                        $cohort->name,
                    'code' =>
                        $cohort->code,
                    'status' =>
                        $cohort->status,
                    'organization_name' =>
                        $cohort->organization_name,
                    'organization_status' =>
                        $cohort->organization_status,
                ],
                'members' =>
                    $rows,
            ],
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

    public function adminUpdateOutcome(
        Request $request,
        int $cohortId,
        string $userId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $assignment = DB::table(
            self::COHORT_MEMBERS
        )
            ->where(
                'cohort_id',
                $cohortId
            )
            ->where(
                'user_id',
                $userId
            )
            ->first();

        abort_unless(
            $assignment,
            404,
            'Enterprise cohort assignment not found.'
        );

        $data = $request->validate([
            'outcome_status' => [
                'required',
                Rule::in([
                    'in_progress',
                    'completed',
                    'withdrawn',
                    'not_completed',
                ]),
            ],
            'completion_basis' => [
                'required',
                Rule::in([
                    'admin_review',
                    'goal_progress',
                    'participation',
                    'custom',
                ]),
            ],
            'member_summary' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'internal_note' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'member_visible' => [
                'required',
                'boolean',
            ],
        ]);

        $before = DB::table(
            self::OUTCOMES
        )
            ->where(
                'cohort_id',
                $cohortId
            )
            ->where(
                'user_id',
                $userId
            )
            ->first();

        $values = [
            'outcome_status' =>
                $data['outcome_status'],
            'completion_basis' =>
                $data['completion_basis'],
            'member_summary' =>
                $data['member_summary']
                ?? null,
            'internal_note' =>
                $data['internal_note']
                ?? null,
            'member_visible' =>
                (bool) $data['member_visible'],
            'completed_at' =>
                $data['outcome_status']
                    === 'completed'
                    ? ($before?->completed_at ?: now())
                    : null,
            'reviewed_by' =>
                (string) $request->user()->getKey(),
            'reviewed_at' =>
                now(),
            'updated_at' =>
                now(),
        ];

        if ($before) {
            DB::table(
                self::OUTCOMES
            )
                ->where(
                    'id',
                    $before->id
                )
                ->update(
                    $values
                );

            $id =
                (int) $before->id;
        } else {
            $id = DB::table(
                self::OUTCOMES
            )->insertGetId([
                'cohort_id' =>
                    $cohortId,
                'user_id' =>
                    $userId,
                ...$values,
                'created_at' =>
                    now(),
            ]);
        }

        $after = DB::table(
            self::OUTCOMES
        )
            ->where(
                'id',
                $id
            )
            ->first();

        $this->audit(
            $request,
            'enterprise.outcome_updated',
            'enterprise_cohort_outcome',
            (string) $id,
            $before,
            $after
        );

        $this->notifyMemberOutcome(
            $userId,
            $cohortId,
            (bool) $data['member_visible']
        );

        return response()->json([
            'message' =>
                'Enterprise cohort outcome saved.',
            'data' =>
                $after,
            'governance' => [
                'updates_cohort_assignment_automatically'
                    => false,
                'official_completion_certificate'
                    => false,
            ],
        ]);
    }

    public function partnerOutcomes(
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
                            'inactive',
                        ]
                    )
                    ->distinct()
                    ->pluck('user_id')
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
                    'cohort_status' =>
                        $cohort->status,
                    'member_count' =>
                        count($memberIds),
                    'privacy_threshold' =>
                        $threshold,
                    'metrics_suppressed' =>
                        $suppressed,
                    'outcomes' =>
                        null,
                    'goal_progress' =>
                        null,
                ];

                if ($suppressed) {
                    return $row;
                }

                $outcomes = DB::table(
                    self::OUTCOMES
                )
                    ->where(
                        'cohort_id',
                        $cohort->id
                    )
                    ->whereIn(
                        'user_id',
                        $memberIds
                    );

                $row['outcomes'] = [
                    'reviewed' =>
                        (clone $outcomes)
                            ->count(),
                    'in_progress' =>
                        (clone $outcomes)
                            ->where(
                                'outcome_status',
                                'in_progress'
                            )
                            ->count(),
                    'completed' =>
                        (clone $outcomes)
                            ->where(
                                'outcome_status',
                                'completed'
                            )
                            ->count(),
                    'withdrawn' =>
                        (clone $outcomes)
                            ->where(
                                'outcome_status',
                                'withdrawn'
                            )
                            ->count(),
                    'not_completed' =>
                        (clone $outcomes)
                            ->where(
                                'outcome_status',
                                'not_completed'
                            )
                            ->count(),
                ];

                $row['goal_progress'] =
                    $this->aggregateGoalProgress(
                        (int) $cohort->id,
                        $memberIds
                    );

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
                'member_summary_included'
                    => false,
                'internal_notes_included'
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
                'nurselink_internal_outcomes'
                    => true,
                'official_certificate_metrics'
                    => false,
                'official_credential_metrics'
                    => false,
                'employment_performance_metrics'
                    => false,
                'regulatory_metrics'
                    => false,
            ],
        ]);
    }

    private function goalProgressSummary(
        int $cohortId,
        string $userId
    ): array {
        if (
            ! Schema::hasTable(
                self::GOALS
            )
            || ! Schema::hasTable(
                self::PROGRESS
            )
        ) {
            return [
                'goals_total' => 0,
                'completed' => 0,
                'in_progress' => 0,
                'not_started' => 0,
                'waived' => 0,
                'completion_rate' => 0.0,
            ];
        }

        $goalIds = DB::table(
            self::GOALS
        )
            ->where(
                'cohort_id',
                $cohortId
            )
            ->whereIn(
                'status',
                [
                    'active',
                    'completed',
                ]
            )
            ->pluck('id')
            ->all();

        if ($goalIds === []) {
            return [
                'goals_total' => 0,
                'completed' => 0,
                'in_progress' => 0,
                'not_started' => 0,
                'waived' => 0,
                'completion_rate' => 0.0,
            ];
        }

        $progress = DB::table(
            self::PROGRESS
        )
            ->whereIn(
                'goal_id',
                $goalIds
            )
            ->where(
                'user_id',
                $userId
            );

        $completed =
            (clone $progress)
                ->where(
                    'status',
                    'completed'
                )
                ->count();

        $inProgress =
            (clone $progress)
                ->where(
                    'status',
                    'in_progress'
                )
                ->count();

        $waived =
            (clone $progress)
                ->where(
                    'status',
                    'waived'
                )
                ->count();

        $recorded =
            (clone $progress)
                ->count();

        $notStarted =
            max(
                0,
                count($goalIds)
                - $recorded
            )
            + (clone $progress)
                ->where(
                    'status',
                    'not_started'
                )
                ->count();

        $denominator =
            max(
                1,
                count($goalIds)
                - $waived
            );

        return [
            'goals_total' =>
                count($goalIds),
            'completed' =>
                $completed,
            'in_progress' =>
                $inProgress,
            'not_started' =>
                $notStarted,
            'waived' =>
                $waived,
            'completion_rate' =>
                round(
                    ($completed / $denominator)
                    * 100,
                    1
                ),
        ];
    }

    private function aggregateGoalProgress(
        int $cohortId,
        array $userIds
    ): array {
        if (
            $userIds === []
            || ! Schema::hasTable(
                self::GOALS
            )
            || ! Schema::hasTable(
                self::PROGRESS
            )
        ) {
            return [
                'goal_records' => 0,
                'completed_progress_records' => 0,
                'completion_rate' => 0.0,
            ];
        }

        $goalIds = DB::table(
            self::GOALS
        )
            ->where(
                'cohort_id',
                $cohortId
            )
            ->whereIn(
                'status',
                [
                    'active',
                    'completed',
                ]
            )
            ->pluck('id')
            ->all();

        if ($goalIds === []) {
            return [
                'goal_records' => 0,
                'completed_progress_records' => 0,
                'completion_rate' => 0.0,
            ];
        }

        $expected =
            count($goalIds)
            * count($userIds);

        $completed =
            DB::table(
                self::PROGRESS
            )
                ->whereIn(
                    'goal_id',
                    $goalIds
                )
                ->whereIn(
                    'user_id',
                    $userIds
                )
                ->where(
                    'status',
                    'completed'
                )
                ->count();

        return [
            'goal_records' =>
                $expected,
            'completed_progress_records' =>
                $completed,
            'completion_rate' =>
                $expected > 0
                    ? round(
                        ($completed / $expected)
                        * 100,
                        1
                    )
                    : 0.0,
        ];
    }

    private function notifyMemberOutcome(
        string $userId,
        int $cohortId,
        bool $memberVisible
    ): void {
        if (
            ! $memberVisible
            || ! Schema::hasTable(
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
                'enterprise.cohort.outcome',
            'severity' =>
                'info',
            'title' =>
                'Enterprise cohort outcome updated',
            'message' =>
                'Your NurseLink enterprise outcome for '
                . $cohort->name
                . ' has been updated.',
            'action_url' =>
                '/nurselink-enterprise-outcomes.html',
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
            'Administrator access is required for enterprise outcome management.'
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
