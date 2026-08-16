<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class EnterpriseGoalsController extends Controller
{
    private const GOALS =
        'nurselink_enterprise_cohort_goals';

    private const PROGRESS =
        'nurselink_enterprise_cohort_progress';

    private const COHORTS =
        'nurselink_enterprise_cohorts';

    private const COHORT_MEMBERS =
        'nurselink_enterprise_cohort_members';

    public function memberIndex(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $rows = DB::table(self::GOALS . ' as g')
            ->join(
                self::COHORTS . ' as c',
                'c.id',
                '=',
                'g.cohort_id'
            )
            ->join(
                self::COHORT_MEMBERS . ' as cm',
                function ($join) use ($userId): void {
                    $join->on(
                        'cm.cohort_id',
                        '=',
                        'g.cohort_id'
                    )->where(
                        'cm.user_id',
                        '=',
                        $userId
                    );
                }
            )
            ->leftJoin(
                self::PROGRESS . ' as p',
                function ($join) use ($userId): void {
                    $join->on(
                        'p.goal_id',
                        '=',
                        'g.id'
                    )->where(
                        'p.user_id',
                        '=',
                        $userId
                    );
                }
            )
            ->whereIn(
                'cm.status',
                ['active', 'completed']
            )
            ->whereIn(
                'g.visibility',
                ['members_only', 'members_and_partners']
            )
            ->whereIn(
                'g.status',
                ['active', 'completed']
            )
            ->orderByRaw(
                'CASE WHEN g.due_at IS NULL THEN 1 ELSE 0 END'
            )
            ->orderBy('g.due_at')
            ->orderBy('g.title')
            ->select([
                'g.id',
                'g.cohort_id',
                'g.title',
                'g.description',
                'g.goal_type',
                'g.target_value',
                'g.target_unit',
                'g.status as goal_status',
                'g.visibility',
                'g.due_at',
                'c.name as cohort_name',
                'c.code as cohort_code',
                'cm.status as cohort_membership_status',
                'p.status as progress_status',
                'p.progress_value',
                'p.member_note',
                'p.completed_at',
            ])
            ->get();

        return response()->json([
            'data' => $rows,
            'governance' => [
                'self_reported_progress' => true,
                'official_credential_status' => false,
                'official_cpd_record' => false,
                'employment_performance_record' => false,
                'message' =>
                    'Enterprise goal progress is a NurseLink coordination record and may include member self-reported progress. It is not an official credential, regulatory, employment-performance or CPD record.',
            ],
        ]);
    }

    public function memberUpdate(
        Request $request,
        int $goalId
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'not_started',
                    'in_progress',
                    'completed',
                    'waived',
                ]),
            ],
            'progress_value' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999',
            ],
            'member_note' => [
                'nullable',
                'string',
                'max:3000',
            ],
        ]);

        $goal = DB::table(self::GOALS . ' as g')
            ->join(
                self::COHORT_MEMBERS . ' as cm',
                function ($join) use ($userId): void {
                    $join->on(
                        'cm.cohort_id',
                        '=',
                        'g.cohort_id'
                    )->where(
                        'cm.user_id',
                        '=',
                        $userId
                    );
                }
            )
            ->where('g.id', $goalId)
            ->whereIn(
                'cm.status',
                ['active', 'completed']
            )
            ->whereIn(
                'g.visibility',
                ['members_only', 'members_and_partners']
            )
            ->whereIn(
                'g.status',
                ['active', 'completed']
            )
            ->first([
                'g.id',
                'g.cohort_id',
                'g.target_value',
            ]);

        abort_unless($goal, 404);

        $before = DB::table(self::PROGRESS)
            ->where('goal_id', $goalId)
            ->where('user_id', $userId)
            ->first();

        $values = [
            'status' => $data['status'],
            'progress_value' =>
                $data['progress_value'] ?? null,
            'member_note' =>
                $data['member_note'] ?? null,
            'completed_at' =>
                $data['status'] === 'completed'
                    ? ($before?->completed_at ?: now())
                    : null,
            'updated_at' => now(),
        ];

        if ($before) {
            DB::table(self::PROGRESS)
                ->where('id', $before->id)
                ->update($values);
            $id = (int) $before->id;
        } else {
            $id = DB::table(self::PROGRESS)
                ->insertGetId([
                    'goal_id' => $goalId,
                    'user_id' => $userId,
                    ...$values,
                    'created_at' => now(),
                ]);
        }

        $after = DB::table(self::PROGRESS)
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'enterprise.goal_progress_member_updated',
            'enterprise_goal_progress',
            (string) $id,
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Enterprise goal progress updated.',
            'data' => $after,
        ]);
    }

    public function adminGoals(
        Request $request,
        int $cohortId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $this->requireCohort($cohortId);

        $rows = DB::table(self::GOALS)
            ->where('cohort_id', $cohortId)
            ->orderByRaw(
                "CASE status
                    WHEN 'active' THEN 1
                    WHEN 'planned' THEN 2
                    WHEN 'completed' THEN 3
                    ELSE 4 END"
            )
            ->orderBy('due_at')
            ->orderBy('title')
            ->get()
            ->map(function ($goal): array {
                $progress = DB::table(
                    self::PROGRESS
                )
                    ->where(
                        'goal_id',
                        $goal->id
                    );

                return [
                    'id' => (int) $goal->id,
                    'cohort_id' =>
                        (int) $goal->cohort_id,
                    'title' => $goal->title,
                    'description' =>
                        $goal->description,
                    'goal_type' =>
                        $goal->goal_type,
                    'target_value' =>
                        $goal->target_value,
                    'target_unit' =>
                        $goal->target_unit,
                    'status' => $goal->status,
                    'visibility' =>
                        $goal->visibility,
                    'due_at' => $goal->due_at,
                    'progress' => [
                        'records' =>
                            (clone $progress)->count(),
                        'not_started' =>
                            (clone $progress)
                                ->where(
                                    'status',
                                    'not_started'
                                )
                                ->count(),
                        'in_progress' =>
                            (clone $progress)
                                ->where(
                                    'status',
                                    'in_progress'
                                )
                                ->count(),
                        'completed' =>
                            (clone $progress)
                                ->where(
                                    'status',
                                    'completed'
                                )
                                ->count(),
                        'waived' =>
                            (clone $progress)
                                ->where(
                                    'status',
                                    'waived'
                                )
                                ->count(),
                    ],
                ];
            })
            ->values();

        return response()->json([
            'data' => $rows,
            'privacy' => [
                'member_notes_in_summary' => false,
                'member_contact_details_in_summary'
                    => false,
            ],
        ]);
    }

    public function adminStoreGoal(
        Request $request,
        int $cohortId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $this->requireCohort($cohortId);

        $data = $this->validateGoal(
            $request
        );

        $id = DB::table(self::GOALS)
            ->insertGetId([
                'cohort_id' => $cohortId,
                ...$data,
                'created_by' =>
                    (string)
                        $request->user()->getKey(),
                'updated_by' =>
                    (string)
                        $request->user()->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $row = DB::table(self::GOALS)
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'enterprise.goal_created',
            'enterprise_goal',
            (string) $id,
            null,
            $row
        );

        return response()->json([
            'message' =>
                'Enterprise cohort goal created.',
            'data' => $row,
        ], 201);
    }

    public function adminUpdateGoal(
        Request $request,
        int $goalId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $before = DB::table(self::GOALS)
            ->where('id', $goalId)
            ->first();

        abort_unless($before, 404);

        $data = $this->validateGoal(
            $request
        );

        DB::table(self::GOALS)
            ->where('id', $goalId)
            ->update([
                ...$data,
                'updated_by' =>
                    (string)
                        $request->user()->getKey(),
                'updated_at' => now(),
            ]);

        $after = DB::table(self::GOALS)
            ->where('id', $goalId)
            ->first();

        $this->audit(
            $request,
            'enterprise.goal_updated',
            'enterprise_goal',
            (string) $goalId,
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Enterprise cohort goal updated.',
            'data' => $after,
        ]);
    }

    public function adminProgress(
        Request $request,
        int $goalId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $goal = DB::table(self::GOALS)
            ->where('id', $goalId)
            ->first();

        abort_unless($goal, 404);

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
                self::PROGRESS . ' as p',
                function ($join) use ($goalId): void {
                    $join->on(
                        'p.user_id',
                        '=',
                        'cm.user_id'
                    )->where(
                        'p.goal_id',
                        '=',
                        $goalId
                    );
                }
            )
            ->where(
                'cm.cohort_id',
                $goal->cohort_id
            )
            ->whereIn(
                'cm.status',
                ['active', 'completed']
            )
            ->orderBy('u.email')
            ->select([
                'cm.user_id',
                'u.name',
                'u.email',
                'm.member_number',
                'm.standing',
                'p.id as progress_id',
                'p.status',
                'p.progress_value',
                'p.member_note',
                'p.completed_at',
                'p.updated_at',
            ])
            ->get();

        return response()->json([
            'data' => [
                'goal' => $goal,
                'members' => $rows,
            ],
            'privacy' => [
                'administrator_only_detail' =>
                    true,
                'partner_access' => false,
                'home_address_included' => false,
                'phone_included' => false,
                'documents_included' => false,
                'credential_numbers_included'
                    => false,
            ],
        ]);
    }

    public function adminUpdateProgress(
        Request $request,
        int $goalId,
        string $userId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $goal = DB::table(self::GOALS)
            ->where('id', $goalId)
            ->first();

        abort_unless($goal, 404);

        $membership = DB::table(
            self::COHORT_MEMBERS
        )
            ->where(
                'cohort_id',
                $goal->cohort_id
            )
            ->where('user_id', $userId)
            ->whereIn(
                'status',
                ['active', 'completed']
            )
            ->first();

        abort_unless($membership, 404);

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'not_started',
                    'in_progress',
                    'completed',
                    'waived',
                ]),
            ],
            'progress_value' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999',
            ],
            'member_note' => [
                'nullable',
                'string',
                'max:3000',
            ],
        ]);

        $before = DB::table(self::PROGRESS)
            ->where('goal_id', $goalId)
            ->where('user_id', $userId)
            ->first();

        $values = [
            'status' => $data['status'],
            'progress_value' =>
                $data['progress_value'] ?? null,
            'member_note' =>
                $data['member_note'] ?? null,
            'completed_at' =>
                $data['status'] === 'completed'
                    ? ($before?->completed_at ?: now())
                    : null,
            'updated_at' => now(),
        ];

        if ($before) {
            DB::table(self::PROGRESS)
                ->where('id', $before->id)
                ->update($values);
            $id = (int) $before->id;
        } else {
            $id = DB::table(self::PROGRESS)
                ->insertGetId([
                    'goal_id' => $goalId,
                    'user_id' => $userId,
                    ...$values,
                    'created_at' => now(),
                ]);
        }

        $after = DB::table(self::PROGRESS)
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'enterprise.goal_progress_admin_updated',
            'enterprise_goal_progress',
            (string) $id,
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Enterprise member goal progress updated.',
            'data' => $after,
        ]);
    }

    public function partnerGoals(
        Request $request
    ): JsonResponse {
        $scope = $this->authorizePartner(
            $request
        );

        $orgId =
            (int) $scope['organization']->id;

        $cohorts = DB::table(self::COHORTS)
            ->where(
                'partner_organization_id',
                $orgId
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
                        ['active', 'completed']
                    )
                    ->pluck('user_id')
                    ->all();

                $privacyThreshold = 3;
                $suppressed =
                    count($memberIds)
                    < $privacyThreshold;

                $goals = DB::table(self::GOALS)
                    ->where(
                        'cohort_id',
                        $cohort->id
                    )
                    ->where(
                        'visibility',
                        'members_and_partners'
                    )
                    ->whereIn(
                        'status',
                        ['active', 'completed']
                    )
                    ->orderBy('due_at')
                    ->get()
                    ->map(
                        function ($goal) use (
                            $memberIds,
                            $suppressed
                        ): array {
                            if ($suppressed) {
                                return [
                                    'id' =>
                                        (int) $goal->id,
                                    'title' =>
                                        $goal->title,
                                    'goal_type' =>
                                        $goal->goal_type,
                                    'target_value' =>
                                        $goal->target_value,
                                    'target_unit' =>
                                        $goal->target_unit,
                                    'status' =>
                                        $goal->status,
                                    'due_at' =>
                                        $goal->due_at,
                                    'metrics_suppressed'
                                        => true,
                                ];
                            }

                            $progress = DB::table(
                                self::PROGRESS
                            )
                                ->where(
                                    'goal_id',
                                    $goal->id
                                )
                                ->whereIn(
                                    'user_id',
                                    $memberIds
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

                            $notStarted =
                                (clone $progress)
                                    ->where(
                                        'status',
                                        'not_started'
                                    )
                                    ->count();

                            $waived =
                                (clone $progress)
                                    ->where(
                                        'status',
                                        'waived'
                                    )
                                    ->count();

                            $denominator = max(
                                1,
                                count($memberIds)
                                - $waived
                            );

                            return [
                                'id' =>
                                    (int) $goal->id,
                                'title' =>
                                    $goal->title,
                                'goal_type' =>
                                    $goal->goal_type,
                                'target_value' =>
                                    $goal->target_value,
                                'target_unit' =>
                                    $goal->target_unit,
                                'status' =>
                                    $goal->status,
                                'due_at' =>
                                    $goal->due_at,
                                'metrics_suppressed'
                                    => false,
                                'progress' => [
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
                                            (
                                                $completed
                                                / $denominator
                                            ) * 100,
                                            1
                                        ),
                                ],
                            ];
                        }
                    )
                    ->values();

                return [
                    'cohort_id' =>
                        (int) $cohort->id,
                    'cohort_name' =>
                        $cohort->name,
                    'cohort_code' =>
                        $cohort->code,
                    'member_count' =>
                        count($memberIds),
                    'privacy_threshold' =>
                        $privacyThreshold,
                    'metrics_suppressed' =>
                        $suppressed,
                    'goals' => $goals,
                ];
            }
        )->values();

        return response()->json([
            'data' => [
                'organization' => [
                    'id' => $orgId,
                    'name' =>
                        $scope['organization']->name,
                ],
                'cohorts' => $rows,
            ],
            'privacy' => [
                'aggregate_only' => true,
                'member_identity_included' =>
                    false,
                'member_notes_included' =>
                    false,
                'member_contact_details_included'
                    => false,
                'small_cohort_metrics_suppressed'
                    => true,
                'minimum_aggregate_cohort_size'
                    => 3,
            ],
            'governance' => [
                'self_reported_progress_possible'
                    => true,
                'official_performance_measure'
                    => false,
                'official_cpd_measure' => false,
            ],
        ]);
    }

    private function validateGoal(
        Request $request
    ): array {
        return $request->validate([
            'title' => [
                'required',
                'string',
                'max:190',
            ],
            'description' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'goal_type' => [
                'required',
                Rule::in([
                    'participation',
                    'learning',
                    'engagement',
                    'readiness',
                    'custom',
                ]),
            ],
            'target_value' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999',
            ],
            'target_unit' => [
                'nullable',
                'string',
                'max:80',
            ],
            'status' => [
                'required',
                Rule::in([
                    'planned',
                    'active',
                    'completed',
                    'archived',
                ]),
            ],
            'visibility' => [
                'required',
                Rule::in([
                    'admin_only',
                    'members_only',
                    'members_and_partners',
                ]),
            ],
            'due_at' => [
                'nullable',
                'date',
            ],
        ]);
    }

    private function requireCohort(
        int $cohortId
    ): object {
        $cohort = DB::table(self::COHORTS)
            ->where('id', $cohortId)
            ->first();

        abort_unless($cohort, 404);

        return $cohort;
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
            ->where('active', true)
            ->first();

        abort_unless(
            $access,
            403,
            'NurseLink partner access is required.'
        );

        $organization = DB::table(
            'nurselink_partner_organizations'
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
                ['viewer', 'recruiter', 'manager'],
                true
            ),
            403
        );

        return [
            'role' => $role,
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
                ['admin', 'super_admin'],
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
            'Administrator access is required for enterprise goal management.'
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
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
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
            'created_at' => now(),
        ]);
    }
}
