<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BenefitReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BenefitReminderController extends Controller
{
    public function member(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $now = CarbonImmutable::now();
        $inThirtyDays =
            $now->addDays(30);

        $rows = DB::table(
            'nurselink_saved_benefits as sb'
        )
            ->join(
                'nurselink_member_benefits as b',
                'b.id',
                '=',
                'sb.benefit_id'
            )
            ->where(
                'sb.user_id',
                $userId
            )
            ->where(
                'b.status',
                'published'
            )
            ->whereNotNull(
                'b.ends_at'
            )
            ->where(
                'b.ends_at',
                '>=',
                $now
            )
            ->where(
                'b.ends_at',
                '<=',
                $inThirtyDays
            )
            ->orderBy('b.ends_at')
            ->select([
                'b.id',
                'b.title',
                'b.category',
                'b.provider_name',
                'b.ends_at',
            ])
            ->get()
            ->map(
                function ($row) use (
                    $now
                ): array {
                    $end =
                        CarbonImmutable::parse(
                            $row->ends_at
                        );

                    $days = max(
                        0,
                        $now->startOfDay()
                            ->diffInDays(
                                $end->startOfDay(),
                                false
                            )
                    );

                    return [
                        'benefit_id' =>
                            (int) $row->id,
                        'title' =>
                            $row->title,
                        'category' =>
                            $row->category,
                        'provider_name' =>
                            $row->provider_name,
                        'ends_at' =>
                            $row->ends_at,
                        'days_remaining' =>
                            $days,
                        'reminder_window' =>
                            $days <= 7
                                ? 'ending_7'
                                : 'ending_30',
                        'availability_guaranteed'
                            => false,
                        'eligibility_guaranteed'
                            => false,
                    ];
                }
            )
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'count' => $rows->count(),
                'message' =>
                    'Saved benefit reminders are planning notices only. Availability and eligibility remain subject to the listing and provider terms.',
            ],
        ]);
    }

    public function adminSummary(
        Request $request,
        BenefitReminderService $service
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $now = CarbonImmutable::now();

        $eligible = 0;

        if (
            Schema::hasTable(
                'nurselink_saved_benefits'
            )
            && Schema::hasTable(
                'nurselink_member_benefits'
            )
            && Schema::hasTable(
                'nurselink_memberships'
            )
        ) {
            $eligible = DB::table(
                'nurselink_saved_benefits as sb'
            )
                ->join(
                    'nurselink_member_benefits as b',
                    'b.id',
                    '=',
                    'sb.benefit_id'
                )
                ->join(
                    'nurselink_memberships as m',
                    'm.user_id',
                    '=',
                    'sb.user_id'
                )
                ->where(
                    'm.status',
                    'approved'
                )
                ->where(
                    'm.standing',
                    'active'
                )
                ->where(
                    'b.status',
                    'published'
                )
                ->whereNotNull(
                    'b.ends_at'
                )
                ->where(
                    'b.ends_at',
                    '>=',
                    $now
                )
                ->where(
                    'b.ends_at',
                    '<=',
                    $now->addDays(30)
                )
                ->count();
        }

        return response()->json([
            'data' => [
                'currently_eligible_saved_benefits'
                    => $eligible,
                'delivery' =>
                    $service->summary(),
                'deduplicated' => true,
                'automatic_cron_created'
                    => false,
                'privacy' => [
                    'aggregate_only' => true,
                    'member_notes_exposed'
                        => false,
                    'private_contact_details_exposed'
                        => false,
                ],
            ],
        ]);
    }

    public function generate(
        Request $request,
        BenefitReminderService $service
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $result = $service->generate();

        $this->audit(
            $request,
            'benefit.reminders_generated',
            $result
        );

        return response()->json([
            'message' =>
                'Benefit reminder generation completed.',
            'data' => $result,
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
            'Administrator access is required for benefit reminder operations.'
        );
    }

    private function audit(
        Request $request,
        string $action,
        array $after
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
                'benefit_reminders',
            'target_id' => 'aggregate',
            'before_state' => null,
            'after_state' =>
                json_encode(
                    $after,
                    JSON_UNESCAPED_UNICODE
                ),
            'created_at' => now(),
        ]);
    }
}
