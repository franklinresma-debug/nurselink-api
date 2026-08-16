<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class MembershipOnboardingController extends Controller
{
    private const TABLE = 'nurselink_membership_onboarding';

    public function memberIndex(Request $request): JsonResponse
    {
        $userId = (string) $request->user()->getKey();
        $membership = $this->approvedMembership($userId);

        abort_unless(
            $membership,
            403,
            'Approved NurseLink membership is required for onboarding.'
        );

        $row = $this->ensureOnboarding(
            (int) $membership->id,
            $userId
        );

        $signals = $this->memberSignals(
            $userId,
            $membership
        );

        $computedStatus = $this->computedStatus(
            $row,
            $signals
        );

        if (
            $computedStatus !== (string) $row->status
            && ! in_array(
                (string) $row->status,
                ['completed', 'paused'],
                true
            )
        ) {
            DB::table(self::TABLE)
                ->where('id', $row->id)
                ->update([
                    'status' => $computedStatus,
                    'completed_at' =>
                        $computedStatus === 'completed'
                            ? now()
                            : null,
                    'updated_at' => now(),
                ]);

            $row = DB::table(self::TABLE)
                ->where('id', $row->id)
                ->first();
        }

        return response()->json([
            'data' => [
                'membership' => [
                    'id' => (int) $membership->id,
                    'member_number' => $membership->member_number,
                    'status' => $membership->status,
                    'standing' => $membership->standing,
                    'approved_at' => $membership->approved_at ?? null,
                ],
                'onboarding' => [
                    'id' => (int) $row->id,
                    'status' => $row->status,
                    'due_at' => $row->due_at,
                    'welcome_viewed_at' => $row->welcome_viewed_at,
                    'orientation_started_at' => $row->orientation_started_at,
                    'orientation_completed_at' => $row->orientation_completed_at,
                    'completed_at' => $row->completed_at,
                    'last_member_activity_at' => $row->last_member_activity_at,
                ],
                'signals' => $signals,
                'recommended_actions' =>
                    $this->recommendedActions(
                        $row,
                        $signals
                    ),
            ],
            'privacy' => [
                'administrator_note_included' => false,
                'assigned_admin_identity_included' => false,
                'other_member_data_included' => false,
            ],
            'governance' => [
                'onboarding_completion_is_official_credential' => false,
                'onboarding_completion_is_licensure' => false,
                'onboarding_completion_is_regulatory_status' => false,
                'message' =>
                    'NurseLink onboarding is an internal membership activation workflow. It is not a professional license, government credential, regulatory decision, or official CPD record.',
            ],
        ]);
    }

    public function memberMark(
        Request $request
    ): JsonResponse {
        $userId = (string) $request->user()->getKey();
        $membership = $this->approvedMembership($userId);

        abort_unless(
            $membership,
            403,
            'Approved NurseLink membership is required for onboarding.'
        );

        $data = $request->validate([
            'action' => [
                'required',
                Rule::in([
                    'welcome_viewed',
                    'orientation_started',
                    'orientation_completed',
                ]),
            ],
        ]);

        $row = $this->ensureOnboarding(
            (int) $membership->id,
            $userId
        );

        $updates = [
            'last_member_activity_at' => now(),
            'updated_at' => now(),
        ];

        if (
            $data['action'] === 'welcome_viewed'
            && empty($row->welcome_viewed_at)
        ) {
            $updates['welcome_viewed_at'] = now();
        }

        if (
            in_array(
                $data['action'],
                [
                    'orientation_started',
                    'orientation_completed',
                ],
                true
            )
            && empty($row->orientation_started_at)
        ) {
            $updates['orientation_started_at'] = now();
        }

        if (
            $data['action'] === 'orientation_completed'
            && empty($row->orientation_completed_at)
        ) {
            $updates['orientation_completed_at'] = now();
        }

        DB::table(self::TABLE)
            ->where('id', $row->id)
            ->update($updates);

        $after = DB::table(self::TABLE)
            ->where('id', $row->id)
            ->first();

        $signals = $this->memberSignals(
            $userId,
            $membership
        );

        $status = $this->computedStatus(
            $after,
            $signals
        );

        DB::table(self::TABLE)
            ->where('id', $row->id)
            ->update([
                'status' => $status,
                'completed_at' =>
                    $status === 'completed'
                        ? ($after->completed_at ?: now())
                        : null,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Membership onboarding progress updated.',
            'data' => [
                'action' => $data['action'],
                'status' => $status,
            ],
        ]);
    }

    public function adminSummary(
        Request $request
    ): JsonResponse {
        $access = $this->requireAdministratorSession(
            $request
        );

        $this->seedApprovedMemberships();

        $counts = [];
        foreach (
            [
                'pending',
                'in_progress',
                'completed',
                'paused',
            ] as $status
        ) {
            $counts[$status] = DB::table(self::TABLE)
                ->where('status', $status)
                ->count();
        }

        $overdue = DB::table(self::TABLE)
            ->whereIn(
                'status',
                ['pending', 'in_progress']
            )
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();

        $unassigned = DB::table(self::TABLE)
            ->whereIn(
                'status',
                ['pending', 'in_progress']
            )
            ->whereNull('assigned_admin_user_id')
            ->count();

        return response()->json([
            'data' => [
                'counts' => $counts,
                'overdue' => $overdue,
                'unassigned' => $unassigned,
                'permissions' => [
                    'role' => $access['role'],
                    'can_manage_onboarding' => $access['is_admin'],
                ],
            ],
        ]);
    }

    public function adminQueue(
        Request $request
    ): JsonResponse {
        $access = $this->requireAdministratorSession(
            $request
        );

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required for membership onboarding management.'
        );

        $this->seedApprovedMemberships();

        $data = $request->validate([
            'status' => [
                'nullable',
                Rule::in([
                    'pending',
                    'in_progress',
                    'completed',
                    'paused',
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
            'overdue' => [
                'nullable',
                'boolean',
            ],
        ]);

        $query = DB::table(
            self::TABLE . ' as o'
        )
            ->join(
                'nurselink_memberships as m',
                'm.id',
                '=',
                'o.membership_id'
            )
            ->join(
                'users as u',
                'u.id',
                '=',
                'o.user_id'
            )
            ->where(
                'm.status',
                'approved'
            );

        if (
            ! empty(
                $data['status']
            )
        ) {
            $query->where(
                'o.status',
                $data['status']
            );
        }

        $assignment =
            $data['assignment']
            ?? 'all';

        if (
            $assignment === 'mine'
        ) {
            $query->where(
                'o.assigned_admin_user_id',
                (string)
                    $request->user()->getKey()
            );
        } elseif (
            $assignment === 'assigned'
        ) {
            $query->whereNotNull(
                'o.assigned_admin_user_id'
            );
        } elseif (
            $assignment === 'unassigned'
        ) {
            $query->whereNull(
                'o.assigned_admin_user_id'
            );
        }

        if (
            ($data['overdue'] ?? false)
        ) {
            $query
                ->whereIn(
                    'o.status',
                    ['pending', 'in_progress']
                )
                ->whereNotNull('o.due_at')
                ->where('o.due_at', '<', now());
        }

        $rows = $query
            ->orderByRaw(
                "CASE o.status
                    WHEN 'in_progress' THEN 1
                    WHEN 'pending' THEN 2
                    WHEN 'paused' THEN 3
                    WHEN 'completed' THEN 4
                    ELSE 5 END"
            )
            ->orderByRaw(
                'CASE WHEN o.due_at IS NULL THEN 1 ELSE 0 END'
            )
            ->orderBy('o.due_at')
            ->limit(750)
            ->get([
                'o.*',
                'm.member_number',
                'm.standing',
                'm.approved_at',
                'u.name',
                'u.email',
            ]);

        $adminMap = $this->userMap(
            $rows
                ->pluck(
                    'assigned_admin_user_id'
                )
                ->filter()
                ->all()
        );

        $search = strtolower(
            trim(
                (string)
                    ($data['search'] ?? '')
            )
        );

        $presented = $rows
            ->map(
                function (
                    $row
                ) use (
                    $adminMap
                ): array {
                    $signals =
                        $this->memberSignalsByIds(
                            (string) $row->user_id,
                            (int) $row->membership_id,
                            (string) $row->member_number,
                            (string) $row->standing
                        );

                    $assignedId =
                        (string) (
                            $row
                                ->assigned_admin_user_id
                            ?? ''
                        );

                    return [
                        'onboarding_id' =>
                            (int) $row->id,
                        'membership_id' =>
                            (int) $row->membership_id,
                        'user_id' =>
                            (string) $row->user_id,
                        'name' =>
                            $row->name
                            ?: $row->email,
                        'email' =>
                            $row->email,
                        'member_number' =>
                            $row->member_number,
                        'standing' =>
                            $row->standing,
                        'approved_at' =>
                            $row->approved_at,
                        'status' =>
                            $row->status,
                        'due_at' =>
                            $row->due_at,
                        'welcome_viewed_at' =>
                            $row->welcome_viewed_at,
                        'orientation_completed_at' =>
                            $row->orientation_completed_at,
                        'completed_at' =>
                            $row->completed_at,
                        'last_member_activity_at' =>
                            $row->last_member_activity_at,
                        'admin_note' =>
                            $row->admin_note,
                        'assigned_admin_user_id' =>
                            $assignedId !== ''
                                ? $assignedId
                                : null,
                        'assigned_admin' =>
                            $assignedId !== ''
                                ? (
                                    $adminMap[
                                        $assignedId
                                    ] ?? null
                                )
                                : null,
                        'overdue' =>
                            ! empty($row->due_at)
                            && now()->greaterThan(
                                \Carbon\Carbon::parse(
                                    $row->due_at
                                )
                            )
                            && in_array(
                                $row->status,
                                [
                                    'pending',
                                    'in_progress',
                                ],
                                true
                            ),
                        'signals' =>
                            $signals,
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
                    ) use (
                        $search
                    ): bool {
                        $haystack =
                            strtolower(
                                ($row['name'] ?? '')
                                . ' '
                                . ($row['email'] ?? '')
                                . ' '
                                . ($row[
                                    'member_number'
                                ] ?? '')
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

    public function adminUpdate(
        Request $request,
        int $membershipId
    ): JsonResponse {
        $access = $this->requireAdministratorSession(
            $request
        );

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required for membership onboarding management.'
        );

        $membership = DB::table(
            'nurselink_memberships'
        )
            ->where(
                'id',
                $membershipId
            )
            ->where(
                'status',
                'approved'
            )
            ->first();

        abort_unless(
            $membership,
            404,
            'Approved membership not found.'
        );

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'pending',
                    'in_progress',
                    'completed',
                    'paused',
                ]),
            ],
            'assigned_admin_user_id' => [
                'nullable',
                'string',
                'max:191',
            ],
            'due_at' => [
                'nullable',
                'date',
            ],
            'admin_note' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ]);

        $row = $this->ensureOnboarding(
            (int) $membership->id,
            (string) $membership->user_id
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
                'Assigned onboarding owner must have active Administrator or Super Administrator access.'
            );
        }

        $before = $row;

        DB::table(self::TABLE)
            ->where('id', $row->id)
            ->update([
                'status' =>
                    $data['status'],
                'assigned_admin_user_id' =>
                    $assignedAdminId !== ''
                        ? $assignedAdminId
                        : null,
                'due_at' =>
                    $data['due_at']
                    ?? null,
                'admin_note' =>
                    $data['admin_note']
                    ?? null,
                'last_admin_action_at' =>
                    now(),
                'completed_at' =>
                    $data['status']
                    === 'completed'
                        ? ($row->completed_at ?: now())
                        : null,
                'updated_at' =>
                    now(),
            ]);

        $after = DB::table(self::TABLE)
            ->where('id', $row->id)
            ->first();

        $this->audit(
            $request,
            'membership.onboarding_updated',
            'membership_onboarding',
            (string) $row->id,
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Membership onboarding administration updated.',
            'data' =>
                $after,
        ]);
    }

    public function adminSendWelcome(
        Request $request,
        int $membershipId
    ): JsonResponse {
        $access = $this->requireAdministratorSession(
            $request
        );

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required to send membership onboarding notices.'
        );

        $membership = DB::table(
            'nurselink_memberships'
        )
            ->where(
                'id',
                $membershipId
            )
            ->where(
                'status',
                'approved'
            )
            ->first();

        abort_unless(
            $membership,
            404,
            'Approved membership not found.'
        );

        $this->ensureOnboarding(
            (int) $membership->id,
            (string) $membership->user_id
        );

        if (
            Schema::hasTable(
                'nurselink_notifications'
            )
        ) {
            DB::table(
                'nurselink_notifications'
            )->insert([
                'user_id' =>
                    (string) $membership->user_id,
                'type' =>
                    'membership.onboarding.welcome',
                'severity' =>
                    'info',
                'title' =>
                    'Welcome to NurseLink',
                'message' =>
                    'Your NurseLink membership is approved. Open the Membership Welcome Center to complete your activation checklist.',
                'action_url' =>
                    '/nurselink-membership-welcome.html',
                'created_at' =>
                    now(),
                'updated_at' =>
                    now(),
            ]);
        }

        $this->audit(
            $request,
            'membership.onboarding_welcome_sent',
            'membership',
            (string) $membershipId,
            null,
            [
                'user_id' =>
                    (string) $membership->user_id,
            ]
        );

        return response()->json([
            'message' =>
                'NurseLink onboarding welcome notification sent.',
        ]);
    }

    private function approvedMembership(
        string $userId
    ): ?object {
        return DB::table(
            'nurselink_memberships'
        )
            ->where(
                'user_id',
                $userId
            )
            ->where(
                'status',
                'approved'
            )
            ->first();
    }

    private function ensureOnboarding(
        int $membershipId,
        string $userId
    ): object {
        $row = DB::table(
            self::TABLE
        )
            ->where(
                'membership_id',
                $membershipId
            )
            ->first();

        if ($row) {
            return $row;
        }

        $id = DB::table(
            self::TABLE
        )->insertGetId([
            'membership_id' =>
                $membershipId,
            'user_id' =>
                $userId,
            'status' =>
                'pending',
            'assigned_admin_user_id' =>
                null,
            'due_at' =>
                now()->addDays(14),
            'welcome_viewed_at' =>
                null,
            'orientation_started_at' =>
                null,
            'orientation_completed_at' =>
                null,
            'last_member_activity_at' =>
                null,
            'last_admin_action_at' =>
                null,
            'completed_at' =>
                null,
            'admin_note' =>
                null,
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);

        return DB::table(
            self::TABLE
        )
            ->where(
                'id',
                $id
            )
            ->first();
    }

    private function seedApprovedMemberships(): void
    {
        $rows = DB::table(
            'nurselink_memberships'
        )
            ->where(
                'status',
                'approved'
            )
            ->get([
                'id',
                'user_id',
            ]);

        foreach (
            $rows as $membership
        ) {
            $this->ensureOnboarding(
                (int) $membership->id,
                (string) $membership->user_id
            );
        }
    }

    private function memberSignals(
        string $userId,
        object $membership
    ): array {
        return $this->memberSignalsByIds(
            $userId,
            (int) $membership->id,
            (string) $membership->member_number,
            (string) $membership->standing
        );
    }

    private function memberSignalsByIds(
        string $userId,
        int $membershipId,
        string $memberNumber,
        string $standing
    ): array {
        $employmentCount =
            Schema::hasTable(
                'nurselink_employment_histories'
            )
                ? DB::table(
                    'nurselink_employment_histories'
                )
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->count()
                : 0;

        $credentialCount =
            Schema::hasTable(
                'nurselink_credentials_registry'
            )
                ? DB::table(
                    'nurselink_credentials_registry'
                )
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->count()
                : 0;

        $portfolioCount =
            Schema::hasTable(
                'nurselink_portfolio_items'
            )
                ? DB::table(
                    'nurselink_portfolio_items'
                )
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->count()
                : 0;

        $careerPreferences =
            Schema::hasTable(
                'nurselink_career_preferences'
            )
                && DB::table(
                    'nurselink_career_preferences'
                )
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->exists();

        $profilePhoto =
            Schema::hasColumn(
                'users',
                'profile_photo_path'
            )
                ? DB::table(
                    'users'
                )
                    ->where(
                        'id',
                        $userId
                    )
                    ->whereNotNull(
                        'profile_photo_path'
                    )
                    ->where(
                        'profile_photo_path',
                        '<>',
                        ''
                    )
                    ->exists()
                : false;

        return [
            'profile_photo_ready' =>
                $profilePhoto,
            'employment_records' =>
                $employmentCount,
            'credentials_registered' =>
                $credentialCount,
            'portfolio_started' =>
                $portfolioCount > 0,
            'career_preferences_ready' =>
                $careerPreferences,
            'digital_member_identity_ready' =>
                $memberNumber !== ''
                && strtolower(
                    $standing
                ) === 'active',
            'activation_score' =>
                $this->activationScore([
                    $profilePhoto,
                    $employmentCount > 0,
                    $credentialCount > 0,
                    $portfolioCount > 0,
                    $careerPreferences,
                    $memberNumber !== ''
                        && strtolower(
                            $standing
                        ) === 'active',
                ]),
        ];
    }

    private function activationScore(
        array $signals
    ): int {
        if (
            $signals === []
        ) {
            return 0;
        }

        $complete = count(
            array_filter(
                $signals
            )
        );

        return (int) round(
            ($complete / count($signals))
            * 100
        );
    }

    private function computedStatus(
        object $row,
        array $signals
    ): string {
        if (
            $row->status === 'paused'
        ) {
            return 'paused';
        }

        $memberComplete =
            ! empty(
                $row->welcome_viewed_at
            )
            && ! empty(
                $row->orientation_completed_at
            );

        $activationReady =
            ($signals[
                'activation_score'
            ] ?? 0)
                >= 67;

        if (
            $memberComplete
            && $activationReady
        ) {
            return 'completed';
        }

        if (
            ! empty(
                $row->welcome_viewed_at
            )
            || ! empty(
                $row->orientation_started_at
            )
            || (
                $signals[
                    'activation_score'
                ] ?? 0
            ) > 0
        ) {
            return 'in_progress';
        }

        return 'pending';
    }

    private function recommendedActions(
        object $row,
        array $signals
    ): array {
        $actions = [];

        if (
            empty(
                $row->welcome_viewed_at
            )
        ) {
            $actions[] = [
                'key' => 'welcome',
                'label' => 'Review your NurseLink welcome',
                'url' => '/nurselink-membership-welcome.html',
            ];
        }

        if (
            empty(
                $row->orientation_completed_at
            )
        ) {
            $actions[] = [
                'key' => 'orientation',
                'label' => 'Complete NurseLink member orientation',
                'url' => '/nurselink-membership-welcome.html#orientation',
            ];
        }

        if (
            ! (
                $signals[
                    'profile_photo_ready'
                ] ?? false
            )
        ) {
            $actions[] = [
                'key' => 'profile',
                'label' => 'Complete your professional profile',
                'url' => '/profile',
            ];
        }

        if (
            ! (
                $signals[
                    'portfolio_started'
                ] ?? false
            )
        ) {
            $actions[] = [
                'key' => 'portfolio',
                'label' => 'Start your professional portfolio',
                'url' => '/portfolio',
            ];
        }

        if (
            ! (
                $signals[
                    'career_preferences_ready'
                ] ?? false
            )
        ) {
            $actions[] = [
                'key' => 'career',
                'label' => 'Set your career preferences',
                'url' => '/career-preferences',
            ];
        }

        if (
            $signals[
                'digital_member_identity_ready'
            ] ?? false
        ) {
            $actions[] = [
                'key' => 'member_id',
                'label' => 'Review your NurseLink digital member identity',
                'url' => '/nurselink-digital-id.html',
            ];
        }

        return $actions;
    }

    private function isActiveAdministrator(
        string $userId
    ): bool {
        $reviewAccess =
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

        return $reviewAccess
            || $superAccess;
    }

    private function requireAdministratorSession(
        Request $request
    ): array {
        $user = $request->user();
        abort_unless($user, 401);

        $userId = (string) $user->getKey();
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

        $isSuper =
            $super
            || $reviewRole
                === 'super_admin'
            || (bool) (
                $user->is_super_admin
                ?? false
            );

        $isAdmin =
            $isSuper
            || $reviewRole
                === 'admin'
            || (bool) (
                $user->is_admin
                ?? false
            );

        abort_unless(
            $isAdmin,
            403,
            'Administrator access is required for membership onboarding.'
        );

        return [
            'role' =>
                $isSuper
                    ? 'super_admin'
                    : 'admin',
            'is_admin' =>
                $isAdmin,
            'is_super_admin' =>
                $isSuper,
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

        $rows = DB::table('users')
            ->whereIn('id', $ids)
            ->get($columns);

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
