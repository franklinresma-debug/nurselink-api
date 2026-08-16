<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminMemberRegistryController extends Controller
{
    private const ELEVATION_TTL_SECONDS = 28800;
    private const FRONTEND_URL = 'https://app.amsertech.com';

    public function summary(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $approved = DB::table('nurselink_memberships')
            ->where('status', 'approved');

        $total = (clone $approved)->count();

        $approved30 = (clone $approved)
            ->where('approved_at', '>=', now()->subDays(30))
            ->count();

        $approvedYear = (clone $approved)
            ->whereYear('approved_at', now()->year)
            ->count();

        $approvedUserIds = (clone $approved)
            ->pluck('user_id');

        $activeUserIds = (clone $approved)
            ->where('standing', 'active')
            ->pluck('user_id');

        $withVerifiedCredential = 0;
        if (
            Schema::hasTable('nurselink_credentials_registry')
            && $approvedUserIds->isNotEmpty()
        ) {
            $withVerifiedCredential = DB::table(
                'nurselink_credentials_registry'
            )
                ->whereIn('user_id', $approvedUserIds->all())
                ->where('verification_status', 'verified')
                ->distinct()
                ->count('user_id');
        }

        $publicProfiles = 0;
        if (
            Schema::hasTable('nurselink_public_profiles')
            && $activeUserIds->isNotEmpty()
        ) {
            $publicProfiles = DB::table('nurselink_public_profiles')
                ->whereIn('user_id', $activeUserIds->all())
                ->where('enabled', true)
                ->count();
        }

        $standingCounts = [];

        foreach (['active', 'suspended', 'inactive'] as $standing) {
            $standingCounts[$standing] = (clone $approved)
                ->where('standing', $standing)
                ->count();
        }

        return response()->json([
            'data' => [
                'total_approved' => $total,
                'approved_last_30_days' => $approved30,
                'approved_this_year' => $approvedYear,
                'with_verified_credentials' => $withVerifiedCredential,
                'public_profiles_enabled' => $publicProfiles,
                'active_members' => $standingCounts['active'],
                'suspended_members' => $standingCounts['suspended'],
                'inactive_members' => $standingCounts['inactive'],
                'registry_is_read_only' => true,
                'directory_is_read_only' => true,
                'lifecycle_managed_separately' => true,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:190'],
            'credential_state' => [
                'nullable',
                'string',
                Rule::in(['all', 'verified', 'attention']),
            ],
            'public_profile' => [
                'nullable',
                'string',
                Rule::in(['all', 'enabled', 'disabled']),
            ],
            'standing' => [
                'nullable',
                'string',
                Rule::in(['all', 'active', 'suspended', 'inactive']),
            ],
        ]);

        $membershipQuery = DB::table('nurselink_memberships')
            ->where('status', 'approved');

        $standingFilter = $data['standing'] ?? 'all';

        if ($standingFilter !== 'all') {
            $membershipQuery->where('standing', $standingFilter);
        }

        $memberships = $membershipQuery
            ->orderByRaw("CASE standing
                WHEN 'active' THEN 1
                WHEN 'suspended' THEN 2
                WHEN 'inactive' THEN 3
                ELSE 4 END")
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        $ids = $memberships
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $users = $this->userMap($ids);
        $credentials = $this->credentialSummaryMap($ids);
        $employment = $this->countMap(
            'nurselink_employment_histories',
            $ids
        );
        $learning = $this->countMap(
            'nurselink_learning_records',
            $ids
        );
        $publicProfiles = $this->publicProfileMap($ids);

        $rows = $memberships->map(function ($membership) use (
            $users,
            $credentials,
            $employment,
            $learning,
            $publicProfiles
        ): array {
            $id = (string) $membership->user_id;
            $user = $users[$id] ?? [];
            $credential = $credentials[$id] ?? [
                'total' => 0,
                'verified' => 0,
                'attention' => 0,
            ];
            $public = $publicProfiles[$id] ?? [
                'enabled' => false,
                'slug' => null,
            ];

            return [
                'membership_id' => (int) $membership->id,
                'user_id' => $id,
                'name' => $user['name'] ?? $id,
                'email' => $user['email'] ?? '',
                'profile_photo_uploaded' =>
                    $user['profile_photo_uploaded'] ?? false,
                'member_number' => $membership->member_number,
                'approved_at' => $membership->approved_at,
                'standing' => $this->normalizedStanding($membership),
                'standing_label' => ucfirst(
                    $this->normalizedStanding($membership)
                ),
                'standing_reason' =>
                    $membership->standing_reason ?? null,
                'standing_changed_at' =>
                    $membership->standing_changed_at ?? null,
                'verification_available' =>
                    ! empty($membership->verification_code),
                'verification_url' => $membership->verification_code
                    ? self::FRONTEND_URL
                        . '/nurselink-member-verify.html?code='
                        . rawurlencode($membership->verification_code)
                    : null,
                'credentials' => $credential,
                'employment_records' => (int) ($employment[$id] ?? 0),
                'learning_records' => (int) ($learning[$id] ?? 0),
                'public_profile' => $public,
            ];
        });

        $search = strtolower(trim((string) ($data['search'] ?? '')));

        if ($search !== '') {
            $rows = $rows->filter(function (array $row) use ($search): bool {
                $haystack = strtolower(
                    ($row['name'] ?? '')
                    . ' '
                    . ($row['email'] ?? '')
                    . ' '
                    . ($row['member_number'] ?? '')
                );

                return str_contains($haystack, $search);
            });
        }

        $credentialState = $data['credential_state'] ?? 'all';

        if ($credentialState === 'verified') {
            $rows = $rows->filter(
                fn (array $row): bool =>
                    ($row['credentials']['verified'] ?? 0) > 0
                    && ($row['credentials']['attention'] ?? 0) === 0
            );
        } elseif ($credentialState === 'attention') {
            $rows = $rows->filter(
                fn (array $row): bool =>
                    ($row['credentials']['attention'] ?? 0) > 0
            );
        }

        $publicFilter = $data['public_profile'] ?? 'all';

        if ($publicFilter === 'enabled') {
            $rows = $rows->filter(
                fn (array $row): bool =>
                    (bool) ($row['public_profile']['enabled'] ?? false)
            );
        } elseif ($publicFilter === 'disabled') {
            $rows = $rows->filter(
                fn (array $row): bool =>
                    ! (bool) ($row['public_profile']['enabled'] ?? false)
            );
        }

        return response()->json([
            'data' => $rows->values(),
            'privacy' => [
                'contact_address_exposed' => false,
                'credential_numbers_exposed' => false,
                'uploaded_documents_exposed' => false,
                'reviewer_notes_exposed' => false,
            ],
        ]);
    }

    public function show(Request $request, int $membershipId): JsonResponse
    {
        $this->requireAdmin($request);

        $membership = DB::table('nurselink_memberships')
            ->where('id', $membershipId)
            ->where('status', 'approved')
            ->first();

        abort_unless($membership, 404);

        $userId = (string) $membership->user_id;

        $user = $this->userMap([$userId])[$userId] ?? [
            'name' => $userId,
            'email' => '',
            'profile_photo_uploaded' => false,
        ];

        $credentials = [];
        if (Schema::hasTable('nurselink_credentials_registry')) {
            $credentials = DB::table('nurselink_credentials_registry')
                ->where('user_id', $userId)
                ->orderByDesc('issue_date')
                ->orderByDesc('id')
                ->limit(100)
                ->get([
                    'id',
                    'credential_type',
                    'title',
                    'issuing_body',
                    'country',
                    'issue_date',
                    'expiry_date',
                    'verification_status',
                ])
                ->map(fn ($row): array => [
                    'id' => (int) $row->id,
                    'credential_type' => $row->credential_type,
                    'title' => $row->title,
                    'issuing_body' => $row->issuing_body,
                    'country' => $row->country,
                    'issue_date' => $row->issue_date,
                    'expiry_date' => $row->expiry_date,
                    'verification_status' => $row->verification_status,
                ])
                ->values()
                ->all();
        }

        $employment = [];
        if (Schema::hasTable('nurselink_employment_histories')) {
            $employment = DB::table('nurselink_employment_histories')
                ->where('user_id', $userId)
                ->orderByDesc('is_current')
                ->orderByDesc('start_date')
                ->limit(100)
                ->get([
                    'id',
                    'employer_name',
                    'country',
                    'city',
                    'position',
                    'specialty',
                    'employment_type',
                    'start_date',
                    'end_date',
                    'is_current',
                    'is_overseas',
                ])
                ->map(fn ($row): array => [
                    'id' => (int) $row->id,
                    'employer_name' => $row->employer_name,
                    'country' => $row->country,
                    'city' => $row->city,
                    'position' => $row->position,
                    'specialty' => $row->specialty,
                    'employment_type' => $row->employment_type,
                    'start_date' => $row->start_date,
                    'end_date' => $row->end_date,
                    'is_current' => (bool) $row->is_current,
                    'is_overseas' => (bool) $row->is_overseas,
                ])
                ->values()
                ->all();
        }

        $learning = [];
        if (Schema::hasTable('nurselink_learning_records')) {
            $learning = DB::table('nurselink_learning_records')
                ->where('user_id', $userId)
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get([
                    'id',
                    'learning_type',
                    'title',
                    'provider',
                    'topic',
                    'status',
                    'started_at',
                    'completed_at',
                    'learning_hours',
                    'cpd_units',
                ])
                ->map(fn ($row): array => [
                    'id' => (int) $row->id,
                    'learning_type' => $row->learning_type,
                    'title' => $row->title,
                    'provider' => $row->provider,
                    'topic' => $row->topic,
                    'status' => $row->status,
                    'started_at' => $row->started_at,
                    'completed_at' => $row->completed_at,
                    'learning_hours' => $row->learning_hours !== null
                        ? (float) $row->learning_hours
                        : null,
                    'cpd_units' => $row->cpd_units !== null
                        ? (float) $row->cpd_units
                        : null,
                ])
                ->values()
                ->all();
        }

        $public = $this->publicProfileMap([$userId])[$userId] ?? [
            'enabled' => false,
            'slug' => null,
        ];

        $audit = [];
        if (Schema::hasTable('nurselink_review_audit')) {
            $audit = DB::table('nurselink_review_audit')
                ->where('target_type', 'membership')
                ->where('target_id', (string) $membership->id)
                ->orderByDesc('id')
                ->limit(25)
                ->get([
                    'id',
                    'reviewer_user_id',
                    'action',
                    'created_at',
                ])
                ->map(fn ($row): array => [
                    'id' => (int) $row->id,
                    'reviewer_user_id' => (string) $row->reviewer_user_id,
                    'action' => $row->action,
                    'created_at' => $row->created_at,
                ])
                ->values()
                ->all();
        }

        return response()->json([
            'data' => [
                'membership' => [
                    'id' => (int) $membership->id,
                    'user_id' => $userId,
                    'member_number' => $membership->member_number,
                    'approved_at' => $membership->approved_at,
                    'standing' => $this->normalizedStanding($membership),
                    'standing_label' => ucfirst(
                        $this->normalizedStanding($membership)
                    ),
                    'standing_reason' =>
                        $membership->standing_reason ?? null,
                    'standing_changed_by' =>
                        $membership->standing_changed_by ?? null,
                    'standing_changed_at' =>
                        $membership->standing_changed_at ?? null,
                    'suspended_at' => $membership->suspended_at ?? null,
                    'inactive_at' => $membership->inactive_at ?? null,
                    'reactivated_at' =>
                        $membership->reactivated_at ?? null,
                    'verification_available' =>
                        ! empty($membership->verification_code),
                    'verification_url' => $membership->verification_code
                        ? self::FRONTEND_URL
                            . '/nurselink-member-verify.html?code='
                            . rawurlencode($membership->verification_code)
                        : null,
                ],
                'member' => $user,
                'public_profile' => $public,
                'credentials' => $credentials,
                'employment' => $employment,
                'learning' => $learning,
                'membership_audit' => $audit,
                'lifecycle' => [
                    'endpoint' =>
                        '/api/nurselink/admin/membership-lifecycle/'
                        . $membership->id,
                    'directory_read_only' => true,
                    'standing_changes_audited' => true,
                ],
                'privacy' => [
                    'credential_numbers_exposed' => false,
                    'uploaded_documents_exposed' => false,
                    'home_address_exposed' => false,
                    'phone_exposed' => false,
                ],
            ],
        ]);
    }

    private function normalizedStanding(object $membership): string
    {
        $standing = strtolower(trim((string) (
            $membership->standing ?? ''
        )));

        return in_array(
            $standing,
            ['active', 'suspended', 'inactive'],
            true
        ) ? $standing : 'active';
    }

    private function requireAdmin(Request $request): array
    {
        $user = $request->user();
        abort_unless($user, 401);

        $sessionUserId = (string) $request->session()->get(
            'nurselink_admin_elevated_user_id',
            ''
        );

        $elevatedAt = (int) $request->session()->get(
            'nurselink_admin_elevated_at',
            0
        );

        $expiresAt = (int) $request->session()->get(
            'nurselink_admin_expires_at',
            0
        );

        abort_unless(
            $sessionUserId !== ''
            && hash_equals(
                $sessionUserId,
                (string) $user->getKey()
            )
            && $elevatedAt > 0
            && $expiresAt >= time()
            && (time() - $elevatedAt)
                <= self::ELEVATION_TTL_SECONDS,
            403,
            'A separate NurseLink administrator sign-in is required.'
        );

        $access = $this->resolveAccess($user);

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required for the approved-member registry.'
        );

        return $access;
    }

    private function resolveAccess($user): array
    {
        $userId = $user->getKey();

        $reviewerAccess = Schema::hasTable(
            'nurselink_reviewer_access'
        )
            ? DB::table('nurselink_reviewer_access')
                ->where('user_id', $userId)
                ->where('active', true)
                ->first()
            : null;

        $explicitSuperAdmin = Schema::hasTable(
            'nurselink_super_admin_access'
        )
            && DB::table('nurselink_super_admin_access')
                ->where('user_id', $userId)
                ->where('active', true)
                ->exists();

        $modelRole = strtolower(trim((string) (
            $user->role
            ?? $user->user_role
            ?? $user->user_type
            ?? ''
        )));

        $reviewRole = strtolower(
            (string) ($reviewerAccess->role ?? '')
        );

        $modelSuperAdmin = (bool) ($user->is_super_admin ?? false)
            || in_array(
                $modelRole,
                [
                    'super_admin',
                    'super-administrator',
                    'super_administrator',
                    'superadministrator',
                ],
                true
            );

        $isSuperAdmin = $explicitSuperAdmin
            || $modelSuperAdmin
            || $reviewRole === 'super_admin';

        $isAdmin = $isSuperAdmin
            || (bool) ($user->is_admin ?? false)
            || in_array(
                $modelRole,
                ['admin', 'administrator'],
                true
            )
            || in_array(
                $reviewRole,
                ['admin', 'super_admin'],
                true
            );

        return [
            'role' => $isSuperAdmin
                ? 'super_admin'
                : ($isAdmin ? 'admin' : 'reviewer'),
            'is_super_admin' => $isSuperAdmin,
            'is_admin' => $isAdmin,
        ];
    }

    private function userMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn ($id) => (string) $id, $ids)
        )));

        if ($ids === []) return [];

        $columns = ['id'];

        foreach ([
            'email',
            'name',
            'first_name',
            'last_name',
            'profile_photo_path',
        ] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $columns[] = $column;
            }
        }

        $rows = DB::table('users')
            ->whereIn('id', $ids)
            ->get($columns);

        $map = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row->name ?? ''));

            if ($name === '') {
                $name = trim(
                    (string) ($row->first_name ?? '')
                    . ' '
                    . (string) ($row->last_name ?? '')
                );
            }

            $map[(string) $row->id] = [
                'id' => (string) $row->id,
                'name' => $name !== ''
                    ? $name
                    : (string) ($row->email ?? $row->id),
                'email' => (string) ($row->email ?? ''),
                'profile_photo_uploaded' =>
                    ! empty($row->profile_photo_path ?? null),
            ];
        }

        return $map;
    }

    private function credentialSummaryMap(array $ids): array
    {
        if (
            $ids === []
            || ! Schema::hasTable('nurselink_credentials_registry')
        ) {
            return [];
        }

        $rows = DB::table('nurselink_credentials_registry')
            ->whereIn('user_id', $ids)
            ->get([
                'user_id',
                'verification_status',
            ]);

        $map = [];

        foreach ($rows as $row) {
            $id = (string) $row->user_id;

            if (! isset($map[$id])) {
                $map[$id] = [
                    'total' => 0,
                    'verified' => 0,
                    'attention' => 0,
                ];
            }

            $map[$id]['total']++;

            if ($row->verification_status === 'verified') {
                $map[$id]['verified']++;
            } else {
                $map[$id]['attention']++;
            }
        }

        return $map;
    }

    private function countMap(string $table, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->whereIn('user_id', $ids)
            ->select('user_id', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('user_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->user_id =>
                    (int) $row->aggregate_count,
            ])
            ->all();
    }

    private function publicProfileMap(array $ids): array
    {
        if (
            $ids === []
            || ! Schema::hasTable('nurselink_public_profiles')
        ) {
            return [];
        }

        return DB::table('nurselink_public_profiles')
            ->whereIn('user_id', $ids)
            ->get([
                'user_id',
                'enabled',
                'slug',
            ])
            ->mapWithKeys(fn ($row): array => [
                (string) $row->user_id => [
                    'enabled' => (bool) $row->enabled,
                    'slug' => $row->slug,
                    'url' => $row->enabled && $row->slug
                        ? self::FRONTEND_URL
                            . '/nurselink-public-profile.html?slug='
                            . rawurlencode($row->slug)
                        : null,
                ],
            ])
            ->all();
    }
}
