<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EngagementController extends Controller
{
    public function memberSummary(
        Request $request
    ): JsonResponse {
        $userId =
            (string) $request->user()->getKey();

        $chapters = $this->chapterSummary(
            $userId
        );

        $events = $this->eventSummary(
            $userId
        );

        $mentoring = $this->mentoringSummary(
            $userId
        );

        $benefits = $this->benefitSummary(
            $userId
        );

        return response()->json([
            'data' => [
                'chapters' => $chapters,
                'events' => $events,
                'mentoring' => $mentoring,
                'benefits' => $benefits,
                'recommended_actions' =>
                    $this->recommendedActions(
                        $chapters,
                        $events,
                        $mentoring,
                        $benefits
                    ),
                'privacy' => [
                    'private_contact_details_exposed'
                        => false,
                    'private_messages_exposed'
                        => false,
                ],
                'advisory' => [
                    'engagement_is_professional_credential'
                        => false,
                    'message' =>
                        'NurseLink engagement activity is a community participation summary and is not a professional license, employment credential, regulator standing or official CPD certification.',
                ],
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

        $chapterSummary = [
            'total' => 0,
            'active' => 0,
            'pending_requests' => 0,
            'active_memberships' => 0,
        ];

        if (
            Schema::hasTable(
                'nurselink_chapters'
            )
        ) {
            $chapterSummary['total'] =
                DB::table(
                    'nurselink_chapters'
                )->count();

            $chapterSummary['active'] =
                DB::table(
                    'nurselink_chapters'
                )
                    ->where(
                        'status',
                        'active'
                    )
                    ->count();
        }

        if (
            Schema::hasTable(
                'nurselink_chapter_memberships'
            )
        ) {
            $chapterSummary[
                'pending_requests'
            ] = DB::table(
                'nurselink_chapter_memberships'
            )
                ->where(
                    'status',
                    'pending'
                )
                ->count();

            $chapterSummary[
                'active_memberships'
            ] = DB::table(
                'nurselink_chapter_memberships'
            )
                ->where(
                    'status',
                    'active'
                )
                ->count();
        }

        $eventSummary = [
            'published_upcoming' => 0,
            'registrations' => 0,
            'waitlisted' => 0,
            'attended' => 0,
        ];

        if (
            Schema::hasTable(
                'nurselink_events'
            )
        ) {
            $eventSummary[
                'published_upcoming'
            ] = DB::table(
                'nurselink_events'
            )
                ->where(
                    'status',
                    'published'
                )
                ->where(
                    'starts_at',
                    '>=',
                    $now
                )
                ->count();
        }

        if (
            Schema::hasTable(
                'nurselink_event_registrations'
            )
        ) {
            $eventSummary['registrations'] =
                DB::table(
                    'nurselink_event_registrations'
                )
                    ->whereIn(
                        'status',
                        [
                            'registered',
                            'attended',
                        ]
                    )
                    ->count();

            $eventSummary['waitlisted'] =
                DB::table(
                    'nurselink_event_registrations'
                )
                    ->where(
                        'status',
                        'waitlisted'
                    )
                    ->count();

            $eventSummary['attended'] =
                DB::table(
                    'nurselink_event_registrations'
                )
                    ->where(
                        'status',
                        'attended'
                    )
                    ->count();
        }

        $mentoringSummary = [
            'profiles' => 0,
            'discoverable' => 0,
            'open_requests' => 0,
            'accepted_relationships' => 0,
            'completed_relationships' => 0,
        ];

        if (
            Schema::hasTable(
                'nurselink_mentoring_profiles'
            )
        ) {
            $mentoringSummary['profiles'] =
                DB::table(
                    'nurselink_mentoring_profiles'
                )->count();

            $mentoringSummary['discoverable'] =
                DB::table(
                    'nurselink_mentoring_profiles'
                )
                    ->where(
                        'discoverable',
                        true
                    )
                    ->count();
        }

        if (
            Schema::hasTable(
                'nurselink_mentoring_requests'
            )
        ) {
            $mentoringSummary[
                'open_requests'
            ] = DB::table(
                'nurselink_mentoring_requests'
            )
                ->where(
                    'status',
                    'requested'
                )
                ->count();

            $mentoringSummary[
                'accepted_relationships'
            ] = DB::table(
                'nurselink_mentoring_requests'
            )
                ->where(
                    'status',
                    'accepted'
                )
                ->count();

            $mentoringSummary[
                'completed_relationships'
            ] = DB::table(
                'nurselink_mentoring_requests'
            )
                ->where(
                    'status',
                    'completed'
                )
                ->count();
        }

        $benefitSummary = [
            'published_available' => 0,
            'requested' => 0,
            'approved' => 0,
            'fulfilled' => 0,
        ];

        if (
            Schema::hasTable(
                'nurselink_member_benefits'
            )
        ) {
            $benefitSummary[
                'published_available'
            ] = DB::table(
                'nurselink_member_benefits'
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
                ->count();
        }

        if (
            Schema::hasTable(
                'nurselink_benefit_requests'
            )
        ) {
            foreach (
                [
                    'requested',
                    'approved',
                    'fulfilled',
                ] as $status
            ) {
                $benefitSummary[$status] =
                    DB::table(
                        'nurselink_benefit_requests'
                    )
                        ->where(
                            'status',
                            $status
                        )
                        ->count();
            }
        }

        $chapterRows = [];

        if (
            Schema::hasTable(
                'nurselink_chapters'
            )
            && Schema::hasTable(
                'nurselink_chapter_memberships'
            )
        ) {
            $chapters = DB::table(
                'nurselink_chapters'
            )
                ->where(
                    'status',
                    'active'
                )
                ->orderBy('country')
                ->orderBy('region')
                ->orderBy('name')
                ->limit(500)
                ->get();

            foreach ($chapters as $chapter) {
                $chapterRows[] = [
                    'chapter_id' =>
                        (int) $chapter->id,
                    'name' => $chapter->name,
                    'region' => $chapter->region,
                    'country' => $chapter->country,
                    'active_members' =>
                        DB::table(
                            'nurselink_chapter_memberships'
                        )
                            ->where(
                                'chapter_id',
                                $chapter->id
                            )
                            ->where(
                                'status',
                                'active'
                            )
                            ->count(),
                    'pending_requests' =>
                        DB::table(
                            'nurselink_chapter_memberships'
                        )
                            ->where(
                                'chapter_id',
                                $chapter->id
                            )
                            ->where(
                                'status',
                                'pending'
                            )
                            ->count(),
                    'upcoming_events' =>
                        Schema::hasTable(
                            'nurselink_events'
                        )
                            ? DB::table(
                                'nurselink_events'
                            )
                                ->where(
                                    'chapter_id',
                                    $chapter->id
                                )
                                ->where(
                                    'status',
                                    'published'
                                )
                                ->where(
                                    'starts_at',
                                    '>=',
                                    $now
                                )
                                ->count()
                            : 0,
                ];
            }
        }

        return response()->json([
            'data' => [
                'chapters' => $chapterSummary,
                'events' => $eventSummary,
                'mentoring' =>
                    $mentoringSummary,
                'benefits' =>
                    $benefitSummary,
                'chapter_activity' =>
                    $chapterRows,
                'privacy' => [
                    'aggregate_only' => true,
                    'member_email_exposed' => false,
                    'mentoring_messages_exposed' => false,
                    'credential_data_exposed' => false,
                ],
            ],
        ]);
    }

    private function chapterSummary(
        string $userId
    ): array {
        if (
            ! Schema::hasTable(
                'nurselink_chapter_memberships'
            )
        ) {
            return [
                'active' => 0,
                'pending' => 0,
                'primary' => null,
            ];
        }

        $rows = DB::table(
            'nurselink_chapter_memberships as cm'
        )
            ->leftJoin(
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
                'cm.status',
                'cm.is_primary',
                'cm.chapter_role',
                'c.id as chapter_id',
                'c.name as chapter_name',
            ])
            ->get();

        $primary = $rows->first(
            fn ($row): bool =>
                $row->status === 'active'
                && (bool) $row->is_primary
        );

        return [
            'active' =>
                $rows->where(
                    'status',
                    'active'
                )->count(),
            'pending' =>
                $rows->where(
                    'status',
                    'pending'
                )->count(),
            'primary' => $primary
                ? [
                    'chapter_id' =>
                        (int)
                            $primary->chapter_id,
                    'name' =>
                        $primary->chapter_name,
                    'role' =>
                        $primary->chapter_role,
                ]
                : null,
        ];
    }

    private function eventSummary(
        string $userId
    ): array {
        if (
            ! Schema::hasTable(
                'nurselink_event_registrations'
            )
        ) {
            return [
                'upcoming_registered' => 0,
                'waitlisted' => 0,
                'attended' => 0,
                'next_event' => null,
            ];
        }

        $rows = DB::table(
            'nurselink_event_registrations as r'
        )
            ->join(
                'nurselink_events as e',
                'e.id',
                '=',
                'r.event_id'
            )
            ->where(
                'r.user_id',
                $userId
            )
            ->select([
                'r.status',
                'e.id as event_id',
                'e.title',
                'e.starts_at',
                'e.delivery_mode',
            ])
            ->orderBy('e.starts_at')
            ->get();

        $now = CarbonImmutable::now();

        $upcoming = $rows->filter(
            function ($row) use (
                $now
            ): bool {
                if (
                    ! in_array(
                        $row->status,
                        ['registered', 'attended'],
                        true
                    )
                ) {
                    return false;
                }

                try {
                    return CarbonImmutable::parse(
                        $row->starts_at
                    )->greaterThanOrEqualTo(
                        $now
                    );
                } catch (\Throwable) {
                    return false;
                }
            }
        );

        $next = $upcoming->first();

        return [
            'upcoming_registered' =>
                $upcoming->count(),
            'waitlisted' =>
                $rows->where(
                    'status',
                    'waitlisted'
                )->count(),
            'attended' =>
                $rows->where(
                    'status',
                    'attended'
                )->count(),
            'next_event' => $next
                ? [
                    'event_id' =>
                        (int)
                            $next->event_id,
                    'title' => $next->title,
                    'starts_at' =>
                        $next->starts_at,
                    'delivery_mode' =>
                        $next->delivery_mode,
                ]
                : null,
        ];
    }

    private function mentoringSummary(
        string $userId
    ): array {
        $profile = null;

        if (
            Schema::hasTable(
                'nurselink_mentoring_profiles'
            )
        ) {
            $profile = DB::table(
                'nurselink_mentoring_profiles'
            )
                ->where(
                    'user_id',
                    $userId
                )
                ->first();
        }

        $requests = collect();

        if (
            Schema::hasTable(
                'nurselink_mentoring_requests'
            )
        ) {
            $requests = DB::table(
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
                ->get();
        }

        return [
            'profile_configured' =>
                $profile !== null,
            'discoverable' =>
                (bool) (
                    $profile->discoverable
                    ?? false
                ),
            'role_preference' =>
                $profile->role_preference
                    ?? null,
            'pending_requests' =>
                $requests->where(
                    'status',
                    'requested'
                )->count(),
            'active_relationships' =>
                $requests->where(
                    'status',
                    'accepted'
                )->count(),
            'completed_relationships' =>
                $requests->where(
                    'status',
                    'completed'
                )->count(),
        ];
    }

    private function benefitSummary(
        string $userId
    ): array {
        $available = 0;
        $endingSoon = 0;

        if (
            Schema::hasTable(
                'nurselink_member_benefits'
            )
        ) {
            $now = CarbonImmutable::now();
            $inThirtyDays =
                $now->addDays(30);

            $availableQuery = DB::table(
                'nurselink_member_benefits'
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
                );

            $available =
                (clone $availableQuery)
                    ->count();

            $endingSoon =
                (clone $availableQuery)
                    ->whereNotNull(
                        'ends_at'
                    )
                    ->where(
                        'ends_at',
                        '<=',
                        $inThirtyDays
                    )
                    ->count();
        }

        $requests = collect();

        if (
            Schema::hasTable(
                'nurselink_benefit_requests'
            )
        ) {
            $requests = DB::table(
                'nurselink_benefit_requests'
            )
                ->where(
                    'user_id',
                    $userId
                )
                ->get();
        }

        $saved = 0;

        if (
            Schema::hasTable(
                'nurselink_saved_benefits'
            )
        ) {
            $saved = DB::table(
                'nurselink_saved_benefits'
            )
                ->where(
                    'user_id',
                    $userId
                )
                ->count();
        }

        return [
            'available' => $available,
            'ending_within_30_days' =>
                $endingSoon,
            'saved' => $saved,
            'requested' =>
                $requests->where(
                    'status',
                    'requested'
                )->count(),
            'approved' =>
                $requests->where(
                    'status',
                    'approved'
                )->count(),
            'fulfilled' =>
                $requests->where(
                    'status',
                    'fulfilled'
                )->count(),
        ];
    }

    private function recommendedActions(
        array $chapters,
        array $events,
        array $mentoring,
        array $benefits
    ): array {
        $actions = [];

        if (
            ($chapters['active'] ?? 0) === 0
        ) {
            $actions[] = [
                'priority' => 'recommended',
                'title' =>
                    'Explore NurseLink chapters',
                'message' =>
                    'Join a regional or professional-interest community to strengthen member connections.',
                'url' =>
                    '/nurselink-chapters.html',
            ];
        }

        if (
            ($events['upcoming_registered']
                ?? 0) === 0
        ) {
            $actions[] = [
                'priority' => 'recommended',
                'title' =>
                    'Browse upcoming events',
                'message' =>
                    'Review NurseLink webinars, workshops and community activities.',
                'url' =>
                    '/nurselink-events.html',
            ];
        }

        if (
            ! ($mentoring[
                'profile_configured'
            ] ?? false)
        ) {
            $actions[] = [
                'priority' => 'optional',
                'title' =>
                    'Set up mentoring preferences',
                'message' =>
                    'Create an opt-in mentoring profile for peer and professional support.',
                'url' =>
                    '/nurselink-mentoring.html',
            ];
        }

        if (
            ($benefits['available'] ?? 0) > 0
            && (
                ($benefits['requested'] ?? 0) === 0
                && ($benefits['approved'] ?? 0) === 0
                && ($benefits['fulfilled'] ?? 0) === 0
            )
        ) {
            $actions[] = [
                'priority' => 'optional',
                'title' =>
                    'Review member benefits',
                'message' =>
                    'Browse available NurseLink resources and support offers that may be relevant to you.',
                'url' =>
                    '/nurselink-benefits.html',
            ];
        }

        return array_slice(
            $actions,
            0,
            4
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
            'Administrator access is required for engagement analytics.'
        );
    }
}
