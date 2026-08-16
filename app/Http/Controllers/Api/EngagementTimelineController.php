<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EngagementTimelineController extends Controller
{
    public function member(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $limit = max(
            10,
            min(
                100,
                (int) $request->query(
                    'limit',
                    40
                )
            )
        );

        $items = collect();

        if (
            Schema::hasTable(
                'nurselink_chapter_memberships'
            )
            && Schema::hasTable(
                'nurselink_chapters'
            )
        ) {
            $rows = DB::table(
                'nurselink_chapter_memberships as cm'
            )
                ->join(
                    'nurselink_chapters as c',
                    'c.id',
                    '=',
                    'cm.chapter_id'
                )
                ->where(
                    'cm.user_id',
                    $userId
                )
                ->select([
                    'cm.id',
                    'cm.status',
                    'cm.chapter_role',
                    'cm.is_primary',
                    'cm.updated_at',
                    'c.name',
                ])
                ->get();

            foreach ($rows as $row) {
                $items->push([
                    'module' => 'chapters',
                    'activity_type' =>
                        'chapter_membership',
                    'title' => $row->name,
                    'detail' =>
                        'Chapter membership: '
                        . $this->label(
                            $row->status
                        ),
                    'status' =>
                        $row->status,
                    'occurred_at' =>
                        $row->updated_at,
                    'url' =>
                        '/nurselink-chapters.html',
                    'metadata' => [
                        'chapter_role' =>
                            $row->chapter_role,
                        'is_primary' =>
                            (bool)
                                $row->is_primary,
                    ],
                ]);
            }
        }

        if (
            Schema::hasTable(
                'nurselink_event_registrations'
            )
            && Schema::hasTable(
                'nurselink_events'
            )
        ) {
            $rows = DB::table(
                'nurselink_event_registrations as er'
            )
                ->join(
                    'nurselink_events as e',
                    'e.id',
                    '=',
                    'er.event_id'
                )
                ->where(
                    'er.user_id',
                    $userId
                )
                ->select([
                    'er.status',
                    'er.updated_at',
                    'e.title',
                    'e.starts_at',
                    'e.delivery_mode',
                ])
                ->get();

            foreach ($rows as $row) {
                $items->push([
                    'module' => 'events',
                    'activity_type' =>
                        'event_registration',
                    'title' => $row->title,
                    'detail' =>
                        'Event participation: '
                        . $this->label(
                            $row->status
                        ),
                    'status' =>
                        $row->status,
                    'occurred_at' =>
                        $row->updated_at,
                    'url' =>
                        '/nurselink-events.html',
                    'metadata' => [
                        'starts_at' =>
                            $row->starts_at,
                        'delivery_mode' =>
                            $row->delivery_mode,
                    ],
                ]);
            }
        }

        if (
            Schema::hasTable(
                'nurselink_mentoring_requests'
            )
        ) {
            $rows = DB::table(
                'nurselink_mentoring_requests'
            )
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
                ->select([
                    'mentor_user_id',
                    'mentee_user_id',
                    'status',
                    'focus_area',
                    'updated_at',
                ])
                ->get();

            foreach ($rows as $row) {
                $role = (string)
                    $row->mentor_user_id
                    === $userId
                        ? 'mentor'
                        : 'mentee';

                $items->push([
                    'module' =>
                        'mentoring',
                    'activity_type' =>
                        'mentoring_request',
                    'title' =>
                        'Mentoring connection',
                    'detail' =>
                        ucfirst($role)
                        . ' · '
                        . $this->label(
                            $row->status
                        ),
                    'status' =>
                        $row->status,
                    'occurred_at' =>
                        $row->updated_at,
                    'url' =>
                        '/nurselink-mentoring.html',
                    'metadata' => [
                        'role' => $role,
                        'focus_area' =>
                            $row->focus_area,
                    ],
                ]);
            }
        }

        if (
            Schema::hasTable(
                'nurselink_benefit_requests'
            )
            && Schema::hasTable(
                'nurselink_member_benefits'
            )
        ) {
            $rows = DB::table(
                'nurselink_benefit_requests as br'
            )
                ->join(
                    'nurselink_member_benefits as b',
                    'b.id',
                    '=',
                    'br.benefit_id'
                )
                ->where(
                    'br.user_id',
                    $userId
                )
                ->select([
                    'br.status',
                    'br.updated_at',
                    'b.title',
                    'b.category',
                ])
                ->get();

            foreach ($rows as $row) {
                $items->push([
                    'module' =>
                        'benefits',
                    'activity_type' =>
                        'benefit_request',
                    'title' =>
                        $row->title,
                    'detail' =>
                        'Benefit request: '
                        . $this->label(
                            $row->status
                        ),
                    'status' =>
                        $row->status,
                    'occurred_at' =>
                        $row->updated_at,
                    'url' =>
                        '/nurselink-benefits.html',
                    'metadata' => [
                        'category' =>
                            $row->category,
                    ],
                ]);
            }
        }

        if (
            Schema::hasTable(
                'nurselink_saved_benefits'
            )
            && Schema::hasTable(
                'nurselink_member_benefits'
            )
        ) {
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
                ->select([
                    'sb.created_at',
                    'b.title',
                    'b.category',
                    'b.ends_at',
                ])
                ->get();

            foreach ($rows as $row) {
                $items->push([
                    'module' =>
                        'benefits',
                    'activity_type' =>
                        'benefit_saved',
                    'title' =>
                        $row->title,
                    'detail' =>
                        'Saved benefit',
                    'status' => 'saved',
                    'occurred_at' =>
                        $row->created_at,
                    'url' =>
                        '/nurselink-benefits.html',
                    'metadata' => [
                        'category' =>
                            $row->category,
                        'ends_at' =>
                            $row->ends_at,
                    ],
                ]);
            }
        }

        $sorted = $items
            ->filter(
                fn (array $row): bool =>
                    ! empty(
                        $row['occurred_at']
                    )
            )
            ->sortByDesc(
                function (
                    array $row
                ): int {
                    try {
                        return CarbonImmutable::parse(
                            $row['occurred_at']
                        )->getTimestamp();
                    } catch (\Throwable) {
                        return 0;
                    }
                }
            )
            ->take($limit)
            ->values();

        return response()->json([
            'data' => $sorted,
            'meta' => [
                'limit' => $limit,
                'private_messages_exposed'
                    => false,
                'member_notes_exposed'
                    => false,
                'private_contact_details_exposed'
                    => false,
                'message' =>
                    'This timeline summarizes NurseLink community participation only. It is not a professional credential, employment record, regulatory record or official CPD transcript.',
            ],
        ]);
    }

    public function adminSummary(
        Request $request
    ): JsonResponse {
        $this->requireAdministratorSession(
            $request
        );

        $days = max(
            7,
            min(
                90,
                (int) $request->query(
                    'days',
                    30
                )
            )
        );

        $today =
            CarbonImmutable::today();
        $since =
            $today->subDays(
                $days - 1
            );

        $definitions = [
            'chapters' => [
                'table' =>
                    'nurselink_chapter_memberships',
                'date' => 'created_at',
            ],
            'events' => [
                'table' =>
                    'nurselink_event_registrations',
                'date' => 'created_at',
            ],
            'mentoring' => [
                'table' =>
                    'nurselink_mentoring_requests',
                'date' => 'created_at',
            ],
            'benefit_requests' => [
                'table' =>
                    'nurselink_benefit_requests',
                'date' => 'created_at',
            ],
            'benefit_saves' => [
                'table' =>
                    'nurselink_saved_benefits',
                'date' => 'created_at',
            ],
        ];

        $daily = [];
        $totals = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $since
                ->addDays($i)
                ->toDateString();

            $daily[$date] = [
                'date' => $date,
                'chapters' => 0,
                'events' => 0,
                'mentoring' => 0,
                'benefit_requests' => 0,
                'benefit_saves' => 0,
            ];
        }

        foreach (
            $definitions as $key => $definition
        ) {
            $totals[$key] = 0;

            if (
                ! Schema::hasTable(
                    $definition['table']
                )
            ) {
                continue;
            }

            $rows = DB::table(
                $definition['table']
            )
                ->where(
                    $definition['date'],
                    '>=',
                    $since->startOfDay()
                )
                ->select(
                    DB::raw(
                        'DATE('
                        . $definition['date']
                        . ') AS activity_date'
                    ),
                    DB::raw(
                        'COUNT(*) AS aggregate_count'
                    )
                )
                ->groupBy(
                    'activity_date'
                )
                ->get();

            foreach ($rows as $row) {
                $date = (string)
                    $row->activity_date;

                $count = (int)
                    $row->aggregate_count;

                $totals[$key] += $count;

                if (
                    array_key_exists(
                        $date,
                        $daily
                    )
                ) {
                    $daily[$date][$key] =
                        $count;
                }
            }
        }

        $combined =
            array_sum($totals);

        return response()->json([
            'data' => [
                'period_days' =>
                    $days,
                'since' =>
                    $since->toDateString(),
                'through' =>
                    $today->toDateString(),
                'totals' =>
                    $totals,
                'combined_new_activity_records'
                    => $combined,
                'daily' =>
                    array_values($daily),
                'privacy' => [
                    'aggregate_only' => true,
                    'user_ids_exposed' => false,
                    'member_names_exposed'
                        => false,
                    'private_messages_exposed'
                        => false,
                    'benefit_notes_exposed'
                        => false,
                ],
                'interpretation' =>
                    'Counts represent new NurseLink participation records during the selected period and should not be interpreted as licensure, employment, regulatory or official CPD metrics.',
            ],
        ]);
    }

    private function label(
        ?string $value
    ): string {
        return ucwords(
            str_replace(
                '_',
                ' ',
                (string) $value
            )
        );
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
            'Administrator access is required for engagement activity analytics.'
        );
    }
}
