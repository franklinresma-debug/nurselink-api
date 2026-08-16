<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class MentoringController extends Controller
{
    private const PROFILES =
        'nurselink_mentoring_profiles';

    private const REQUESTS =
        'nurselink_mentoring_requests';

    public function profile(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $profile = DB::table(self::PROFILES)
            ->where('user_id', $userId)
            ->first();

        return response()->json([
            'data' => $profile
                ? $this->presentProfile(
                    $profile,
                    $this->displayName($userId)
                )
                : null,
        ]);
    }

    public function updateProfile(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $data = $request->validate([
            'role_preference' => [
                'required',
                'string',
                Rule::in([
                    'mentor',
                    'mentee',
                    'both',
                ]),
            ],
            'availability' => [
                'required',
                'string',
                Rule::in([
                    'open',
                    'limited',
                    'unavailable',
                ]),
            ],
            'focus_areas' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'languages' => [
                'nullable',
                'string',
                'max:500',
            ],
            'timezone' => [
                'nullable',
                'string',
                'max:120',
            ],
            'bio' => [
                'nullable',
                'string',
                'max:3000',
            ],
            'discoverable' => [
                'required',
                'boolean',
            ],
        ]);

        $before = DB::table(self::PROFILES)
            ->where('user_id', $userId)
            ->first();

        if ($before) {
            DB::table(self::PROFILES)
                ->where('id', $before->id)
                ->update([
                    ...$data,
                    'updated_at' => now(),
                ]);

            $id = (int) $before->id;
        } else {
            $id = DB::table(self::PROFILES)
                ->insertGetId([
                    'user_id' => $userId,
                    ...$data,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $after = DB::table(self::PROFILES)
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'mentoring.profile_updated',
            (string) $id,
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Mentoring profile updated.',
            'data' =>
                $this->presentProfile(
                    $after,
                    $this->displayName($userId)
                ),
        ]);
    }

    public function directory(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $data = $request->validate([
            'role' => [
                'nullable',
                'string',
                Rule::in([
                    'all',
                    'mentor',
                    'mentee',
                    'both',
                ]),
            ],
            'search' => [
                'nullable',
                'string',
                'max:190',
            ],
        ]);

        $rows = DB::table(
            self::PROFILES
        )
            ->where('discoverable', true)
            ->whereIn(
                'availability',
                ['open', 'limited']
            )
            ->where(
                'user_id',
                '!=',
                $userId
            )
            ->orderBy('role_preference')
            ->orderByDesc('updated_at')
            ->limit(1000)
            ->get();

        $role = $data['role'] ?? 'all';

        if ($role !== 'all') {
            $rows = $rows->filter(
                fn ($row): bool =>
                    $row->role_preference === $role
                    || $row->role_preference === 'both'
            );
        }

        $nameMap = $this->userNameMap(
            $rows->pluck('user_id')
                ->map(fn ($id) => (string) $id)
                ->all()
        );

        $result = $rows->map(
            function ($row) use (
                $nameMap
            ): array {
                $id = (string) $row->user_id;

                return $this->presentProfile(
                    $row,
                    $nameMap[$id]
                        ?? 'NurseLink Member'
                );
            }
        );

        $search = strtolower(
            trim(
                (string) (
                    $data['search'] ?? ''
                )
            )
        );

        if ($search !== '') {
            $result = $result->filter(
                function (
                    array $row
                ) use ($search): bool {
                    $haystack = strtolower(
                        ($row['display_name'] ?? '')
                        . ' '
                        . ($row['focus_areas'] ?? '')
                        . ' '
                        . ($row['languages'] ?? '')
                        . ' '
                        . ($row['bio'] ?? '')
                    );

                    return str_contains(
                        $haystack,
                        $search
                    );
                }
            );
        }

        return response()->json([
            'data' => $result->values(),
            'privacy' => [
                'email_exposed' => false,
                'phone_exposed' => false,
                'address_exposed' => false,
                'employment_private_fields_exposed'
                    => false,
            ],
            'governance' => [
                'mentor_role_is_official_credential'
                    => false,
            ],
        ]);
    }

    public function requests(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $rows = DB::table(self::REQUESTS)
            ->where(
                function ($q) use (
                    $userId
                ): void {
                    $q->where(
                        'mentor_user_id',
                        $userId
                    )->orWhere(
                        'mentee_user_id',
                        $userId
                    );
                }
            )
            ->orderByDesc('updated_at')
            ->limit(1000)
            ->get();

        $ids = $rows->flatMap(
            fn ($row): array => [
                (string) $row->mentor_user_id,
                (string) $row->mentee_user_id,
            ]
        )->unique()->values()->all();

        $names = $this->userNameMap($ids);

        return response()->json([
            'data' => $rows->map(
                fn ($row): array =>
                    $this->presentRequest(
                        $row,
                        $userId,
                        $names
                    )
            )->values(),
        ]);
    }

    public function sendRequest(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $data = $request->validate([
            'mentor_user_id' => [
                'required',
                'string',
            ],
            'focus_area' => [
                'nullable',
                'string',
                'max:190',
            ],
            'message' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $mentorId = (string)
            $data['mentor_user_id'];

        if (hash_equals(
            $userId,
            $mentorId
        )) {
            return response()->json([
                'message' =>
                    'You cannot send a mentoring request to yourself.',
            ], 422);
        }

        $mentorProfile = DB::table(
            self::PROFILES
        )
            ->where(
                'user_id',
                $mentorId
            )
            ->where('discoverable', true)
            ->whereIn(
                'availability',
                ['open', 'limited']
            )
            ->whereIn(
                'role_preference',
                ['mentor', 'both']
            )
            ->first();

        abort_unless($mentorProfile, 404);

        $active = DB::table(self::REQUESTS)
            ->where(
                'mentor_user_id',
                $mentorId
            )
            ->where(
                'mentee_user_id',
                $userId
            )
            ->whereIn(
                'status',
                ['requested', 'accepted']
            )
            ->first();

        if ($active) {
            return response()->json([
                'message' =>
                    'An active mentoring request already exists with this member.',
                'data' =>
                    $this->presentRequest(
                        $active,
                        $userId,
                        $this->userNameMap([
                            $mentorId,
                            $userId,
                        ])
                    ),
            ], 409);
        }

        $id = DB::table(self::REQUESTS)
            ->insertGetId([
                'mentor_user_id' => $mentorId,
                'mentee_user_id' => $userId,
                'status' => 'requested',
                'focus_area' =>
                    $data['focus_area']
                        ?? null,
                'message' =>
                    $data['message']
                        ?? null,
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $row = DB::table(self::REQUESTS)
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'mentoring.request_sent',
            (string) $id,
            null,
            $row
        );

        $this->notify(
            $mentorId,
            'mentoring.request.received',
            'New mentoring request',
            'A NurseLink member sent you a mentoring request.'
        );

        return response()->json([
            'message' =>
                'Mentoring request sent.',
            'data' =>
                $this->presentRequest(
                    $row,
                    $userId,
                    $this->userNameMap([
                        $mentorId,
                        $userId,
                    ])
                ),
        ], 201);
    }

    public function updateRequest(
        Request $request,
        int $requestId
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $before = DB::table(self::REQUESTS)
            ->where('id', $requestId)
            ->first();

        abort_unless($before, 404);

        $data = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in([
                    'accepted',
                    'declined',
                    'cancelled',
                    'completed',
                ]),
            ],
        ]);

        $status = $data['status'];

        $isMentor = hash_equals(
            (string) $before->mentor_user_id,
            $userId
        );

        $isMentee = hash_equals(
            (string) $before->mentee_user_id,
            $userId
        );

        abort_unless(
            $isMentor || $isMentee,
            403
        );

        if (
            in_array(
                $status,
                ['accepted', 'declined'],
                true
            )
            && ! $isMentor
        ) {
            return response()->json([
                'message' =>
                    'Only the requested mentor can accept or decline this request.',
            ], 403);
        }

        if (
            $status === 'completed'
            && $before->status !== 'accepted'
        ) {
            return response()->json([
                'message' =>
                    'Only an accepted mentoring relationship can be completed.',
            ], 422);
        }

        if (
            in_array(
                $before->status,
                ['declined', 'cancelled', 'completed'],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'This mentoring request is already closed.',
            ], 422);
        }

        if (
            $status === 'accepted'
            && $before->status !== 'requested'
        ) {
            return response()->json([
                'message' =>
                    'Only a requested mentoring relationship can be accepted.',
            ], 422);
        }

        if (
            $status === 'declined'
            && $before->status !== 'requested'
        ) {
            return response()->json([
                'message' =>
                    'Only a requested mentoring relationship can be declined.',
            ], 422);
        }

        $updates = [
            'status' => $status,
            'updated_at' => now(),
        ];

        if ($status === 'accepted') {
            $updates['accepted_at'] = now();
        } elseif ($status === 'declined') {
            $updates['declined_at'] = now();
        } elseif ($status === 'cancelled') {
            $updates['cancelled_at'] = now();
        } elseif ($status === 'completed') {
            $updates['completed_at'] = now();
        }

        DB::table(self::REQUESTS)
            ->where('id', $requestId)
            ->update($updates);

        $after = DB::table(self::REQUESTS)
            ->where('id', $requestId)
            ->first();

        $this->audit(
            $request,
            'mentoring.request_status_changed',
            (string) $requestId,
            $before,
            $after
        );

        $otherUserId = $isMentor
            ? (string) $before->mentee_user_id
            : (string) $before->mentor_user_id;

        $this->notify(
            $otherUserId,
            'mentoring.request.' . $status,
            'Mentoring request updated',
            'Your NurseLink mentoring request is now '
                . ucfirst($status)
                . '.'
        );

        return response()->json([
            'message' =>
                'Mentoring request updated.',
            'data' =>
                $this->presentRequest(
                    $after,
                    $userId,
                    $this->userNameMap([
                        (string)
                            $after->mentor_user_id,
                        (string)
                            $after->mentee_user_id,
                    ])
                ),
        ]);
    }

    public function adminSummary(
        Request $request
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $profiles = DB::table(
            self::PROFILES
        )
            ->select(
                'role_preference',
                'availability',
                'discoverable'
            )
            ->get();

        $requests = DB::table(
            self::REQUESTS
        )
            ->select(
                'status',
                DB::raw(
                    'COUNT(*) AS aggregate_count'
                )
            )
            ->groupBy('status')
            ->get();

        $requestCounts = [
            'requested' => 0,
            'accepted' => 0,
            'declined' => 0,
            'cancelled' => 0,
            'completed' => 0,
        ];

        foreach ($requests as $row) {
            if (
                array_key_exists(
                    (string) $row->status,
                    $requestCounts
                )
            ) {
                $requestCounts[
                    (string) $row->status
                ] = (int)
                    $row->aggregate_count;
            }
        }

        return response()->json([
            'data' => [
                'profiles' => [
                    'total' =>
                        $profiles->count(),
                    'discoverable' =>
                        $profiles->where(
                            'discoverable',
                            true
                        )->count(),
                    'open_or_limited' =>
                        $profiles->whereIn(
                            'availability',
                            ['open', 'limited']
                        )->count(),
                    'mentor_or_both' =>
                        $profiles->whereIn(
                            'role_preference',
                            ['mentor', 'both']
                        )->count(),
                    'mentee_or_both' =>
                        $profiles->whereIn(
                            'role_preference',
                            ['mentee', 'both']
                        )->count(),
                ],
                'requests' =>
                    $requestCounts,
                'privacy' => [
                    'request_message_exposed'
                        => false,
                    'member_email_exposed'
                        => false,
                ],
            ],
        ]);
    }

    private function presentProfile(
        object $row,
        string $displayName
    ): array {
        return [
            'id' => (int) $row->id,
            'user_id' =>
                (string) $row->user_id,
            'display_name' =>
                $displayName,
            'role_preference' =>
                $row->role_preference,
            'availability' =>
                $row->availability,
            'focus_areas' =>
                $row->focus_areas,
            'languages' =>
                $row->languages,
            'timezone' =>
                $row->timezone,
            'bio' => $row->bio,
            'discoverable' =>
                (bool) $row->discoverable,
        ];
    }

    private function presentRequest(
        object $row,
        string $currentUserId,
        array $names
    ): array {
        $mentorId =
            (string) $row->mentor_user_id;

        $menteeId =
            (string) $row->mentee_user_id;

        return [
            'id' => (int) $row->id,
            'mentor_user_id' => $mentorId,
            'mentee_user_id' => $menteeId,
            'mentor_name' =>
                $names[$mentorId]
                    ?? 'NurseLink Member',
            'mentee_name' =>
                $names[$menteeId]
                    ?? 'NurseLink Member',
            'current_user_role' =>
                hash_equals(
                    $mentorId,
                    $currentUserId
                )
                    ? 'mentor'
                    : 'mentee',
            'status' => $row->status,
            'focus_area' =>
                $row->focus_area,
            'message' => $row->message,
            'requested_at' =>
                $row->requested_at,
            'accepted_at' =>
                $row->accepted_at,
            'declined_at' =>
                $row->declined_at,
            'cancelled_at' =>
                $row->cancelled_at,
            'completed_at' =>
                $row->completed_at,
        ];
    }

    private function displayName(
        string $userId
    ): string {
        $map = $this->userNameMap([
            $userId,
        ]);

        return $map[$userId]
            ?? 'NurseLink Member';
    }

    private function userNameMap(
        array $ids
    ): array {
        if ($ids === []) {
            return [];
        }

        $columns = ['id'];

        foreach (
            [
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
                $columns[] = $column;
            }
        }

        return DB::table('users')
            ->whereIn('id', $ids)
            ->get($columns)
            ->mapWithKeys(
                function ($row): array {
                    $name = trim(
                        (string) (
                            $row->name ?? ''
                        )
                    );

                    if ($name === '') {
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

                    return [
                        (string) $row->id => (
                            $name !== ''
                                ? $name
                                : 'NurseLink Member'
                        ),
                    ];
                }
            )
            ->all();
    }

    private function notify(
        string $userId,
        string $type,
        string $title,
        string $message
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
            'user_id' => $userId,
            'type' => $type,
            'severity' => 'info',
            'title' => $title,
            'message' => $message,
            'action_url' =>
                '/nurselink-mentoring.html',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

        $reviewRole =
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

        $explicitSuperAdmin =
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
            $explicitSuperAdmin
            || in_array(
                $reviewRole,
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
            'Administrator access is required for mentoring analytics.'
        );
    }

    private function audit(
        Request $request,
        string $action,
        string $targetId,
        ?object $before,
        ?object $after
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
            'target_type' => 'mentoring',
            'target_id' => $targetId,
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
            'created_at' => now(),
        ]);
    }
}
