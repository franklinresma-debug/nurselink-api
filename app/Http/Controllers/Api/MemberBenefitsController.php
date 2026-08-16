<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MemberBenefitsController extends Controller
{
    private const BENEFITS =
        'nurselink_member_benefits';

    private const REQUESTS =
        'nurselink_benefit_requests';

    public function memberIndex(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $data = $request->validate([
            'category' => [
                'nullable',
                'string',
                Rule::in([
                    'all',
                    'wellness',
                    'education',
                    'career',
                    'financial',
                    'community',
                    'partner_offer',
                    'resource',
                    'other',
                ]),
            ],
            'scope' => [
                'nullable',
                'string',
                Rule::in([
                    'all',
                    'requested',
                    'available',
                ]),
            ],
            'search' => [
                'nullable',
                'string',
                'max:190',
            ],
        ]);

        $now = CarbonImmutable::now();

        $query = DB::table(self::BENEFITS)
            ->where('status', 'published')
            ->where(
                function ($q) use ($now): void {
                    $q->whereNull('starts_at')
                        ->orWhere(
                            'starts_at',
                            '<=',
                            $now
                        );
                }
            )
            ->where(
                function ($q) use ($now): void {
                    $q->whereNull('ends_at')
                        ->orWhere(
                            'ends_at',
                            '>=',
                            $now
                        );
                }
            );

        if (($data['category'] ?? 'all') !== 'all') {
            $query->where(
                'category',
                $data['category']
            );
        }

        if (! empty($data['search'])) {
            $search = '%'
                . trim($data['search'])
                . '%';

            $query->where(
                function ($q) use ($search): void {
                    $q->where(
                        'title',
                        'like',
                        $search
                    )
                    ->orWhere(
                        'provider_name',
                        'like',
                        $search
                    )
                    ->orWhere(
                        'description',
                        'like',
                        $search
                    )
                    ->orWhere(
                        'eligibility_note',
                        'like',
                        $search
                    );
                }
            );
        }

        $rows = $query
            ->orderByRaw(
                'CASE WHEN ends_at IS NULL THEN 1 ELSE 0 END'
            )
            ->orderBy('ends_at')
            ->orderBy('title')
            ->limit(1000)
            ->get();

        $ids = $rows->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $requestMap = $ids === []
            ? []
            : DB::table(self::REQUESTS)
                ->where(
                    'user_id',
                    $userId
                )
                ->whereIn(
                    'benefit_id',
                    $ids
                )
                ->get()
                ->mapWithKeys(
                    fn ($row): array => [
                        (int) $row->benefit_id
                            => $row,
                    ]
                )
                ->all();

        $counts = $ids === []
            ? []
            : DB::table(self::REQUESTS)
                ->whereIn(
                    'benefit_id',
                    $ids
                )
                ->whereIn(
                    'status',
                    [
                        'requested',
                        'approved',
                        'fulfilled',
                    ]
                )
                ->select(
                    'benefit_id',
                    DB::raw(
                        'COUNT(*) AS aggregate_count'
                    )
                )
                ->groupBy('benefit_id')
                ->get()
                ->mapWithKeys(
                    fn ($row): array => [
                        (int) $row->benefit_id
                            => (int)
                                $row->aggregate_count,
                    ]
                )
                ->all();

        $result = $rows->map(
            function ($benefit) use (
                $requestMap,
                $counts
            ): array {
                $id = (int) $benefit->id;

                return $this->presentBenefit(
                    $benefit,
                    $requestMap[$id] ?? null,
                    $counts[$id] ?? 0
                );
            }
        );

        $scope = $data['scope'] ?? 'all';

        if ($scope === 'requested') {
            $result = $result->filter(
                fn (array $row): bool =>
                    $row['request'] !== null
            );
        } elseif ($scope === 'available') {
            $result = $result->filter(
                fn (array $row): bool =>
                    $row['request'] === null
                    || in_array(
                        $row['request']['status'],
                        ['declined', 'cancelled'],
                        true
                    )
            );
        }

        return response()->json([
            'data' => $result->values(),
            'meta' => [
                'membership_eligibility_guaranteed'
                    => false,
                'provider_endorsement_implied'
                    => false,
                'message' =>
                    'Benefit availability and eligibility are subject to the listed terms and any provider requirements.',
            ],
        ]);
    }

    public function requestBenefit(
        Request $request,
        int $benefitId
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $data = $request->validate([
            'member_note' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $benefit = $this->availableBenefit(
            $benefitId
        );

        if (! (bool) $benefit->requires_request) {
            return response()->json([
                'message' =>
                    'This resource does not require a NurseLink request. Use the listed access instructions instead.',
            ], 422);
        }

        $activeCount = DB::table(
            self::REQUESTS
        )
            ->where(
                'benefit_id',
                $benefitId
            )
            ->whereIn(
                'status',
                [
                    'requested',
                    'approved',
                    'fulfilled',
                ]
            )
            ->count();

        if (
            $benefit->max_requests !== null
            && $activeCount >=
                (int) $benefit->max_requests
        ) {
            return response()->json([
                'message' =>
                    'This benefit has reached its current NurseLink request capacity.',
            ], 422);
        }

        $existing = DB::table(
            self::REQUESTS
        )
            ->where(
                'benefit_id',
                $benefitId
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
                [
                    'requested',
                    'approved',
                    'fulfilled',
                ],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'You already have a current request for this benefit.',
                'data' =>
                    $this->presentRequest(
                        $existing
                    ),
            ], 409);
        }

        if ($existing) {
            DB::table(self::REQUESTS)
                ->where('id', $existing->id)
                ->update([
                    'status' => 'requested',
                    'member_note' =>
                        $data['member_note']
                            ?? null,
                    'admin_note' => null,
                    'requested_at' => now(),
                    'approved_at' => null,
                    'declined_at' => null,
                    'fulfilled_at' => null,
                    'cancelled_at' => null,
                    'updated_at' => now(),
                ]);

            $id = (int) $existing->id;
        } else {
            $id = DB::table(
                self::REQUESTS
            )->insertGetId([
                'user_id' => $userId,
                'benefit_id' => $benefitId,
                'status' => 'requested',
                'member_note' =>
                    $data['member_note']
                        ?? null,
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $row = DB::table(self::REQUESTS)
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'benefit.requested',
            (string) $id,
            $existing,
            $row
        );

        $this->notifyAdministrators(
            'benefit.requested',
            'New member benefit request',
            'A NurseLink member requested: '
                . $benefit->title
                . '.'
        );

        return response()->json([
            'message' =>
                'Benefit request submitted.',
            'data' =>
                $this->presentRequest($row),
        ], 201);
    }

    public function cancelRequest(
        Request $request,
        int $benefitId
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $before = DB::table(
            self::REQUESTS
        )
            ->where(
                'benefit_id',
                $benefitId
            )
            ->where(
                'user_id',
                $userId
            )
            ->first();

        abort_unless($before, 404);

        if (
            ! in_array(
                $before->status,
                ['requested', 'approved'],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'This benefit request can no longer be cancelled.',
            ], 422);
        }

        DB::table(self::REQUESTS)
            ->where('id', $before->id)
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);

        $after = DB::table(self::REQUESTS)
            ->where('id', $before->id)
            ->first();

        $this->audit(
            $request,
            'benefit.request_cancelled',
            (string) $before->id,
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Benefit request cancelled.',
            'data' =>
                $this->presentRequest($after),
        ]);
    }

    public function adminIndex(
        Request $request
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $rows = DB::table(self::BENEFITS)
            ->orderByRaw(
                "CASE status
                    WHEN 'published' THEN 1
                    WHEN 'draft' THEN 2
                    ELSE 3 END"
            )
            ->orderBy('category')
            ->orderBy('title')
            ->limit(2000)
            ->get();

        $ids = $rows->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $counts = $ids === []
            ? []
            : DB::table(self::REQUESTS)
                ->whereIn(
                    'benefit_id',
                    $ids
                )
                ->select(
                    'benefit_id',
                    'status',
                    DB::raw(
                        'COUNT(*) AS aggregate_count'
                    )
                )
                ->groupBy(
                    'benefit_id',
                    'status'
                )
                ->get()
                ->groupBy(
                    fn ($row) =>
                        (int) $row->benefit_id
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
                    $counts
                ): array {
                    $id = (int) $row->id;

                    return [
                        ...$this->presentBenefit(
                            $row,
                            null,
                            0
                        ),
                        'request_counts' =>
                            $counts[$id] ?? [],
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

        $data = $this->validatedBenefit(
            $request
        );

        $slug = $this->uniqueSlug(
            $data['slug']
                ?? $data['title']
        );

        unset($data['slug']);

        $id = DB::table(
            self::BENEFITS
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

        $row = DB::table(self::BENEFITS)
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'benefit.created',
            (string) $id,
            null,
            $row
        );

        return response()->json([
            'message' =>
                'Member benefit / resource created.',
            'data' =>
                $this->presentBenefit(
                    $row,
                    null,
                    0
                ),
        ], 201);
    }

    public function adminUpdate(
        Request $request,
        int $benefitId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $before = DB::table(self::BENEFITS)
            ->where('id', $benefitId)
            ->first();

        abort_unless($before, 404);

        $data = $this->validatedBenefit(
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
                    $benefitId
                );
        } else {
            unset($data['slug']);
        }

        DB::table(self::BENEFITS)
            ->where('id', $benefitId)
            ->update([
                ...$data,
                'updated_by' =>
                    (string)
                        $request->user()->getKey(),
                'updated_at' => now(),
            ]);

        $after = DB::table(self::BENEFITS)
            ->where('id', $benefitId)
            ->first();

        $this->audit(
            $request,
            'benefit.updated',
            (string) $benefitId,
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Member benefit / resource updated.',
            'data' =>
                $this->presentBenefit(
                    $after,
                    null,
                    0
                ),
        ]);
    }

    public function adminRequests(
        Request $request,
        int $benefitId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $benefit = DB::table(self::BENEFITS)
            ->where('id', $benefitId)
            ->first();

        abort_unless($benefit, 404);

        $rows = DB::table(
            self::REQUESTS . ' as br'
        )
            ->join(
                'users as u',
                'u.id',
                '=',
                'br.user_id'
            )
            ->where(
                'br.benefit_id',
                $benefitId
            )
            ->orderByRaw(
                "CASE br.status
                    WHEN 'requested' THEN 1
                    WHEN 'approved' THEN 2
                    WHEN 'fulfilled' THEN 3
                    ELSE 4 END"
            )
            ->orderByDesc(
                'br.requested_at'
            )
            ->select([
                'br.id',
                'br.user_id',
                'br.status',
                'br.member_note',
                'br.admin_note',
                'br.requested_at',
                'br.approved_at',
                'br.declined_at',
                'br.fulfilled_at',
                'br.cancelled_at',
                'u.email',
            ])
            ->get();

        return response()->json([
            'benefit' => [
                'id' => (int) $benefit->id,
                'title' => $benefit->title,
            ],
            'data' => $rows,
            'privacy' => [
                'home_address_exposed' => false,
                'credential_data_exposed' => false,
                'uploaded_documents_exposed' => false,
            ],
        ]);
    }

    public function adminRequestStatus(
        Request $request,
        int $benefitId,
        int $requestId
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $before = DB::table(
            self::REQUESTS
        )
            ->where('id', $requestId)
            ->where(
                'benefit_id',
                $benefitId
            )
            ->first();

        abort_unless($before, 404);

        $data = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in([
                    'requested',
                    'approved',
                    'declined',
                    'fulfilled',
                    'cancelled',
                ]),
            ],
            'admin_note' => [
                'nullable',
                'string',
                'max:3000',
            ],
        ]);

        $status = $data['status'];

        if (
            $status === 'fulfilled'
            && $before->status !== 'approved'
        ) {
            return response()->json([
                'message' =>
                    'Only an Approved benefit request can be marked Fulfilled.',
            ], 422);
        }

        $updates = [
            'status' => $status,
            'admin_note' =>
                $data['admin_note'] ?? null,
            'updated_at' => now(),
        ];

        if ($status === 'approved') {
            $updates['approved_at'] =
                $before->approved_at ?: now();
        } elseif ($status === 'declined') {
            $updates['declined_at'] =
                $before->declined_at ?: now();
        } elseif ($status === 'fulfilled') {
            $updates['fulfilled_at'] =
                $before->fulfilled_at ?: now();
        } elseif ($status === 'cancelled') {
            $updates['cancelled_at'] =
                $before->cancelled_at ?: now();
        }

        DB::table(self::REQUESTS)
            ->where('id', $requestId)
            ->update($updates);

        $after = DB::table(self::REQUESTS)
            ->where('id', $requestId)
            ->first();

        $this->audit(
            $request,
            'benefit.request_status_changed',
            (string) $requestId,
            $before,
            $after
        );

        $benefitTitle =
            DB::table(self::BENEFITS)
                ->where('id', $benefitId)
                ->value('title')
            ?? 'Member benefit';

        $this->notifyMember(
            (string) $after->user_id,
            'benefit.request.' . $status,
            'Benefit request updated',
            $benefitTitle
                . ' is now '
                . ucfirst($status)
                . '.'
        );

        return response()->json([
            'message' =>
                'Benefit request updated.',
            'data' =>
                $this->presentRequest($after),
        ]);
    }

    private function validatedBenefit(
        Request $request
    ): array {
        return $request->validate([
            'title' => [
                'required',
                'string',
                'max:190',
            ],
            'slug' => [
                'nullable',
                'string',
                'max:190',
            ],
            'category' => [
                'required',
                'string',
                Rule::in([
                    'wellness',
                    'education',
                    'career',
                    'financial',
                    'community',
                    'partner_offer',
                    'resource',
                    'other',
                ]),
            ],
            'provider_name' => [
                'nullable',
                'string',
                'max:190',
            ],
            'description' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'eligibility_note' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'terms' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'external_url' => [
                'nullable',
                'url',
                'max:512',
            ],
            'requires_request' => [
                'required',
                'boolean',
            ],
            'max_requests' => [
                'nullable',
                'integer',
                'min:1',
                'max:1000000',
            ],
            'starts_at' => [
                'nullable',
                'date',
            ],
            'ends_at' => [
                'nullable',
                'date',
                'after_or_equal:starts_at',
            ],
            'status' => [
                'required',
                'string',
                Rule::in([
                    'draft',
                    'published',
                    'inactive',
                ]),
            ],
        ]);
    }

    private function availableBenefit(
        int $benefitId
    ): object {
        $now = CarbonImmutable::now();

        $benefit = DB::table(self::BENEFITS)
            ->where('id', $benefitId)
            ->where('status', 'published')
            ->where(
                function ($q) use ($now): void {
                    $q->whereNull('starts_at')
                        ->orWhere(
                            'starts_at',
                            '<=',
                            $now
                        );
                }
            )
            ->where(
                function ($q) use ($now): void {
                    $q->whereNull('ends_at')
                        ->orWhere(
                            'ends_at',
                            '>=',
                            $now
                        );
                }
            )
            ->first();

        abort_unless($benefit, 404);

        return $benefit;
    }

    private function presentBenefit(
        object $row,
        ?object $requestRow,
        int $activeRequests
    ): array {
        $remaining = null;

        if ($row->max_requests !== null) {
            $remaining = max(
                0,
                (int) $row->max_requests
                    - $activeRequests
            );
        }

        return [
            'id' => (int) $row->id,
            'title' => $row->title,
            'slug' => $row->slug,
            'category' => $row->category,
            'provider_name' =>
                $row->provider_name,
            'description' =>
                $row->description,
            'eligibility_note' =>
                $row->eligibility_note,
            'terms' => $row->terms,
            'external_url' =>
                $row->external_url,
            'requires_request' =>
                (bool) $row->requires_request,
            'max_requests' =>
                $row->max_requests !== null
                    ? (int) $row->max_requests
                    : null,
            'remaining_request_capacity' =>
                $remaining,
            'starts_at' => $row->starts_at,
            'ends_at' => $row->ends_at,
            'status' => $row->status,
            'request' => $requestRow
                ? $this->presentRequest(
                    $requestRow
                )
                : null,
            'advisory' => [
                'eligibility_guaranteed'
                    => false,
                'provider_endorsement_implied'
                    => false,
            ],
        ];
    }

    private function presentRequest(
        object $row
    ): array {
        return [
            'id' => (int) $row->id,
            'benefit_id' =>
                (int) $row->benefit_id,
            'status' => $row->status,
            'member_note' =>
                $row->member_note,
            'admin_note' =>
                $row->admin_note,
            'requested_at' =>
                $row->requested_at,
            'approved_at' =>
                $row->approved_at,
            'declined_at' =>
                $row->declined_at,
            'fulfilled_at' =>
                $row->fulfilled_at,
            'cancelled_at' =>
                $row->cancelled_at,
        ];
    }

    private function uniqueSlug(
        string $value,
        ?int $ignoreId = null
    ): string {
        $base = Str::slug($value);

        if ($base === '') {
            $base = 'benefit';
        }

        $slug = $base;
        $counter = 2;

        while (true) {
            $query = DB::table(
                self::BENEFITS
            )->where('slug', $slug);

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

    private function notifyMember(
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
                '/nurselink-benefits.html',
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
                    '/nurselink-benefit-management.html',
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
                $user->is_admin
                ?? false
            )
            || (bool) (
                $user->is_super_admin
                ?? false
            ),
            403,
            'Administrator access is required for member benefit management.'
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
            'target_type' => 'member_benefit',
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
