<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class EventsController extends Controller
{
    private const EVENTS = 'nurselink_events';
    private const REGISTRATIONS = 'nurselink_event_registrations';

    public function memberIndex(Request $request): JsonResponse
    {
        $userId = (string) $request->user()->getKey();

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:60'],
            'mode' => ['nullable', 'string', Rule::in(['all', 'online', 'onsite', 'hybrid'])],
            'scope' => ['nullable', 'string', Rule::in(['upcoming', 'registered', 'all'])],
            'search' => ['nullable', 'string', 'max:190'],
        ]);

        $activeChapterIds = Schema::hasTable(
            'nurselink_chapter_memberships'
        )
            ? DB::table('nurselink_chapter_memberships')
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->pluck('chapter_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $query = DB::table(self::EVENTS)
            ->where('status', 'published')
            ->where(
                'starts_at',
                '>=',
                CarbonImmutable::now()->subHours(6)
            )
            ->where(function ($q) use (
                $activeChapterIds
            ): void {
                $q->whereNull('chapter_id');

                if ($activeChapterIds !== []) {
                    $q->orWhereIn(
                        'chapter_id',
                        $activeChapterIds
                    );
                }
            });

        if (! empty($data['type'])) {
            $query->where('event_type', $data['type']);
        }

        if (($data['mode'] ?? 'all') !== 'all') {
            $query->where('delivery_mode', $data['mode']);
        }

        if (! empty($data['search'])) {
            $search = '%' . trim($data['search']) . '%';
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', $search)
                    ->orWhere('description', 'like', $search)
                    ->orWhere('organizer', 'like', $search)
                    ->orWhere('venue', 'like', $search);
            });
        }

        $events = $query
            ->orderBy('starts_at')
            ->limit(500)
            ->get();

        $eventIds = $events->pluck('id')->map(fn ($id) => (int) $id)->all();

        $registrationMap = $eventIds === []
            ? []
            : DB::table(self::REGISTRATIONS)
                ->where('user_id', $userId)
                ->whereIn('event_id', $eventIds)
                ->get()
                ->mapWithKeys(fn ($row): array => [(int) $row->event_id => $row])
                ->all();

        $counts = $eventIds === []
            ? []
            : DB::table(self::REGISTRATIONS)
                ->whereIn('event_id', $eventIds)
                ->whereIn('status', ['registered', 'attended'])
                ->select('event_id', DB::raw('COUNT(*) AS aggregate_count'))
                ->groupBy('event_id')
                ->get()
                ->mapWithKeys(fn ($row): array => [(int) $row->event_id => (int) $row->aggregate_count])
                ->all();

        $rows = $events
            ->map(function ($event) use ($registrationMap, $counts): array {
                $id = (int) $event->id;
                return $this->presentEvent(
                    $event,
                    $registrationMap[$id] ?? null,
                    $counts[$id] ?? 0
                );
            });

        if (($data['scope'] ?? 'upcoming') === 'registered') {
            $rows = $rows->filter(
                fn (array $row): bool =>
                    in_array($row['registration']['status'] ?? null, ['registered', 'attended'], true)
            );
        }

        return response()->json([
            'data' => $rows->values(),
            'meta' => [
                'registration_is_membership_service' => true,
                'official_cpd_award' => false,
            ],
        ]);
    }

    public function register(Request $request, int $eventId): JsonResponse
    {
        $userId = (string) $request->user()->getKey();

        $event = DB::table(self::EVENTS)
            ->where('id', $eventId)
            ->where('status', 'published')
            ->first();

        abort_unless($event, 404);

        $now = CarbonImmutable::now();

        if (CarbonImmutable::parse($event->starts_at)->isPast()) {
            return response()->json(['message' => 'Registration is closed because this event has already started.'], 422);
        }

        if ($event->registration_deadline
            && $now->greaterThan(CarbonImmutable::parse($event->registration_deadline))) {
            return response()->json(['message' => 'Registration deadline has passed.'], 422);
        }

        if (! (bool) $event->registration_required) {
            return response()->json(['message' => 'This event does not require registration.'], 422);
        }

        $existing = DB::table(self::REGISTRATIONS)
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->first();

        $registeredCount = DB::table(self::REGISTRATIONS)
            ->where('event_id', $eventId)
            ->whereIn('status', ['registered', 'attended'])
            ->count();

        $status = 'registered';

        if ($event->capacity !== null && $registeredCount >= (int) $event->capacity) {
            $status = 'waitlisted';
        }

        if ($existing) {
            if (in_array($existing->status, ['registered', 'waitlisted', 'attended'], true)) {
                return response()->json([
                    'message' => 'You already have an active registration for this event.',
                    'data' => $this->presentRegistration($existing),
                ], 409);
            }

            DB::table(self::REGISTRATIONS)
                ->where('id', $existing->id)
                ->update([
                    'status' => $status,
                    'registered_at' => now(),
                    'cancelled_at' => null,
                    'updated_at' => now(),
                ]);

            $registration = DB::table(self::REGISTRATIONS)
                ->where('id', $existing->id)
                ->first();
        } else {
            $id = DB::table(self::REGISTRATIONS)->insertGetId([
                'user_id' => $userId,
                'event_id' => $eventId,
                'status' => $status,
                'registered_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $registration = DB::table(self::REGISTRATIONS)
                ->where('id', $id)
                ->first();
        }

        $this->notifyRegistration($userId, $event, $status);

        return response()->json([
            'message' => $status === 'waitlisted'
                ? 'The event is currently full. You have been added to the waitlist.'
                : 'Event registration confirmed.',
            'data' => $this->presentRegistration($registration),
        ]);
    }

    public function cancel(Request $request, int $eventId): JsonResponse
    {
        $userId = (string) $request->user()->getKey();

        $registration = DB::table(self::REGISTRATIONS)
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->first();

        abort_unless($registration, 404);

        if (! in_array($registration->status, ['registered', 'waitlisted'], true)) {
            return response()->json([
                'message' => 'This registration cannot be cancelled in its current status.',
            ], 422);
        }

        DB::table(self::REGISTRATIONS)
            ->where('id', $registration->id)
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Event registration cancelled.']);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireAdministratorSession($request);

        $rows = DB::table(self::EVENTS)
            ->orderByDesc('starts_at')
            ->limit(1000)
            ->get();

        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $counts = $ids === [] ? [] : DB::table(self::REGISTRATIONS)
            ->whereIn('event_id', $ids)
            ->select('event_id', 'status', DB::raw('COUNT(*) AS aggregate_count'))
            ->groupBy('event_id', 'status')
            ->get()
            ->groupBy(fn ($row) => (int) $row->event_id)
            ->map(fn ($group) => $group->mapWithKeys(
                fn ($row): array => [(string) $row->status => (int) $row->aggregate_count]
            )->all())
            ->all();

        return response()->json([
            'data' => $rows->map(fn ($row): array => [
                ...$this->presentEvent($row, null, 0),
                'registration_counts' => $counts[(int) $row->id] ?? [],
            ])->values(),
        ]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $this->requireAdministratorSession($request);
        $data = $this->validatedEvent($request);

        $id = DB::table(self::EVENTS)->insertGetId([
            ...$data,
            'created_by' => (string) $request->user()->getKey(),
            'updated_by' => (string) $request->user()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table(self::EVENTS)->where('id', $id)->first();

        $this->audit($request, 'event.created', (string) $id, null, $row);

        return response()->json([
            'message' => 'Event / program created.',
            'data' => $this->presentEvent($row, null, 0),
        ], 201);
    }

    public function adminUpdate(Request $request, int $eventId): JsonResponse
    {
        $this->requireAdministratorSession($request);

        $before = DB::table(self::EVENTS)->where('id', $eventId)->first();
        abort_unless($before, 404);

        $data = $this->validatedEvent($request);

        DB::table(self::EVENTS)
            ->where('id', $eventId)
            ->update([
                ...$data,
                'updated_by' => (string) $request->user()->getKey(),
                'updated_at' => now(),
            ]);

        $after = DB::table(self::EVENTS)->where('id', $eventId)->first();
        $this->audit($request, 'event.updated', (string) $eventId, $before, $after);

        return response()->json([
            'message' => 'Event / program updated.',
            'data' => $this->presentEvent($after, null, 0),
        ]);
    }

    public function adminRegistrations(Request $request, int $eventId): JsonResponse
    {
        $this->requireAdministratorSession($request);

        $event = DB::table(self::EVENTS)->where('id', $eventId)->first();
        abort_unless($event, 404);

        $rows = DB::table(self::REGISTRATIONS . ' as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.event_id', $eventId)
            ->orderByDesc('r.registered_at')
            ->select([
                'r.id',
                'r.user_id',
                'r.status',
                'r.registered_at',
                'r.cancelled_at',
                'r.attended_at',
                'u.email',
            ])
            ->get();

        return response()->json([
            'data' => $rows,
            'event' => [
                'id' => (int) $event->id,
                'title' => $event->title,
            ],
        ]);
    }

    public function adminRegistrationStatus(
        Request $request,
        int $eventId,
        int $registrationId
    ): JsonResponse {
        $this->requireAdministratorSession($request);

        $before = DB::table(self::REGISTRATIONS)
            ->where('id', $registrationId)
            ->where('event_id', $eventId)
            ->first();

        abort_unless($before, 404);

        $data = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(['registered', 'waitlisted', 'cancelled', 'attended', 'no_show']),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::table(self::REGISTRATIONS)
            ->where('id', $registrationId)
            ->update([
                'status' => $data['status'],
                'notes' => $data['notes'] ?? $before->notes,
                'attended_at' => $data['status'] === 'attended'
                    ? ($before->attended_at ?: now())
                    : $before->attended_at,
                'cancelled_at' => $data['status'] === 'cancelled'
                    ? ($before->cancelled_at ?: now())
                    : $before->cancelled_at,
                'updated_at' => now(),
            ]);

        $after = DB::table(self::REGISTRATIONS)
            ->where('id', $registrationId)
            ->first();

        $this->audit(
            $request,
            'event.registration_status_changed',
            (string) $registrationId,
            $before,
            $after
        );

        return response()->json([
            'message' => 'Registration status updated.',
            'data' => $this->presentRegistration($after),
        ]);
    }

    private function validatedEvent(Request $request): array
    {
        return $request->validate([
            'chapter_id' => [
                'nullable',
                'integer',
                Rule::exists(
                    'nurselink_chapters',
                    'id'
                )->where(
                    fn ($query) =>
                        $query->where(
                            'status',
                            'active'
                        )
                ),
            ],
            'title' => ['required', 'string', 'max:190'],
            'event_type' => [
                'required',
                'string',
                Rule::in([
                    'webinar',
                    'workshop',
                    'conference',
                    'orientation',
                    'networking',
                    'mentoring',
                    'community_service',
                    'program',
                    'other',
                ]),
            ],
            'delivery_mode' => [
                'required',
                'string',
                Rule::in(['online', 'onsite', 'hybrid']),
            ],
            'description' => ['nullable', 'string', 'max:10000'],
            'organizer' => ['nullable', 'string', 'max:190'],
            'venue' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'meeting_url' => ['nullable', 'url', 'max:512'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'status' => [
                'required',
                'string',
                Rule::in(['draft', 'published', 'cancelled', 'completed']),
            ],
            'member_only' => ['required', 'boolean'],
            'registration_required' => ['required', 'boolean'],
            'registration_deadline' => ['nullable', 'date', 'before_or_equal:starts_at'],
            'learning_hours' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'cpd_units_claimed' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
        ]);
    }

    private function presentEvent(object $row, ?object $registration, int $registeredCount): array
    {
        $capacity = $row->capacity !== null ? (int) $row->capacity : null;

        return [
            'id' => (int) $row->id,
            'chapter_id' => $row->chapter_id !== null
                ? (int) $row->chapter_id
                : null,
            'chapter_name' =>
                $this->chapterNameFor(
                    $row->chapter_id !== null
                        ? (int) $row->chapter_id
                        : null
                ),
            'title' => $row->title,
            'event_type' => $row->event_type,
            'delivery_mode' => $row->delivery_mode,
            'description' => $row->description,
            'organizer' => $row->organizer,
            'venue' => $row->venue,
            'city' => $row->city,
            'country' => $row->country,
            'meeting_url' => $row->meeting_url,
            'starts_at' => $row->starts_at,
            'ends_at' => $row->ends_at,
            'capacity' => $capacity,
            'registered_count' => $registeredCount,
            'remaining_capacity' => $capacity === null
                ? null
                : max(0, $capacity - $registeredCount),
            'status' => $row->status,
            'member_only' => (bool) $row->member_only,
            'registration_required' => (bool) $row->registration_required,
            'registration_deadline' => $row->registration_deadline,
            'learning_hours' => $row->learning_hours !== null ? (float) $row->learning_hours : null,
            'cpd_units_claimed' => $row->cpd_units_claimed !== null ? (float) $row->cpd_units_claimed : null,
            'cpd_units_are_official' => false,
            'registration' => $registration ? $this->presentRegistration($registration) : null,
        ];
    }

    private function presentRegistration(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'event_id' => (int) $row->event_id,
            'status' => $row->status,
            'registered_at' => $row->registered_at,
            'cancelled_at' => $row->cancelled_at,
            'attended_at' => $row->attended_at,
        ];
    }

    private function chapterNameFor(
        ?int $chapterId
    ): ?string {
        if (
            $chapterId === null
            || ! Schema::hasTable(
                'nurselink_chapters'
            )
        ) {
            return null;
        }

        $name = DB::table(
            'nurselink_chapters'
        )
            ->where('id', $chapterId)
            ->value('name');

        return $name !== null
            ? (string) $name
            : null;
    }

    private function notifyRegistration(string $userId, object $event, string $status): void
    {
        if (! Schema::hasTable('nurselink_notifications')) {
            return;
        }

        DB::table('nurselink_notifications')->insert([
            'user_id' => $userId,
            'type' => 'event.registration.' . $status,
            'severity' => $status === 'waitlisted' ? 'warning' : 'success',
            'title' => $status === 'waitlisted'
                ? 'Added to event waitlist'
                : 'Event registration confirmed',
            'message' => $status === 'waitlisted'
                ? 'You are on the waitlist for ' . $event->title . '.'
                : 'You are registered for ' . $event->title . '.',
            'action_url' => '/nurselink-events.html',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function requireAdministratorSession(Request $request): void
    {
        $user = $request->user();
        abort_unless($user, 401);

        $userId = (string) $user->getKey();
        $elevatedUserId = (string) $request->session()->get(
            'nurselink_admin_elevated_user_id',
            ''
        );
        $expiresAt = (int) $request->session()->get(
            'nurselink_admin_expires_at',
            0
        );

        abort_unless(
            $elevatedUserId !== ''
            && hash_equals($elevatedUserId, $userId)
            && $expiresAt >= time(),
            403,
            'A separate NurseLink Administrator Portal sign-in is required.'
        );

        $reviewRole = Schema::hasTable('nurselink_reviewer_access')
            ? strtolower((string) (
                DB::table('nurselink_reviewer_access')
                    ->where('user_id', $userId)
                    ->where('active', true)
                    ->value('role')
                ?? ''
            ))
            : '';

        $explicitSuperAdmin = Schema::hasTable('nurselink_super_admin_access')
            && DB::table('nurselink_super_admin_access')
                ->where('user_id', $userId)
                ->where('active', true)
                ->exists();

        abort_unless(
            $explicitSuperAdmin
            || in_array($reviewRole, ['admin', 'super_admin'], true)
            || (bool) ($user->is_admin ?? false)
            || (bool) ($user->is_super_admin ?? false),
            403,
            'Administrator access is required for event management.'
        );
    }

    private function audit(
        Request $request,
        string $action,
        string $targetId,
        ?object $before,
        ?object $after
    ): void {
        if (! Schema::hasTable('nurselink_review_audit')) {
            return;
        }

        DB::table('nurselink_review_audit')->insert([
            'reviewer_user_id' => (string) $request->user()->getKey(),
            'action' => $action,
            'target_type' => 'event',
            'target_id' => $targetId,
            'before_state' => $before
                ? json_encode($before, JSON_UNESCAPED_UNICODE)
                : null,
            'after_state' => $after
                ? json_encode($after, JSON_UNESCAPED_UNICODE)
                : null,
            'created_at' => now(),
        ]);
    }
}
