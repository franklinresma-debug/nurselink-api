<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BenefitIntelligenceController extends Controller
{
    private const BENEFITS =
        'nurselink_member_benefits';

    private const REQUESTS =
        'nurselink_benefit_requests';

    private const SAVED =
        'nurselink_saved_benefits';

    public function memberSummary(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $now = CarbonImmutable::now();
        $inSevenDays = $now->addDays(7);
        $inThirtyDays = $now->addDays(30);

        $availableQuery = DB::table(
            self::BENEFITS
        )
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

        $available = $availableQuery->count();

        $endingSeven = (clone $availableQuery)
            ->whereNotNull('ends_at')
            ->where(
                'ends_at',
                '<=',
                $inSevenDays
            )
            ->count();

        $endingThirty = (clone $availableQuery)
            ->whereNotNull('ends_at')
            ->where(
                'ends_at',
                '>',
                $inSevenDays
            )
            ->where(
                'ends_at',
                '<=',
                $inThirtyDays
            )
            ->count();

        $savedRows = Schema::hasTable(
            self::SAVED
        )
            ? DB::table(self::SAVED)
                ->where(
                    'user_id',
                    $userId
                )
                ->orderByDesc('created_at')
                ->get()
            : collect();

        $savedIds = $savedRows
            ->pluck('benefit_id')
            ->map(
                fn ($id): int => (int) $id
            )
            ->values()
            ->all();

        $requestCounts = [
            'requested' => 0,
            'approved' => 0,
            'fulfilled' => 0,
        ];

        if (
            Schema::hasTable(
                self::REQUESTS
            )
        ) {
            $rows = DB::table(
                self::REQUESTS
            )
                ->where(
                    'user_id',
                    $userId
                )
                ->whereIn(
                    'status',
                    array_keys(
                        $requestCounts
                    )
                )
                ->select(
                    'status',
                    DB::raw(
                        'COUNT(*) AS aggregate_count'
                    )
                )
                ->groupBy('status')
                ->get();

            foreach ($rows as $row) {
                $status =
                    (string) $row->status;

                if (
                    array_key_exists(
                        $status,
                        $requestCounts
                    )
                ) {
                    $requestCounts[
                        $status
                    ] = (int)
                        $row->aggregate_count;
                }
            }
        }

        return response()->json([
            'data' => [
                'available' => $available,
                'ending_within_7_days' =>
                    $endingSeven,
                'ending_within_30_days' =>
                    $endingThirty,
                'saved_count' =>
                    count($savedIds),
                'saved_benefit_ids' =>
                    $savedIds,
                'requests' =>
                    $requestCounts,
                'availability_states' => [
                    'ending_7',
                    'ending_30',
                    'current',
                    'no_end_date',
                ],
                'advisory' => [
                    'availability_is_guaranteed'
                        => false,
                    'eligibility_is_guaranteed'
                        => false,
                ],
            ],
        ]);
    }

    public function save(
        Request $request,
        int $benefitId
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $this->requireAvailableBenefit(
            $benefitId
        );

        $existing = DB::table(
            self::SAVED
        )
            ->where(
                'user_id',
                $userId
            )
            ->where(
                'benefit_id',
                $benefitId
            )
            ->first();

        if (! $existing) {
            DB::table(
                self::SAVED
            )->insert([
                'user_id' => $userId,
                'benefit_id' =>
                    $benefitId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit(
                $request,
                'benefit.saved',
                (string) $benefitId,
                null,
                [
                    'user_id' => $userId,
                    'benefit_id' =>
                        $benefitId,
                ]
            );
        }

        return response()->json([
            'message' =>
                'Benefit saved.',
            'data' => [
                'benefit_id' =>
                    $benefitId,
                'saved' => true,
            ],
        ]);
    }

    public function unsave(
        Request $request,
        int $benefitId
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $existing = DB::table(
            self::SAVED
        )
            ->where(
                'user_id',
                $userId
            )
            ->where(
                'benefit_id',
                $benefitId
            )
            ->first();

        if ($existing) {
            DB::table(
                self::SAVED
            )
                ->where(
                    'id',
                    $existing->id
                )
                ->delete();

            $this->audit(
                $request,
                'benefit.unsaved',
                (string) $benefitId,
                $existing,
                null
            );
        }

        return response()->json([
            'message' =>
                'Benefit removed from saved items.',
            'data' => [
                'benefit_id' =>
                    $benefitId,
                'saved' => false,
            ],
        ]);
    }

    public function adminSummary(
        Request $request
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $now = CarbonImmutable::now();
        $inSevenDays = $now->addDays(7);
        $inThirtyDays = $now->addDays(30);

        $benefits = DB::table(
            self::BENEFITS
        )
            ->select(
                'status',
                'category',
                'ends_at',
                'requires_request'
            )
            ->get();

        $published = $benefits->where(
            'status',
            'published'
        );

        $activePublished =
            $published->filter(
                function ($row) use (
                    $now
                ): bool {
                    try {
                        $endsAt =
                            $row->ends_at
                                ? CarbonImmutable::parse(
                                    $row->ends_at
                                )
                                : null;
                    } catch (\Throwable) {
                        return true;
                    }

                    return $endsAt === null
                        || $endsAt
                            ->greaterThanOrEqualTo(
                                $now
                            );
                }
            );

        $endingSeven =
            $activePublished->filter(
                function ($row) use (
                    $now,
                    $inSevenDays
                ): bool {
                    if (! $row->ends_at) {
                        return false;
                    }

                    try {
                        $date =
                            CarbonImmutable::parse(
                                $row->ends_at
                            );
                    } catch (\Throwable) {
                        return false;
                    }

                    return $date
                        ->greaterThanOrEqualTo(
                            $now
                        )
                        && $date
                            ->lessThanOrEqualTo(
                                $inSevenDays
                            );
                }
            )->count();

        $endingThirty =
            $activePublished->filter(
                function ($row) use (
                    $inSevenDays,
                    $inThirtyDays
                ): bool {
                    if (! $row->ends_at) {
                        return false;
                    }

                    try {
                        $date =
                            CarbonImmutable::parse(
                                $row->ends_at
                            );
                    } catch (\Throwable) {
                        return false;
                    }

                    return $date
                        ->greaterThan(
                            $inSevenDays
                        )
                        && $date
                            ->lessThanOrEqualTo(
                                $inThirtyDays
                            );
                }
            )->count();

        $requestCounts = [];

        if (
            Schema::hasTable(
                self::REQUESTS
            )
        ) {
            $requestCounts = DB::table(
                self::REQUESTS
            )
                ->select(
                    'status',
                    DB::raw(
                        'COUNT(*) AS aggregate_count'
                    )
                )
                ->groupBy('status')
                ->get()
                ->mapWithKeys(
                    fn ($row): array => [
                        (string)
                            $row->status
                            => (int)
                                $row
                                    ->aggregate_count,
                    ]
                )
                ->all();
        }

        $savedCount =
            Schema::hasTable(self::SAVED)
                ? DB::table(
                    self::SAVED
                )->count()
                : 0;

        $categoryCounts = $published
            ->groupBy('category')
            ->map(
                fn ($group): int =>
                    $group->count()
            )
            ->all();

        return response()->json([
            'data' => [
                'benefits' => [
                    'total' =>
                        $benefits->count(),
                    'published' =>
                        $published->count(),
                    'active_published' =>
                        $activePublished
                            ->count(),
                    'ending_within_7_days' =>
                        $endingSeven,
                    'ending_within_30_days' =>
                        $endingThirty,
                    'request_enabled' =>
                        $published
                            ->where(
                                'requires_request',
                                true
                            )
                            ->count(),
                ],
                'requests' =>
                    $requestCounts,
                'saved_total' =>
                    $savedCount,
                'categories' =>
                    $categoryCounts,
                'privacy' => [
                    'aggregate_only' => true,
                    'member_private_notes_exposed'
                        => false,
                    'uploaded_documents_exposed'
                        => false,
                ],
            ],
        ]);
    }

    private function requireAvailableBenefit(
        int $benefitId
    ): object {
        $now = CarbonImmutable::now();

        $benefit = DB::table(
            self::BENEFITS
        )
            ->where(
                'id',
                $benefitId
            )
            ->where(
                'status',
                'published'
            )
            ->where(
                function ($q) use (
                    $now
                ): void {
                    $q->whereNull(
                        'starts_at'
                    )->orWhere(
                        'starts_at',
                        '<=',
                        $now
                    );
                }
            )
            ->where(
                function ($q) use (
                    $now
                ): void {
                    $q->whereNull(
                        'ends_at'
                    )->orWhere(
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
            'Administrator access is required for benefit analytics.'
        );
    }

    private function audit(
        Request $request,
        string $action,
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
            'target_type' =>
                'saved_benefit',
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
            'created_at' => now(),
        ]);
    }
}
