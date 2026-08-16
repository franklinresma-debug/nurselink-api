<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChaptersController extends Controller
{
    private const CHAPTERS = 'nurselink_chapters';
    private const MEMBERSHIPS =
        'nurselink_chapter_memberships';

    public function memberIndex(
        Request $request
    ): JsonResponse {
        $userId = (string) $request->user()->getKey();

        $data = $request->validate([
            'type' => [
                'nullable',
                'string',
                Rule::in([
                    'all',
                    'regional',
                    'overseas',
                    'professional_interest',
                    'institutional',
                    'other',
                ]),
            ],
            'scope' => [
                'nullable',
                'string',
                Rule::in([
                    'all',
                    'mine',
                    'available',
                ]),
            ],
            'search' => [
                'nullable',
                'string',
                'max:190',
            ],
        ]);

        $query = DB::table(self::CHAPTERS)
            ->where('status', 'active');

        if (($data['type'] ?? 'all') !== 'all') {
            $query->where(
                'chapter_type',
                $data['type']
            );
        }

        if (! empty($data['search'])) {
            $search = '%'
                . trim($data['search'])
                . '%';

            $query->where(
                function ($q) use ($search): void {
                    $q->where(
                        'name',
                        'like',
                        $search
                    )
                    ->orWhere(
                        'description',
                        'like',
                        $search
                    )
                    ->orWhere(
                        'region',
                        'like',
                        $search
                    )
                    ->orWhere(
                        'country',
                        'like',
                        $search
                    )
                    ->orWhere(
                        'city',
                        'like',
                        $search
                    );
                }
            );
        }

        $rows = $query
            ->orderBy('country')
            ->orderBy('region')
            ->orderBy('name')
            ->limit(1000)
            ->get();

        $chapterIds = $rows
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $memberMap = $chapterIds === []
            ? []
            : DB::table(self::MEMBERSHIPS)
                ->where('user_id', $userId)
                ->whereIn(
                    'chapter_id',
                    $chapterIds
                )
                ->get()
                ->mapWithKeys(
                    fn ($row): array => [
                        (int) $row->chapter_id
                            => $row,
                    ]
                )
                ->all();

        $counts = $chapterIds === []
            ? []
            : DB::table(self::MEMBERSHIPS)
                ->whereIn(
                    'chapter_id',
                    $chapterIds
                )
                ->where(
                    'status',
                    'active'
                )
                ->select(
                    'chapter_id',
                    DB::raw(
                        'COUNT(*) AS aggregate_count'
                    )
                )
                ->groupBy('chapter_id')
                ->get()
                ->mapWithKeys(
                    fn ($row): array => [
                        (int) $row->chapter_id
                            => (int)
                                $row->aggregate_count,
                    ]
                )
                ->all();

        $result = $rows->map(
            function ($chapter) use (
                $memberMap,
                $counts
            ): array {
                $id = (int) $chapter->id;

                return $this->presentChapter(
                    $chapter,
                    $memberMap[$id] ?? null,
                    $counts[$id] ?? 0
                );
            }
        );

        $scope = $data['scope'] ?? 'all';

        if ($scope === 'mine') {
            $result = $result->filter(
                fn (array $row): bool =>
                    in_array(
                        $row['membership']['status']
                            ?? null,
                        ['pending', 'active'],
                        true
                    )
            );
        } elseif ($scope === 'available') {
            $result = $result->filter(
                fn (array $row): bool =>
                    ! in_array(
                        $row['membership']['status']
                            ?? null,
                        ['pending', 'active'],
                        true
                    )
            );
        }

        return response()->json([
            'data' => $result->values(),
            'meta' => [
                'primary_chapter_policy' =>
                    'At most one Active chapter membership is marked as primary.',
                'member_role_assignment' =>
                    'Chapter officer/coordinator roles are administrator-controlled.',
            ],
        ]);
    }

    public function requestJoin(
        Request $request,
        int $chapterId
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $chapter = DB::table(self::CHAPTERS)
            ->where('id', $chapterId)
            ->where('status', 'active')
            ->first();

        abort_unless($chapter, 404);

        if (! (bool) $chapter->member_join_enabled) {
            return response()->json([
                'message' =>
                    'Member requests are not currently enabled for this chapter.',
            ], 422);
        }

        $existing = DB::table(self::MEMBERSHIPS)
            ->where(
                'chapter_id',
                $chapterId
            )
            ->where(
                'user_id',
                $userId
            )
            ->first();

        if (
            $existing
            && in_array(
                $existing->status,
                ['pending', 'active'],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'You already have a current membership request or active membership for this chapter.',
                'data' =>
                    $this->presentMembership(
                        $existing
                    ),
            ], 409);
        }

        if ($existing) {
            DB::table(self::MEMBERSHIPS)
                ->where('id', $existing->id)
                ->update([
                    'status' => 'pending',
                    'chapter_role' => 'member',
                    'is_primary' => false,
                    'requested_at' => now(),
                    'approved_at' => null,
                    'declined_at' => null,
                    'inactive_at' => null,
                    'updated_at' => now(),
                ]);

            $membership =
                DB::table(self::MEMBERSHIPS)
                    ->where(
                        'id',
                        $existing->id
                    )
                    ->first();
        } else {
            $id = DB::table(
                self::MEMBERSHIPS
            )->insertGetId([
                'user_id' => $userId,
                'chapter_id' => $chapterId,
                'status' => 'pending',
                'chapter_role' => 'member',
                'is_primary' => false,
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $membership =
                DB::table(self::MEMBERSHIPS)
                    ->where('id', $id)
                    ->first();
        }

        $this->notifyAdministrators(
            'chapter.membership.requested',
            'New chapter membership request',
            'An approved NurseLink member requested to join '
                . $chapter->name
                . '.'
        );

        return response()->json([
            'message' =>
                'Chapter membership request submitted.',
            'data' =>
                $this->presentMembership(
                    $membership
                ),
        ], 201);
    }

    public function withdraw(
        Request $request,
        int $chapterId
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $membership =
            DB::table(self::MEMBERSHIPS)
                ->where(
                    'chapter_id',
                    $chapterId
                )
                ->where(
                    'user_id',
                    $userId
                )
                ->first();

        abort_unless($membership, 404);

        if ($membership->status === 'pending') {
            DB::table(self::MEMBERSHIPS)
                ->where('id', $membership->id)
                ->update([
                    'status' => 'inactive',
                    'is_primary' => false,
                    'inactive_at' => now(),
                    'updated_at' => now(),
                ]);

            return response()->json([
                'message' =>
                    'Chapter membership request withdrawn.',
            ]);
        }

        if ($membership->status === 'active') {
            DB::table(self::MEMBERSHIPS)
                ->where('id', $membership->id)
                ->update([
                    'status' => 'inactive',
                    'is_primary' => false,
                    'inactive_at' => now(),
                    'updated_at' => now(),
                ]);

            return response()->json([
                'message' =>
                    'Chapter membership marked inactive.',
            ]);
        }

        return response()->json([
            'message' =>
                'This chapter membership is already inactive.',
        ], 422);
    }

    public function adminIndex(
        Request $request
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $rows = DB::table(self::CHAPTERS)
            ->orderBy('status')
            ->orderBy('country')
            ->orderBy('region')
            ->orderBy('name')
            ->limit(2000)
            ->get();

        $ids = $rows
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $statusCounts = $ids === []
            ? []
            : DB::table(self::MEMBERSHIPS)
                ->whereIn(
                    'chapter_id',
                    $ids
                )
                ->select(
                    'chapter_id',
                    'status',
                    DB::raw(
                        'COUNT(*) AS aggregate_count'
                    )
                )
                ->groupBy(
                    'chapter_id',
                    'status'
                )
                ->get()
                ->groupBy(
                    fn ($row) =>
                        (int) $row->chapter_id
                )
                ->map(
                    fn ($group) =>
                        $group->mapWithKeys(
                            fn ($row): array => [
                                (string) $row->status
                                    => (int)
                                        $row->aggregate_count,
                            ]
                        )->all()
                )
                ->all();

        return response()->json([
            'data' => $rows->map(
                function ($row) use (
                    $statusCounts
                ): array {
                    $id = (int) $row->id;

                    return [
                        ...$this->presentChapter(
                            $row,
                            null,
                            (int) (
                                $statusCounts[$id]['active']
                                ?? 0
                            )
                        ),
                        'membership_counts' =>
                            $statusCounts[$id]
                                ?? [],
                    ];
                }
            )->values(),
        ]);
    }

    public function adminStore(
        Request $request
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $data = $this->validatedChapter(
            $request
        );

        $slug = $this->uniqueSlug(
            $data['slug']
                ?? $data['name']
        );

        unset($data['slug']);

        $id = DB::table(
            self::CHAPTERS
        )->insertGetId([
            ...$data,
            'slug' => $slug,
            'created_by' =>
                (string)
                    $request->user()->getKey(),
            'updated_by' =>
                (string)
                    $request->user()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table(self::CHAPTERS)
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'chapter.created',
            (string) $id,
            null,
            $row
        );

        return response()->json([
            'message' =>
                'Chapter / community created.',
            'data' =>
                $this->presentChapter(
                    $row,
                    null,
                    0
                ),
        ], 201);
    }

    public function adminUpdate(
        Request $request,
        int $chapterId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $before = DB::table(self::CHAPTERS)
            ->where('id', $chapterId)
            ->first();

        abort_unless($before, 404);

        $data = $this->validatedChapter(
            $request
        );

        if (
            isset($data['slug'])
            && trim((string) $data['slug']) !== ''
            && $data['slug'] !== $before->slug
        ) {
            $data['slug'] =
                $this->uniqueSlug(
                    $data['slug'],
                    $chapterId
                );
        } else {
            unset($data['slug']);
        }

        DB::table(self::CHAPTERS)
            ->where('id', $chapterId)
            ->update([
                ...$data,
                'updated_by' =>
                    (string)
                        $request->user()->getKey(),
                'updated_at' => now(),
            ]);

        $after = DB::table(self::CHAPTERS)
            ->where('id', $chapterId)
            ->first();

        $this->audit(
            $request,
            'chapter.updated',
            (string) $chapterId,
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Chapter / community updated.',
            'data' =>
                $this->presentChapter(
                    $after,
                    null,
                    0
                ),
        ]);
    }

    public function adminMembers(
        Request $request,
        int $chapterId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $chapter = DB::table(self::CHAPTERS)
            ->where('id', $chapterId)
            ->first();

        abort_unless($chapter, 404);

        $rows = DB::table(
            self::MEMBERSHIPS . ' as cm'
        )
            ->join(
                'users as u',
                'u.id',
                '=',
                'cm.user_id'
            )
            ->where(
                'cm.chapter_id',
                $chapterId
            )
            ->orderByRaw(
                "CASE cm.status
                    WHEN 'pending' THEN 1
                    WHEN 'active' THEN 2
                    ELSE 3 END"
            )
            ->orderByDesc(
                'cm.requested_at'
            )
            ->select([
                'cm.id',
                'cm.user_id',
                'cm.status',
                'cm.chapter_role',
                'cm.is_primary',
                'cm.requested_at',
                'cm.approved_at',
                'cm.declined_at',
                'cm.inactive_at',
                'u.email',
            ])
            ->get();

        return response()->json([
            'chapter' => [
                'id' => (int) $chapter->id,
                'name' => $chapter->name,
            ],
            'data' => $rows,
        ]);
    }

    public function adminMembershipStatus(
        Request $request,
        int $chapterId,
        int $membershipId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $before = DB::table(
            self::MEMBERSHIPS
        )
            ->where('id', $membershipId)
            ->where(
                'chapter_id',
                $chapterId
            )
            ->first();

        abort_unless($before, 404);

        $data = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in([
                    'pending',
                    'active',
                    'declined',
                    'inactive',
                ]),
            ],
            'chapter_role' => [
                'required',
                'string',
                Rule::in([
                    'member',
                    'officer',
                    'coordinator',
                ]),
            ],
            'is_primary' => [
                'required',
                'boolean',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:3000',
            ],
        ]);

        if (
            $data['is_primary']
            && $data['status'] !== 'active'
        ) {
            return response()->json([
                'message' =>
                    'Only an Active chapter membership can be marked primary.',
            ], 422);
        }

        if ($data['is_primary']) {
            DB::table(self::MEMBERSHIPS)
                ->where(
                    'user_id',
                    $before->user_id
                )
                ->where(
                    'id',
                    '!=',
                    $membershipId
                )
                ->update([
                    'is_primary' => false,
                    'updated_at' => now(),
                ]);
        }

        $timestamps = [
            'approved_at' =>
                $data['status'] === 'active'
                    ? (
                        $before->approved_at
                        ?: now()
                    )
                    : $before->approved_at,
            'declined_at' =>
                $data['status'] === 'declined'
                    ? (
                        $before->declined_at
                        ?: now()
                    )
                    : $before->declined_at,
            'inactive_at' =>
                $data['status'] === 'inactive'
                    ? (
                        $before->inactive_at
                        ?: now()
                    )
                    : $before->inactive_at,
        ];

        DB::table(self::MEMBERSHIPS)
            ->where('id', $membershipId)
            ->update([
                'status' => $data['status'],
                'chapter_role' =>
                    $data['chapter_role'],
                'is_primary' =>
                    (bool) $data['is_primary'],
                'notes' =>
                    $data['notes']
                        ?? $before->notes,
                ...$timestamps,
                'updated_at' => now(),
            ]);

        $after = DB::table(
            self::MEMBERSHIPS
        )
            ->where('id', $membershipId)
            ->first();

        $this->audit(
            $request,
            'chapter.membership_status_changed',
            (string) $membershipId,
            $before,
            $after
        );

        $this->notifyMember(
            (string) $after->user_id,
            $after,
            $chapterId
        );

        return response()->json([
            'message' =>
                'Chapter membership updated.',
            'data' =>
                $this->presentMembership(
                    $after
                ),
        ]);
    }

    private function validatedChapter(
        Request $request
    ): array {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:190',
            ],
            'slug' => [
                'nullable',
                'string',
                'max:190',
            ],
            'chapter_type' => [
                'required',
                'string',
                Rule::in([
                    'regional',
                    'overseas',
                    'professional_interest',
                    'institutional',
                    'other',
                ]),
            ],
            'region' => [
                'nullable',
                'string',
                'max:160',
            ],
            'country' => [
                'required',
                'string',
                'max:120',
            ],
            'city' => [
                'nullable',
                'string',
                'max:120',
            ],
            'description' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'contact_email' => [
                'nullable',
                'email',
                'max:190',
            ],
            'status' => [
                'required',
                'string',
                Rule::in([
                    'draft',
                    'active',
                    'inactive',
                ]),
            ],
            'member_join_enabled' => [
                'required',
                'boolean',
            ],
        ]);
    }

    private function uniqueSlug(
        string $value,
        ?int $ignoreId = null
    ): string {
        $base = Str::slug($value);

        if ($base === '') {
            $base = 'chapter';
        }

        $slug = $base;
        $counter = 2;

        while (true) {
            $query = DB::table(self::CHAPTERS)
                ->where('slug', $slug);

            if ($ignoreId !== null) {
                $query->where(
                    'id',
                    '!=',
                    $ignoreId
                );
            }

            if (! $query->exists()) {
                return $slug;
            }

            $slug = $base . '-' . $counter;
            $counter++;
        }
    }

    private function presentChapter(
        object $row,
        ?object $membership,
        int $activeMembers
    ): array {
        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'slug' => $row->slug,
            'chapter_type' =>
                $row->chapter_type,
            'region' => $row->region,
            'country' => $row->country,
            'city' => $row->city,
            'description' =>
                $row->description,
            'contact_email' =>
                $row->contact_email,
            'status' => $row->status,
            'member_join_enabled' =>
                (bool)
                    $row->member_join_enabled,
            'active_member_count' =>
                $activeMembers,
            'membership' =>
                $membership
                    ? $this->presentMembership(
                        $membership
                    )
                    : null,
        ];
    }

    private function presentMembership(
        object $row
    ): array {
        return [
            'id' => (int) $row->id,
            'chapter_id' =>
                (int) $row->chapter_id,
            'status' => $row->status,
            'chapter_role' =>
                $row->chapter_role,
            'is_primary' =>
                (bool) $row->is_primary,
            'requested_at' =>
                $row->requested_at,
            'approved_at' =>
                $row->approved_at,
            'declined_at' =>
                $row->declined_at,
            'inactive_at' =>
                $row->inactive_at,
        ];
    }

    private function notifyMember(
        string $userId,
        object $membership,
        int $chapterId
    ): void {
        if (
            ! Schema::hasTable(
                'nurselink_notifications'
            )
        ) {
            return;
        }

        $chapterName =
            DB::table(self::CHAPTERS)
                ->where('id', $chapterId)
                ->value('name')
            ?? 'your chapter';

        $status = (string) $membership->status;

        DB::table(
            'nurselink_notifications'
        )->insert([
            'user_id' => $userId,
            'type' =>
                'chapter.membership.' . $status,
            'severity' =>
                $status === 'active'
                    ? 'success'
                    : (
                        $status === 'declined'
                            ? 'warning'
                            : 'info'
                    ),
            'title' =>
                'Chapter membership updated',
            'message' =>
                'Your NurseLink chapter membership for '
                . $chapterName
                . ' is now '
                . ucfirst($status)
                . '.',
            'action_url' =>
                '/nurselink-chapters.html',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function notifyAdministrators(
        string $type,
        string $title,
        string $message
    ): void {
        if (
            ! Schema::hasTable(
                'nurselink_notifications'
            )
            || ! Schema::hasTable(
                'nurselink_reviewer_access'
            )
        ) {
            return;
        }

        $ids = DB::table(
            'nurselink_reviewer_access'
        )
            ->where('active', true)
            ->whereIn(
                'role',
                ['admin', 'super_admin']
            )
            ->pluck('user_id');

        foreach ($ids as $userId) {
            DB::table(
                'nurselink_notifications'
            )->insert([
                'user_id' => $userId,
                'type' => $type,
                'severity' => 'info',
                'title' => $title,
                'message' => $message,
                'action_url' =>
                    '/nurselink-chapter-management.html',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
                $user->is_admin ?? false
            )
            || (bool) (
                $user->is_super_admin
                ?? false
            ),
            403,
            'Administrator access is required for chapter management.'
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
            'target_type' => 'chapter',
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
