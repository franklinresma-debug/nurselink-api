<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class MembershipAdministrationController extends Controller
{
    private const ELEVATION_TTL_SECONDS = 28800;

    private const PENDING_STATUSES = [
        'submitted',
        'under_review',
        'needs_information',
        'ready_for_approval',
    ];

    public function overview(
        Request $request
    ): JsonResponse {
        $access = $this->requireElevatedSession(
            $request
        );

        $counts = [];
        foreach ([
            'submitted',
            'under_review',
            'needs_information',
            'ready_for_approval',
            'approved',
            'declined',
        ] as $status) {
            $counts[$status] = DB::table(
                'nurselink_memberships'
            )
                ->where(
                    'status',
                    $status
                )
                ->count();
        }

        $pending = DB::table(
            'nurselink_memberships'
        )
            ->whereIn(
                'status',
                self::PENDING_STATUSES
            );

        $overdue = Schema::hasColumn(
            'nurselink_memberships',
            'review_due_at'
        )
            ? (clone $pending)
                ->whereNotNull(
                    'review_due_at'
                )
                ->where(
                    'review_due_at',
                    '<',
                    now()
                )
                ->count()
            : 0;

        $unassigned = Schema::hasColumn(
            'nurselink_memberships',
            'assigned_reviewer_user_id'
        )
            ? (clone $pending)
                ->whereNull(
                    'assigned_reviewer_user_id'
                )
                ->count()
            : 0;

        $aging = [
            '0_2_days' => 0,
            '3_7_days' => 0,
            '8_14_days' => 0,
            '15_plus_days' => 0,
        ];

        $pendingRows = (clone $pending)
            ->get([
                'id',
                'created_at',
                'updated_at',
            ]);

        foreach ($pendingRows as $row) {
            $source = $row->created_at
                ?: $row->updated_at;

            if (! $source) {
                continue;
            }

            $days = now()
                ->diffInDays(
                    \Carbon\Carbon::parse(
                        $source
                    )
                );

            if ($days <= 2) {
                $aging['0_2_days']++;
            } elseif ($days <= 7) {
                $aging['3_7_days']++;
            } elseif ($days <= 14) {
                $aging['8_14_days']++;
            } else {
                $aging['15_plus_days']++;
            }
        }

        $standing = [
            'active' => 0,
            'suspended' => 0,
            'inactive' => 0,
        ];

        foreach (
            array_keys(
                $standing
            ) as $value
        ) {
            $standing[$value] = DB::table(
                'nurselink_memberships'
            )
                ->where(
                    'status',
                    'approved'
                )
                ->where(
                    'standing',
                    $value
                )
                ->count();
        }

        $staff = $this->staffRows(
            $request
        );

        return response()->json([
            'data' => [
                'counts' =>
                    $counts,
                'pending_total' =>
                    array_sum([
                        $counts['submitted'],
                        $counts['under_review'],
                        $counts['needs_information'],
                        $counts['ready_for_approval'],
                    ]),
                'overdue_reviews' =>
                    $overdue,
                'unassigned_reviews' =>
                    $unassigned,
                'aging' =>
                    $aging,
                'standing' =>
                    $standing,
                'staff' => [
                    'total_privileged' =>
                        count($staff),
                    'reviewers' =>
                        count(array_filter(
                            $staff,
                            fn (array $row): bool =>
                                $row['active']
                                && $row['role']
                                    === 'reviewer'
                        )),
                    'administrators' =>
                        count(array_filter(
                            $staff,
                            fn (array $row): bool =>
                                $row['active']
                                && $row['role']
                                    === 'admin'
                        )),
                    'super_administrators' =>
                        count(array_filter(
                            $staff,
                            fn (array $row): bool =>
                                $row['active']
                                && $row['role']
                                    === 'super_admin'
                        )),
                ],
                'permissions' => [
                    'role' =>
                        $access['role'],
                    'can_review' =>
                        $access['is_reviewer'],
                    'can_final_decide' =>
                        $access['is_admin'],
                    'can_assign_reviews' =>
                        $access['is_admin'],
                    'can_manage_roles' =>
                        $access['is_super_admin'],
                    'can_manage_standing' =>
                        $access['is_admin'],
                ],
                'governance' => [
                    'final_approval_requires_admin'
                        => true,
                    'role_assignment_requires_super_admin'
                        => true,
                    'last_super_admin_protected'
                        => true,
                    'separate_admin_sign_in_required'
                        => true,
                ],
            ],
        ]);
    }

    public function queue(
        Request $request
    ): JsonResponse {
        $access = $this->requireElevatedSession(
            $request
        );

        $data = $request->validate([
            'status' => [
                'nullable',
                Rule::in([
                    'submitted',
                    'under_review',
                    'needs_information',
                    'ready_for_approval',
                    'approved',
                    'declined',
                ]),
            ],
            'stage' => [
                'nullable',
                Rule::in([
                    'initial_intake',
                    'document_review',
                    'applicant_follow_up',
                    'admin_review',
                    'completed',
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
            'assignment' => [
                'nullable',
                Rule::in([
                    'all',
                    'assigned',
                    'unassigned',
                    'mine',
                ]),
            ],
            'overdue' => [
                'nullable',
                'boolean',
            ],
            'search' => [
                'nullable',
                'string',
                'max:190',
            ],
            'organization' => [
                'nullable',
                'string',
                'max:190',
            ],
        ]);

        $query = DB::table(
            'nurselink_memberships as m'
        );

        $stageStatus =
            match (
                (string) (
                    $data[
                        'stage'
                    ]
                    ?? ''
                )
            ) {
                'initial_intake' =>
                    'submitted',
                'document_review' =>
                    'under_review',
                'applicant_follow_up' =>
                    'needs_information',
                'admin_review' =>
                    'ready_for_approval',
                'completed' =>
                    'approved',
                'closed' =>
                    'declined',
                default =>
                    null,
            };

        if (
            ! empty(
                $data['status']
            )
        ) {
            $query->where(
                'm.status',
                $data['status']
            );
        } elseif (
            $stageStatus
        ) {
            $query->where(
                'm.status',
                $stageStatus
            );
        } else {
            $query->whereIn(
                'm.status',
                self::PENDING_STATUSES
            );
        }

        if (
            ! empty(
                $data['priority']
            )
            && Schema::hasColumn(
                'nurselink_memberships',
                'review_priority'
            )
        ) {
            $query->where(
                'm.review_priority',
                $data['priority']
            );
        }

        $assignment =
            $data['assignment']
            ?? 'all';

        if (
            Schema::hasColumn(
                'nurselink_memberships',
                'assigned_reviewer_user_id'
            )
        ) {
            if (
                $assignment
                === 'assigned'
            ) {
                $query->whereNotNull(
                    'm.assigned_reviewer_user_id'
                );
            } elseif (
                $assignment
                === 'unassigned'
            ) {
                $query->whereNull(
                    'm.assigned_reviewer_user_id'
                );
            } elseif (
                $assignment
                === 'mine'
            ) {
                $query->where(
                    'm.assigned_reviewer_user_id',
                    (string)
                        $request->user()->getKey()
                );
            }
        }

        if (
            ($data['overdue'] ?? false)
            && Schema::hasColumn(
                'nurselink_memberships',
                'review_due_at'
            )
        ) {
            $query
                ->whereNotNull(
                    'm.review_due_at'
                )
                ->where(
                    'm.review_due_at',
                    '<',
                    now()
                );
        }

        $rows = $query
            ->orderByRaw(
                "CASE COALESCE(m.review_priority, 'normal')
                    WHEN 'urgent' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'normal' THEN 3
                    WHEN 'low' THEN 4
                    ELSE 5 END"
            )
            ->orderByRaw(
                "CASE m.status
                    WHEN 'ready_for_approval' THEN 1
                    WHEN 'submitted' THEN 2
                    WHEN 'under_review' THEN 3
                    WHEN 'needs_information' THEN 4
                    ELSE 5 END"
            )
            ->orderBy(
                'm.created_at'
            )
            ->limit(750)
            ->get();

        $users = $this->userMap(
            $rows->pluck(
                'user_id'
            )->all()
        );

        $reviewerIds = $rows
            ->pluck(
                'assigned_reviewer_user_id'
            )
            ->filter()
            ->all();

        $reviewers = $this->userMap(
            $reviewerIds
        );

        $latestEmployment = [];

        if (
            Schema::hasTable(
                'nurselink_employment_histories'
            )
        ) {
            $employmentRows = DB::table(
                'nurselink_employment_histories'
            )
                ->whereIn(
                    'user_id',
                    $rows
                        ->pluck('user_id')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all()
                )
                ->orderByDesc(
                    'is_current'
                )
                ->orderByDesc(
                    'start_date'
                )
                ->orderByDesc(
                    'id'
                )
                ->get([
                    'id',
                    'user_id',
                    'employer_name',
                    'city',
                    'country',
                    'position',
                    'is_current',
                    'start_date',
                ]);

            foreach (
                $employmentRows
                as $employment
            ) {
                $employmentUserId =
                    (string)
                        $employment
                            ->user_id;

                if (
                    isset(
                        $latestEmployment[
                            $employmentUserId
                        ]
                    )
                ) {
                    continue;
                }

                $latestEmployment[
                    $employmentUserId
                ] = [
                    'employer_name' =>
                        $employment
                            ->employer_name,
                    'city' =>
                        $employment
                            ->city,
                    'country' =>
                        $employment
                            ->country,
                    'position' =>
                        $employment
                            ->position,
                    'is_current' =>
                        (bool)
                            $employment
                                ->is_current,
                ];
            }
        }

        $search = strtolower(
            trim(
                (string)
                    ($data['search'] ?? '')
            )
        );

        $organization = strtolower(
            trim(
                (string)
                    ($data['organization'] ?? '')
            )
        );

        $presented = $rows->map(
            function ($row) use (
                $users,
                $reviewers,
                $latestEmployment,
                $request,
                $access
            ): array {
                $userId =
                    (string) $row->user_id;

                $user =
                    $users[$userId]
                    ?? [];

                $assignedId =
                    (string) (
                        $row
                            ->assigned_reviewer_user_id
                        ?? ''
                    );

                $assigned =
                    $assignedId !== ''
                        ? (
                            $reviewers[$assignedId]
                            ?? null
                        )
                        : null;

                $created =
                    $row->created_at
                    ?: $row->updated_at;

                $ageDays = $created
                    ? now()->diffInDays(
                        $created
                    )
                    : null;

                $overdue =
                    ! empty(
                        $row->review_due_at
                    )
                    && now()->greaterThan(
                        \Carbon\Carbon::parse(
                            $row->review_due_at
                        )
                    )
                    && in_array(
                        $row->status,
                        self::PENDING_STATUSES,
                        true
                    );

                return [
                    'membership_id' =>
                        (int) $row->id,
                    'user_id' =>
                        $userId,
                    'name' =>
                        $user['name']
                        ?? $userId,
                    'email' =>
                        $user['email']
                        ?? '',
                    'status' =>
                        $row->status,
                    'member_number' =>
                        $row->member_number,
                    'review_priority' =>
                        $row->review_priority
                        ?? 'normal',
                    'review_due_at' =>
                        $row->review_due_at
                        ?? null,
                    'review_started_at' =>
                        $row->review_started_at
                        ?? null,
                    'last_admin_action_at' =>
                        $row->last_admin_action_at
                        ?? null,
                    'age_days' =>
                        $ageDays,
                    'overdue' =>
                        $overdue,
                    'assigned_reviewer_user_id' =>
                        $assignedId !== ''
                            ? $assignedId
                            : null,
                    'assigned_reviewer' =>
                        $assigned,
                    'is_assigned_to_me' =>
                        $assignedId !== ''
                        && hash_equals(
                            $assignedId,
                            (string)
                                $request
                                    ->user()
                                    ->getKey()
                        ),
                    'application_reference' =>
                        'APP-'
                        . (
                            $created
                                ? \Carbon\Carbon::parse(
                                    $created
                                )->format(
                                    'Y'
                                )
                                : now()->format(
                                    'Y'
                                )
                        )
                        . '-'
                        . str_pad(
                            (string)
                                $row->id,
                            6,
                            '0',
                            STR_PAD_LEFT
                        ),
                    'submitted_at' =>
                        $created,
                    'review_stage' =>
                        $this->reviewStage(
                            (string)
                                $row->status
                        ),
                    'latest_employment' =>
                        $latestEmployment[
                            $userId
                        ]
                        ?? null,
                    'can_assign' =>
                        $access['is_admin'],
                    'can_final_decide' =>
                        $access['is_admin'],
                ];
            }
        );

        if (
            $search !== ''
        ) {
            $presented = $presented
                ->filter(
                    function (
                        array $row
                    ) use ($search): bool {
                        $haystack =
                            strtolower(
                                ($row['name'] ?? '')
                                . ' '
                                . ($row['email'] ?? '')
                                . ' '
                                . ($row[
                                    'member_number'
                                ] ?? '')
                                . ' '
                                . ($row[
                                    'application_reference'
                                ] ?? '')
                                . ' '
                                . (
                                    $row[
                                        'latest_employment'
                                    ][
                                        'employer_name'
                                    ]
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

        if (
            $organization !== ''
        ) {
            $presented = $presented
                ->filter(
                    function (
                        array $row
                    ) use ($organization): bool {
                        $employer = strtolower(
                            (string) (
                                $row[
                                    'latest_employment'
                                ][
                                    'employer_name'
                                ]
                                ?? ''
                            )
                        );

                        return $employer !== ''
                            && str_contains(
                                $employer,
                                $organization
                            );
                    }
                );
        }

        return response()->json([
            'data' =>
                $presented->values(),
            'permissions' => [
                'role' =>
                    $access['role'],
                'can_assign_reviews' =>
                    $access['is_admin'],
                'can_final_decide' =>
                    $access['is_admin'],
            ],
        ]);
    }

    public function staff(
        Request $request
    ): JsonResponse {
        $access = $this->requireElevatedSession(
            $request
        );

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required to view privileged staff.'
        );

        return response()->json([
            'data' =>
                $this->staffRows(
                    $request
                ),
            'permissions' => [
                'can_manage_roles' =>
                    $access['is_super_admin'],
                'can_assign_reviews' =>
                    $access['is_admin'],
                'cannot_revoke_self' =>
                    true,
                'protect_last_super_admin' =>
                    true,
            ],
        ]);
    }

    public function assignReview(
        Request $request,
        int $membershipId
    ): JsonResponse {
        $access = $this->requireElevatedSession(
            $request
        );

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required to assign membership reviews.'
        );

        $data = $request->validate([
            'reviewer_user_id' => [
                'nullable',
                'string',
                'max:191',
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
            'review_due_at' => [
                'nullable',
                'date',
            ],
        ]);

        $before = DB::table(
            'nurselink_memberships'
        )
            ->where(
                'id',
                $membershipId
            )
            ->first();

        abort_unless(
            $before,
            404
        );

        abort_unless(
            in_array(
                $before->status,
                self::PENDING_STATUSES,
                true
            ),
            422,
            'Only pending membership applications can be assigned for review.'
        );

        $reviewerId =
            trim(
                (string)
                    ($data[
                        'reviewer_user_id'
                    ] ?? '')
            );

        if (
            $reviewerId !== ''
        ) {
            abort_unless(
                $this->isActivePrivilegedReviewer(
                    $reviewerId
                ),
                422,
                'Assigned reviewer must have active Reviewer, Administrator or Super Administrator access.'
            );
        }

        $updates = [
            'assigned_reviewer_user_id' =>
                $reviewerId !== ''
                    ? $reviewerId
                    : null,
            'review_priority' =>
                $data['priority'],
            'review_due_at' =>
                $data['review_due_at']
                ?? null,
            'last_admin_action_at' =>
                now(),
            'updated_at' =>
                now(),
        ];

        DB::table(
            'nurselink_memberships'
        )
            ->where(
                'id',
                $membershipId
            )
            ->update(
                $updates
            );

        $after = DB::table(
            'nurselink_memberships'
        )
            ->where(
                'id',
                $membershipId
            )
            ->first();

        $this->audit(
            $request,
            'membership.review_assignment_changed',
            'membership',
            (string) $membershipId,
            $before,
            $after
        );

        if (
            $reviewerId !== ''
        ) {
            $this->notifyAssignedReviewer(
                $reviewerId,
                $after
            );
        }

        return response()->json([
            'message' =>
                $reviewerId !== ''
                    ? 'Membership review assignment saved.'
                    : 'Membership review assignment cleared.',
            'data' => [
                'membership_id' =>
                    (int) $membershipId,
                'assigned_reviewer_user_id' =>
                    $reviewerId !== ''
                        ? $reviewerId
                        : null,
                'review_priority' =>
                    $after->review_priority
                    ?? 'normal',
                'review_due_at' =>
                    $after->review_due_at
                    ?? null,
            ],
        ]);
    }

    public function export(
        Request $request
    ) {
        $access = $this->requireElevatedSession(
            $request
        );

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required to export membership applications.'
        );

        $queueResponse =
            $this->queue(
                $request
            );

        $payload =
            $queueResponse
                ->getData(
                    true
                );

        $rows =
            $payload[
                'data'
            ]
            ?? [];

        $filters =
            $request->only([
                'status',
                'stage',
                'priority',
                'assignment',
                'overdue',
                'search',
                'organization',
            ]);

        $this->audit(
            $request,
            'membership.application_queue_exported',
            'membership_export',
            now()->format(
                'YmdHis'
            ),
            null,
            [
                'row_count' =>
                    count(
                        $rows
                    ),
                'filters' =>
                    $filters,
            ]
        );

        $filename =
            'nurselink-membership-applications-'
            . now()->format(
                'Ymd-His'
            )
            . '.csv';

        return response()->streamDownload(
            function () use (
                $rows
            ): void {
                $handle =
                    fopen(
                        'php://output',
                        'w'
                    );

                if (
                    $handle === false
                ) {
                    return;
                }

                fputcsv(
                    $handle,
                    [
                        'Application ID',
                        'Applicant',
                        'Email',
                        'Organization',
                        'Submitted',
                        'Status',
                        'Review Stage',
                        'Priority',
                        'Assigned Reviewer',
                        'Review Due',
                        'Age Days',
                    ]
                );

                foreach (
                    $rows
                    as $row
                ) {
                    $employment =
                        $row[
                            'latest_employment'
                        ]
                        ?? [];

                    $reviewer =
                        $row[
                            'assigned_reviewer'
                        ]
                        ?? [];

                    $stage =
                        $row[
                            'review_stage'
                        ]
                        ?? [];

                    fputcsv(
                        $handle,
                        [
                            $this->csvCell(
                                $row[
                                    'application_reference'
                                ]
                                ?? ''
                            ),
                            $this->csvCell(
                                $row[
                                    'name'
                                ]
                                ?? ''
                            ),
                            $this->csvCell(
                                $row[
                                    'email'
                                ]
                                ?? ''
                            ),
                            $this->csvCell(
                                $employment[
                                    'employer_name'
                                ]
                                ?? ''
                            ),
                            $this->csvCell(
                                $row[
                                    'submitted_at'
                                ]
                                ?? ''
                            ),
                            $this->csvCell(
                                $row[
                                    'status'
                                ]
                                ?? ''
                            ),
                            $this->csvCell(
                                $stage[
                                    'label'
                                ]
                                ?? ''
                            ),
                            $this->csvCell(
                                $row[
                                    'review_priority'
                                ]
                                ?? 'normal'
                            ),
                            $this->csvCell(
                                $reviewer[
                                    'name'
                                ]
                                ?? ''
                            ),
                            $this->csvCell(
                                $row[
                                    'review_due_at'
                                ]
                                ?? ''
                            ),
                            (int) (
                                $row[
                                    'age_days'
                                ]
                                ?? 0
                            ),
                        ]
                    );
                }

                fclose(
                    $handle
                );
            },
            $filename,
            [
                'Content-Type' =>
                    'text/csv; charset=UTF-8',
                'Cache-Control' =>
                    'no-store, private',
                'X-Content-Type-Options' =>
                    'nosniff',
            ]
        );
    }

    public function activity(
        Request $request
    ): JsonResponse {
        $access = $this->requireElevatedSession(
            $request
        );

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required to view administrative activity.'
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
            ->whereIn(
                'target_type',
                [
                    'membership',
                    'staff_access',
                ]
            )
            ->orderByDesc(
                'id'
            )
            ->limit(150)
            ->get();

        $reviewers = $this->userMap(
            $rows
                ->pluck(
                    'reviewer_user_id'
                )
                ->all()
        );

        return response()->json([
            'data' =>
                $rows->map(
                    function (
                        $row
                    ) use (
                        $reviewers
                    ): array {
                        $reviewer =
                            $reviewers[
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
                            'reviewer_user_id' =>
                                (string)
                                    $row
                                        ->reviewer_user_id,
                            'reviewer_name' =>
                                $reviewer['name']
                                ?? null,
                            'reviewer_email' =>
                                $reviewer['email']
                                ?? null,
                            'created_at' =>
                                $row->created_at,
                        ];
                    }
                )->values(),
        ]);
    }

    private function workloadLevel(
        int $score
    ): string {
        if (
            $score <= 4
        ) {
            return 'light';
        }

        if (
            $score <= 10
        ) {
            return 'balanced';
        }

        if (
            $score <= 18
        ) {
            return 'high';
        }

        return 'heavy';
    }

    private function csvCell(
        $value
    ): string {
        $text =
            trim(
                (string) $value
            );

        if (
            $text !== ''
            && in_array(
                $text[0],
                [
                    '=',
                    '+',
                    '-',
                    '@',
                ],
                true
            )
        ) {
            return "'"
                . $text;
        }

        return $text;
    }

    private function reviewStage(
        string $status
    ): array {
        return match ($status) {
            'submitted' => [
                'key' => 'initial_intake',
                'label' => 'Initial Intake',
                'step' => 1,
                'steps_total' => 4,
            ],
            'under_review' => [
                'key' => 'document_review',
                'label' => 'Document Review',
                'step' => 2,
                'steps_total' => 4,
            ],
            'needs_information' => [
                'key' => 'applicant_follow_up',
                'label' => 'Applicant Follow-up',
                'step' => 2,
                'steps_total' => 4,
            ],
            'ready_for_approval' => [
                'key' => 'admin_review',
                'label' => 'Admin Review',
                'step' => 4,
                'steps_total' => 4,
            ],
            'approved' => [
                'key' => 'completed',
                'label' => 'Completed',
                'step' => 4,
                'steps_total' => 4,
            ],
            'declined' => [
                'key' => 'closed',
                'label' => 'Closed',
                'step' => 4,
                'steps_total' => 4,
            ],
            default => [
                'key' => 'review',
                'label' => 'Review',
                'step' => null,
                'steps_total' => 4,
            ],
        };
    }

    private function staffRows(
        Request $request
    ): array {
        $ids = collect();

        if (
            Schema::hasTable(
                'nurselink_reviewer_access'
            )
        ) {
            $ids = $ids->merge(
                DB::table(
                    'nurselink_reviewer_access'
                )
                    ->pluck(
                        'user_id'
                    )
                    ->map(
                        fn ($value): string =>
                            (string) $value
                    )
            );
        }

        if (
            Schema::hasTable(
                'nurselink_super_admin_access'
            )
        ) {
            $ids = $ids->merge(
                DB::table(
                    'nurselink_super_admin_access'
                )
                    ->pluck(
                        'user_id'
                    )
                    ->map(
                        fn ($value): string =>
                            (string) $value
                    )
            );
        }

        $ids = $ids
            ->unique()
            ->values();

        if (
            $ids->isEmpty()
        ) {
            return [];
        }

        $users =
            $this->userMap(
                $ids->all()
            );

        $reviewAccess =
            Schema::hasTable(
                'nurselink_reviewer_access'
            )
                ? DB::table(
                    'nurselink_reviewer_access'
                )
                    ->whereIn(
                        'user_id',
                        $ids->all()
                    )
                    ->get()
                    ->keyBy(
                        fn ($row): string =>
                            (string) $row->user_id
                    )
                : collect();

        $superAccess =
            Schema::hasTable(
                'nurselink_super_admin_access'
            )
                ? DB::table(
                    'nurselink_super_admin_access'
                )
                    ->whereIn(
                        'user_id',
                        $ids->all()
                    )
                    ->get()
                    ->keyBy(
                        fn ($row): string =>
                            (string) $row->user_id
                    )
                : collect();

        $workloads = [];

        if (
            Schema::hasColumn(
                'nurselink_memberships',
                'assigned_reviewer_user_id'
            )
        ) {
            $workloadColumns = [
                'assigned_reviewer_user_id',
                'status',
            ];

            if (
                Schema::hasColumn(
                    'nurselink_memberships',
                    'review_priority'
                )
            ) {
                $workloadColumns[] =
                    'review_priority';
            }

            if (
                Schema::hasColumn(
                    'nurselink_memberships',
                    'review_due_at'
                )
            ) {
                $workloadColumns[] =
                    'review_due_at';
            }

            $assignedRows = DB::table(
                'nurselink_memberships'
            )
                ->whereIn(
                    'status',
                    self::PENDING_STATUSES
                )
                ->whereNotNull(
                    'assigned_reviewer_user_id'
                )
                ->get(
                    $workloadColumns
                );

            foreach (
                $assignedRows
                as $assigned
            ) {
                $reviewerId =
                    (string)
                        $assigned
                            ->assigned_reviewer_user_id;

                if (
                    $reviewerId === ''
                ) {
                    continue;
                }

                if (
                    ! isset(
                        $workloads[
                            $reviewerId
                        ]
                    )
                ) {
                    $workloads[
                        $reviewerId
                    ] = [
                        'total' => 0,
                        'urgent' => 0,
                        'high' => 0,
                        'overdue' => 0,
                        'ready_for_approval' => 0,
                    ];
                }

                $workloads[
                    $reviewerId
                ][
                    'total'
                ]++;

                $priority = strtolower(
                    (string) (
                        $assigned
                            ->review_priority
                        ?? 'normal'
                    )
                );

                if (
                    in_array(
                        $priority,
                        [
                            'urgent',
                            'high',
                        ],
                        true
                    )
                ) {
                    $workloads[
                        $reviewerId
                    ][
                        $priority
                    ]++;
                }

                if (
                    (string) (
                        $assigned
                            ->status
                        ?? ''
                    )
                    === 'ready_for_approval'
                ) {
                    $workloads[
                        $reviewerId
                    ][
                        'ready_for_approval'
                    ]++;
                }

                $reviewDueAt =
                    $assigned
                        ->review_due_at
                    ?? null;

                if (
                    $reviewDueAt
                    && \Carbon\Carbon::parse(
                        $reviewDueAt
                    )->isPast()
                ) {
                    $workloads[
                        $reviewerId
                    ][
                        'overdue'
                    ]++;
                }
            }
        }

        return $ids->map(
            function (
                $id
            ) use (
                $users,
                $reviewAccess,
                $superAccess,
                $workloads,
                $request
            ): array {
                $key =
                    (string) $id;

                $review =
                    $reviewAccess->get(
                        $key
                    );

                $super =
                    $superAccess->get(
                        $key
                    );

                $superActive =
                    (bool) (
                        $super->active
                        ?? false
                    );

                $reviewActive =
                    (bool) (
                        $review->active
                        ?? false
                    );

                $role = $superActive
                    ? 'super_admin'
                    : (
                        $reviewActive
                            ? (
                                (string)
                                    $review->role
                            )
                            : 'revoked'
                    );

                $user =
                    $users[$key]
                    ?? [];

                return [
                    'user_id' =>
                        $key,
                    'name' =>
                        $user['name']
                        ?? $key,
                    'email' =>
                        $user['email']
                        ?? '',
                    'role' =>
                        $role,
                    'role_label' =>
                        match ($role) {
                            'super_admin' =>
                                'Super Administrator',
                            'admin' =>
                                'Administrator',
                            'reviewer' =>
                                'Reviewer',
                            default =>
                                'Revoked',
                        },
                    'active' =>
                        $superActive
                        || $reviewActive,
                    'pending_workload' =>
                        (int) (
                            $workloads[
                                $key
                            ][
                                'total'
                            ]
                            ?? 0
                        ),
                    'urgent_workload' =>
                        (int) (
                            $workloads[
                                $key
                            ][
                                'urgent'
                            ]
                            ?? 0
                        ),
                    'high_workload' =>
                        (int) (
                            $workloads[
                                $key
                            ][
                                'high'
                            ]
                            ?? 0
                        ),
                    'overdue_workload' =>
                        (int) (
                            $workloads[
                                $key
                            ][
                                'overdue'
                            ]
                            ?? 0
                        ),
                    'ready_for_approval_workload' =>
                        (int) (
                            $workloads[
                                $key
                            ][
                                'ready_for_approval'
                            ]
                            ?? 0
                        ),
                    'workload_score' =>
                        (
                            (int) (
                                $workloads[
                                    $key
                                ][
                                    'total'
                                ]
                                ?? 0
                            )
                            + (
                                3
                                * (int) (
                                    $workloads[
                                        $key
                                    ][
                                        'urgent'
                                    ]
                                    ?? 0
                                )
                            )
                            + (
                                2
                                * (int) (
                                    $workloads[
                                        $key
                                    ][
                                        'high'
                                    ]
                                    ?? 0
                                )
                            )
                            + (
                                2
                                * (int) (
                                    $workloads[
                                        $key
                                    ][
                                        'overdue'
                                    ]
                                    ?? 0
                                )
                            )
                        ),
                    'workload_level' =>
                        $this->workloadLevel(
                            (
                                (int) (
                                    $workloads[
                                        $key
                                    ][
                                        'total'
                                    ]
                                    ?? 0
                                )
                                + (
                                    3
                                    * (int) (
                                        $workloads[
                                            $key
                                        ][
                                            'urgent'
                                        ]
                                        ?? 0
                                    )
                                )
                                + (
                                    2
                                    * (int) (
                                        $workloads[
                                            $key
                                        ][
                                            'high'
                                        ]
                                        ?? 0
                                    )
                                )
                                + (
                                    2
                                    * (int) (
                                        $workloads[
                                            $key
                                        ][
                                            'overdue'
                                        ]
                                        ?? 0
                                    )
                                )
                            )
                        ),
                    'is_current_user' =>
                        hash_equals(
                            $key,
                            (string)
                                $request
                                    ->user()
                                    ->getKey()
                        ),
                ];
            }
        )
            ->sortBy(
                fn (
                    array $row
                ): string =>
                    (
                        $row['active']
                            ? '0'
                            : '1'
                    )
                    . '|'
                    . strtolower(
                        $row['email']
                    )
            )
            ->values()
            ->all();
    }

    private function isActivePrivilegedReviewer(
        string $userId
    ): bool {
        $reviewActive =
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
                        'reviewer',
                        'admin',
                        'super_admin',
                    ]
                )
                ->exists();

        $superActive =
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

        return $reviewActive
            || $superActive;
    }

    private function notifyAssignedReviewer(
        string $reviewerUserId,
        object $membership
    ): void {
        if (
            ! Schema::hasTable(
                'nurselink_notifications'
            )
        ) {
            return;
        }

        $applicant =
            $this->userMap([
                (string) $membership
                    ->user_id,
            ])[
                (string) $membership
                    ->user_id
            ] ?? null;

        DB::table(
            'nurselink_notifications'
        )->insert([
            'user_id' =>
                $reviewerUserId,
            'type' =>
                'membership.review.assigned',
            'severity' =>
                'info',
            'title' =>
                'Membership review assigned',
            'message' =>
                'A NurseLink membership review was assigned to you for '
                . (
                    $applicant['name']
                    ?? 'an applicant'
                )
                . '.',
            'action_url' =>
                '/nurselink-membership-administration.html',
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);
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

        $sessionUserId =
            (string)
                $request->session()->get(
                    'nurselink_admin_elevated_user_id',
                    ''
                );

        $elevatedAt =
            (int)
                $request->session()->get(
                    'nurselink_admin_elevated_at',
                    0
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
                (string)
                    $user->getKey()
            )
            && $elevatedAt > 0
            && $expiresAt >= time()
            && (
                time()
                - $elevatedAt
            )
                <= self::ELEVATION_TTL_SECONDS,
            403,
            'A separate NurseLink administrator sign-in is required.'
        );

        $reviewerAccess =
            Schema::hasTable(
                'nurselink_reviewer_access'
            )
                ? DB::table(
                    'nurselink_reviewer_access'
                )
                    ->where(
                        'user_id',
                        $user->getKey()
                    )
                    ->where(
                        'active',
                        true
                    )
                    ->first()
                : null;

        $explicitSuperAdmin =
            Schema::hasTable(
                'nurselink_super_admin_access'
            )
            && DB::table(
                'nurselink_super_admin_access'
            )
                ->where(
                    'user_id',
                    $user->getKey()
                )
                ->where(
                    'active',
                    true
                )
                ->exists();

        $modelRole =
            strtolower(
                trim(
                    (string) (
                        $user->role
                        ?? $user->user_role
                        ?? $user->user_type
                        ?? ''
                    )
                )
            );

        $reviewRole =
            strtolower(
                (string) (
                    $reviewerAccess->role
                    ?? ''
                )
            );

        $isSuperAdmin =
            $explicitSuperAdmin
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
                    'superadministrator',
                ],
                true
            )
            || $reviewRole
                === 'super_admin';

        $isAdmin =
            $isSuperAdmin
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
            )
            || in_array(
                $reviewRole,
                [
                    'admin',
                    'super_admin',
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

        $role = match (true) {
            $isSuperAdmin =>
                'super_admin',
            $isAdmin =>
                'admin',
            $isReviewer =>
                'reviewer',
            default =>
                'user',
        };

        return [
            'role' =>
                $role,
            'label' =>
                match ($role) {
                    'super_admin' =>
                        'Super Administrator',
                    'admin' =>
                        'Administrator',
                    'reviewer' =>
                        'Reviewer',
                    default =>
                        'User',
                },
            'is_super_admin' =>
                $isSuperAdmin,
            'is_admin' =>
                $isAdmin,
            'is_reviewer' =>
                $isReviewer,
        ];
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

        foreach ([
            'email',
            'name',
            'first_name',
            'last_name',
        ] as $column) {
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

            if (
                $name === ''
            ) {
                $name =
                    (string) (
                        $row->email
                        ?? $row->id
                    );
            }

            $map[
                (string) $row->id
            ] = [
                'id' =>
                    (string) $row->id,
                'name' =>
                    $name,
                'email' =>
                    (string) (
                        $row->email
                        ?? ''
                    ),
            ];
        }

        return $map;
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
                $before
                    ? json_encode(
                        $before,
                        JSON_UNESCAPED_UNICODE
                    )
                    : null,
            'after_state' =>
                $after
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
