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

class EnterprisePlatformController extends Controller
{
    public function memberMe(Request $request): JsonResponse
    {
        $userId = (string) $request->user()->getKey();

        $rows = DB::table('nurselink_enterprise_cohort_members as cm')
            ->join(
                'nurselink_enterprise_cohorts as c',
                'c.id',
                '=',
                'cm.cohort_id'
            )
            ->join(
                'nurselink_partner_organizations as o',
                'o.id',
                '=',
                'c.partner_organization_id'
            )
            ->where('cm.user_id', $userId)
            ->orderByRaw(
                "CASE cm.status
                    WHEN 'active' THEN 1
                    WHEN 'completed' THEN 2
                    ELSE 3 END"
            )
            ->orderByDesc('cm.updated_at')
            ->select([
                'cm.id',
                'cm.status as membership_status',
                'cm.joined_at',
                'cm.completed_at',
                'cm.inactive_at',
                'c.id as cohort_id',
                'c.name as cohort_name',
                'c.code as cohort_code',
                'c.description',
                'c.status as cohort_status',
                'c.starts_at',
                'c.ends_at',
                'o.id as organization_id',
                'o.name as organization_name',
                'o.organization_type',
                'o.country',
                'o.city',
            ])
            ->get();

        return response()->json([
            'data' => $rows,
            'privacy' => [
                'internal_notes_exposed' => false,
                'other_cohort_member_identities_exposed' => false,
            ],
            'governance' => [
                'employment_relationship_implied' => false,
                'licensure_status_implied' => false,
                'credential_verification_implied' => false,
                'message' =>
                    'Enterprise cohort participation is a NurseLink institutional coordination feature and does not itself establish employment, licensure, credential verification or regulatory standing.',
            ],
        ]);
    }

    public function adminSummary(Request $request): JsonResponse
    {
        $this->requireAdministratorSession($request);

        $organizations = DB::table('nurselink_partner_organizations');
        $cohorts = DB::table('nurselink_enterprise_cohorts');
        $members = DB::table('nurselink_enterprise_cohort_members');

        $rows = DB::table('nurselink_enterprise_cohorts as c')
            ->join(
                'nurselink_partner_organizations as o',
                'o.id',
                '=',
                'c.partner_organization_id'
            )
            ->orderBy('o.name')
            ->orderBy('c.name')
            ->select([
                'c.id',
                'c.name',
                'c.code',
                'c.status',
                'c.starts_at',
                'c.ends_at',
                'o.id as organization_id',
                'o.name as organization_name',
                'o.status as organization_status',
                'o.organization_type',
                'o.country',
            ])
            ->get()
            ->map(function ($row): array {
                $memberQuery = DB::table(
                    'nurselink_enterprise_cohort_members'
                )->where('cohort_id', $row->id);

                $memberIds = (clone $memberQuery)
                    ->whereIn('status', ['active', 'completed'])
                    ->pluck('user_id')
                    ->all();

                return [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'code' => $row->code,
                    'status' => $row->status,
                    'starts_at' => $row->starts_at,
                    'ends_at' => $row->ends_at,
                    'organization' => [
                        'id' => (int) $row->organization_id,
                        'name' => $row->organization_name,
                        'status' => $row->organization_status,
                        'organization_type' => $row->organization_type,
                        'country' => $row->country,
                    ],
                    'members' => [
                        'total' => (clone $memberQuery)->count(),
                        'active' => (clone $memberQuery)
                            ->where('status', 'active')
                            ->count(),
                        'completed' => (clone $memberQuery)
                            ->where('status', 'completed')
                            ->count(),
                    ],
                    'engagement_90_days' =>
                        $this->aggregateEngagement(
                            $memberIds,
                            90
                        ),
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'summary' => [
                    'organizations_total' =>
                        (clone $organizations)->count(),
                    'organizations_verified' =>
                        (clone $organizations)
                            ->where('status', 'verified')
                            ->count(),
                    'cohorts_total' =>
                        (clone $cohorts)->count(),
                    'cohorts_active' =>
                        (clone $cohorts)
                            ->where('status', 'active')
                            ->count(),
                    'cohort_memberships_total' =>
                        (clone $members)->count(),
                    'cohort_memberships_active' =>
                        (clone $members)
                            ->where('status', 'active')
                            ->count(),
                ],
                'cohorts' => $rows,
                'generated_at' => now()->toIso8601String(),
            ],
            'privacy' => [
                'summary_aggregate_only' => true,
                'private_member_notes_included' => false,
                'documents_included' => false,
                'credential_numbers_included' => false,
                'small_cohort_metrics_suppressed' => true,
                'minimum_aggregate_cohort_size' => 3,
            ],
        ]);
    }

    public function adminOrganizations(
        Request $request
    ): JsonResponse {
        $this->requireAdministratorSession($request);

        $rows = DB::table('nurselink_partner_organizations')
            ->orderByRaw(
                "CASE status
                    WHEN 'verified' THEN 1
                    WHEN 'pending' THEN 2
                    ELSE 3 END"
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'organization_type',
                'country',
                'city',
                'status',
            ]);

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function adminCohorts(Request $request): JsonResponse
    {
        $this->requireAdministratorSession($request);

        $rows = DB::table('nurselink_enterprise_cohorts as c')
            ->join(
                'nurselink_partner_organizations as o',
                'o.id',
                '=',
                'c.partner_organization_id'
            )
            ->orderBy('o.name')
            ->orderBy('c.name')
            ->select([
                'c.*',
                'o.name as organization_name',
                'o.status as organization_status',
                'o.organization_type',
                'o.country',
                'o.city',
            ])
            ->get()
            ->map(function ($row): array {
                return [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'code' => $row->code,
                    'description' => $row->description,
                    'status' => $row->status,
                    'starts_at' => $row->starts_at,
                    'ends_at' => $row->ends_at,
                    'organization' => [
                        'id' => (int) $row->partner_organization_id,
                        'name' => $row->organization_name,
                        'status' => $row->organization_status,
                        'organization_type' => $row->organization_type,
                        'country' => $row->country,
                        'city' => $row->city,
                    ],
                    'member_count' =>
                        DB::table('nurselink_enterprise_cohort_members')
                            ->where('cohort_id', $row->id)
                            ->count(),
                ];
            })
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function adminCohortDetail(
        Request $request,
        int $cohortId
    ): JsonResponse {
        $this->requireAdministratorSession($request);

        $cohort = DB::table('nurselink_enterprise_cohorts as c')
            ->join(
                'nurselink_partner_organizations as o',
                'o.id',
                '=',
                'c.partner_organization_id'
            )
            ->where('c.id', $cohortId)
            ->first([
                'c.*',
                'o.name as organization_name',
                'o.status as organization_status',
            ]);

        abort_unless($cohort, 404);

        $members = DB::table('nurselink_enterprise_cohort_members as cm')
            ->join('users as u', 'u.id', '=', 'cm.user_id')
            ->leftJoin(
                'nurselink_memberships as m',
                'm.user_id',
                '=',
                'cm.user_id'
            )
            ->where('cm.cohort_id', $cohortId)
            ->orderByRaw(
                "CASE cm.status
                    WHEN 'active' THEN 1
                    WHEN 'completed' THEN 2
                    ELSE 3 END"
            )
            ->orderBy('u.email')
            ->select([
                'cm.id',
                'cm.user_id',
                'cm.status',
                'cm.joined_at',
                'cm.completed_at',
                'cm.inactive_at',
                'cm.internal_note',
                'u.name',
                'u.email',
                'm.member_number',
                'm.status as membership_status',
                'm.standing',
            ])
            ->get();

        return response()->json([
            'data' => [
                'cohort' => [
                    'id' => (int) $cohort->id,
                    'name' => $cohort->name,
                    'code' => $cohort->code,
                    'description' => $cohort->description,
                    'status' => $cohort->status,
                    'starts_at' => $cohort->starts_at,
                    'ends_at' => $cohort->ends_at,
                    'organization_id' =>
                        (int) $cohort->partner_organization_id,
                    'organization_name' =>
                        $cohort->organization_name,
                    'organization_status' =>
                        $cohort->organization_status,
                ],
                'members' => $members,
            ],
            'privacy' => [
                'administrator_only_roster' => true,
                'home_address_included' => false,
                'phone_included' => false,
                'documents_included' => false,
                'credential_numbers_included' => false,
            ],
        ]);
    }

    public function adminStoreCohort(Request $request): JsonResponse
    {
        $this->requireAdministratorSession($request);

        $data = $this->validateCohort($request);

        $organization = DB::table('nurselink_partner_organizations')
            ->where('id', $data['partner_organization_id'])
            ->first();

        abort_unless($organization, 422, 'Partner organization not found.');

        abort_unless(
            $organization->status === 'verified',
            422,
            'Enterprise cohorts require a verified partner organization.'
        );

        $code = $this->uniqueCode(
            $data['code'] ?? $data['name']
        );

        unset($data['code']);

        $id = DB::table('nurselink_enterprise_cohorts')
            ->insertGetId([
                ...$data,
                'code' => $code,
                'created_by' => (string) $request->user()->getKey(),
                'updated_by' => (string) $request->user()->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $row = DB::table('nurselink_enterprise_cohorts')
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'enterprise.cohort_created',
            'enterprise_cohort',
            (string) $id,
            null,
            $row
        );

        return response()->json([
            'message' => 'Enterprise cohort created.',
            'data' => $row,
        ], 201);
    }

    public function adminUpdateCohort(
        Request $request,
        int $cohortId
    ): JsonResponse {
        $this->requireAdministratorSession($request);

        $before = DB::table('nurselink_enterprise_cohorts')
            ->where('id', $cohortId)
            ->first();

        abort_unless($before, 404);

        $data = $this->validateCohort($request);

        $organization = DB::table('nurselink_partner_organizations')
            ->where('id', $data['partner_organization_id'])
            ->first();

        abort_unless(
            $organization
            && $organization->status === 'verified',
            422,
            'Enterprise cohorts require a verified partner organization.'
        );

        if (
            ! empty($data['code'])
            && $data['code'] !== $before->code
        ) {
            $data['code'] = $this->uniqueCode(
                $data['code'],
                $cohortId
            );
        } else {
            unset($data['code']);
        }

        DB::table('nurselink_enterprise_cohorts')
            ->where('id', $cohortId)
            ->update([
                ...$data,
                'updated_by' => (string) $request->user()->getKey(),
                'updated_at' => now(),
            ]);

        $after = DB::table('nurselink_enterprise_cohorts')
            ->where('id', $cohortId)
            ->first();

        $this->audit(
            $request,
            'enterprise.cohort_updated',
            'enterprise_cohort',
            (string) $cohortId,
            $before,
            $after
        );

        return response()->json([
            'message' => 'Enterprise cohort updated.',
            'data' => $after,
        ]);
    }

    public function adminEnrollMember(
        Request $request,
        int $cohortId
    ): JsonResponse {
        $this->requireAdministratorSession($request);

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:190'],
            'member_number' => ['nullable', 'string', 'max:80'],
            'status' => [
                'nullable',
                Rule::in(['active', 'completed', 'inactive']),
            ],
            'internal_note' => ['nullable', 'string', 'max:3000'],
        ]);

        abort_if(
            empty($data['email'])
            && empty($data['member_number']),
            422,
            'Provide member email or member number.'
        );

        $cohort = DB::table('nurselink_enterprise_cohorts')
            ->where('id', $cohortId)
            ->first();

        abort_unless($cohort, 404);

        $user = DB::table('users as u')
            ->join(
                'nurselink_memberships as m',
                'm.user_id',
                '=',
                'u.id'
            )
            ->when(
                ! empty($data['email']),
                fn ($q) => $q->where('u.email', $data['email'])
            )
            ->when(
                empty($data['email'])
                && ! empty($data['member_number']),
                fn ($q) => $q->where(
                    'm.member_number',
                    $data['member_number']
                )
            )
            ->where('m.status', 'approved')
            ->first([
                'u.id',
                'u.email',
                'u.name',
                'm.member_number',
                'm.standing',
            ]);

        abort_unless(
            $user,
            422,
            'Only an approved NurseLink member can be assigned to an enterprise cohort.'
        );

        $existing = DB::table('nurselink_enterprise_cohort_members')
            ->where('cohort_id', $cohortId)
            ->where('user_id', $user->id)
            ->first();

        $status = $data['status'] ?? 'active';

        $values = [
            'status' => $status,
            'internal_note' => $data['internal_note'] ?? null,
            'joined_at' =>
                $existing?->joined_at ?: now(),
            'completed_at' =>
                $status === 'completed'
                    ? ($existing?->completed_at ?: now())
                    : null,
            'inactive_at' =>
                $status === 'inactive'
                    ? ($existing?->inactive_at ?: now())
                    : null,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('nurselink_enterprise_cohort_members')
                ->where('id', $existing->id)
                ->update($values);
            $id = (int) $existing->id;
        } else {
            $id = DB::table('nurselink_enterprise_cohort_members')
                ->insertGetId([
                    'cohort_id' => $cohortId,
                    'user_id' => $user->id,
                    ...$values,
                    'created_at' => now(),
                ]);
        }

        $after = DB::table('nurselink_enterprise_cohort_members')
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'enterprise.cohort_member_assigned',
            'enterprise_cohort_member',
            (string) $id,
            $existing,
            $after
        );

        return response()->json([
            'message' => 'Enterprise cohort member assignment saved.',
            'data' => [
                'id' => $id,
                'user_id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'member_number' => $user->member_number,
                'standing' => $user->standing,
                'status' => $status,
            ],
        ], $existing ? 200 : 201);
    }

    public function adminRemoveMember(
        Request $request,
        int $cohortId,
        string $userId
    ): JsonResponse {
        $this->requireAdministratorSession($request);

        $before = DB::table('nurselink_enterprise_cohort_members')
            ->where('cohort_id', $cohortId)
            ->where('user_id', $userId)
            ->first();

        abort_unless($before, 404);

        DB::table('nurselink_enterprise_cohort_members')
            ->where('id', $before->id)
            ->delete();

        $this->audit(
            $request,
            'enterprise.cohort_member_removed',
            'enterprise_cohort_member',
            (string) $before->id,
            $before,
            null
        );

        return response()->json([
            'message' => 'Member removed from enterprise cohort.',
        ]);
    }

    public function partnerSummary(Request $request): JsonResponse
    {
        $scope = $this->authorizePartner($request);
        $orgId = (int) $scope['organization']->id;

        $cohorts = DB::table('nurselink_enterprise_cohorts')
            ->where('partner_organization_id', $orgId)
            ->orderBy('name')
            ->get();

        $rows = $cohorts->map(function ($cohort): array {
            $memberQuery = DB::table('nurselink_enterprise_cohort_members')
                ->where('cohort_id', $cohort->id);

            $memberIds = (clone $memberQuery)
                ->whereIn('status', ['active', 'completed'])
                ->pluck('user_id')
                ->all();

            $totalMembers = (clone $memberQuery)->count();
            $privacyThreshold = 3;
            $metricsSuppressed =
                count($memberIds) < $privacyThreshold;

            return [
                'id' => (int) $cohort->id,
                'name' => $cohort->name,
                'code' => $cohort->code,
                'status' => $cohort->status,
                'starts_at' => $cohort->starts_at,
                'ends_at' => $cohort->ends_at,
                'members' => [
                    'total' => $totalMembers,
                    'active' => (clone $memberQuery)
                        ->where('status', 'active')
                        ->count(),
                    'completed' => (clone $memberQuery)
                        ->where('status', 'completed')
                        ->count(),
                ],
                'privacy_threshold' =>
                    $privacyThreshold,
                'metrics_suppressed' =>
                    $metricsSuppressed,
                'membership_standing' =>
                    $metricsSuppressed
                        ? null
                        : $this->standingAggregate($memberIds),
                'engagement_90_days' =>
                    $metricsSuppressed
                        ? null
                        : $this->aggregateEngagement($memberIds, 90),
            ];
        })->values();

        return response()->json([
            'data' => [
                'organization' => [
                    'id' => $orgId,
                    'name' => $scope['organization']->name,
                    'organization_type' =>
                        $scope['organization']->organization_type,
                    'country' => $scope['organization']->country,
                    'city' => $scope['organization']->city,
                ],
                'cohorts' => $rows,
                'summary' => [
                    'cohorts_total' => $rows->count(),
                    'cohorts_active' =>
                        $rows->where('status', 'active')->count(),
                    'member_assignments_total' =>
                        $rows->sum(
                            fn ($row) =>
                                (int) $row['members']['total']
                        ),
                ],
            ],
            'privacy' => [
                'aggregate_only' => true,
                'member_identity_included' => false,
                'member_contact_details_included' => false,
                'internal_notes_included' => false,
                'documents_included' => false,
                'credential_numbers_included' => false,
            ],
        ]);
    }

    private function aggregateEngagement(
        array $userIds,
        int $days
    ): array {
        if ($userIds === []) {
            return [
                'event_registrations' => 0,
                'mentoring_requests' => 0,
                'benefit_requests' => 0,
                'benefit_saves' => 0,
            ];
        }

        $since = CarbonImmutable::now()->subDays($days);

        $events = Schema::hasTable('nurselink_event_registrations')
            ? DB::table('nurselink_event_registrations')
                ->whereIn('user_id', $userIds)
                ->where('created_at', '>=', $since)
                ->count()
            : 0;

        $mentoring = Schema::hasTable('nurselink_mentoring_requests')
            ? DB::table('nurselink_mentoring_requests')
                ->where('created_at', '>=', $since)
                ->where(function ($q) use ($userIds): void {
                    $q->whereIn('mentor_user_id', $userIds)
                        ->orWhereIn('mentee_user_id', $userIds);
                })
                ->count()
            : 0;

        $benefitRequests = Schema::hasTable('nurselink_benefit_requests')
            ? DB::table('nurselink_benefit_requests')
                ->whereIn('user_id', $userIds)
                ->where('created_at', '>=', $since)
                ->count()
            : 0;

        $benefitSaves = Schema::hasTable('nurselink_saved_benefits')
            ? DB::table('nurselink_saved_benefits')
                ->whereIn('user_id', $userIds)
                ->where('created_at', '>=', $since)
                ->count()
            : 0;

        return [
            'event_registrations' => $events,
            'mentoring_requests' => $mentoring,
            'benefit_requests' => $benefitRequests,
            'benefit_saves' => $benefitSaves,
        ];
    }

    private function standingAggregate(array $userIds): array
    {
        $counts = [
            'active' => 0,
            'suspended' => 0,
            'inactive' => 0,
            'other' => 0,
        ];

        if (
            $userIds === []
            || ! Schema::hasTable('nurselink_memberships')
        ) {
            return $counts;
        }

        $rows = DB::table('nurselink_memberships')
            ->whereIn('user_id', $userIds)
            ->select(
                'standing',
                DB::raw('COUNT(*) AS aggregate_count')
            )
            ->groupBy('standing')
            ->get();

        foreach ($rows as $row) {
            $standing = strtolower((string) $row->standing);
            $key = array_key_exists($standing, $counts)
                ? $standing
                : 'other';

            $counts[$key] += (int) $row->aggregate_count;
        }

        return $counts;
    }

    private function validateCohort(Request $request): array
    {
        return $request->validate([
            'partner_organization_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'name' => ['required', 'string', 'max:190'],
            'code' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => [
                'required',
                Rule::in(['planned', 'active', 'completed', 'inactive']),
            ],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => [
                'nullable',
                'date',
                'after_or_equal:starts_at',
            ],
        ]);
    }

    private function uniqueCode(
        string $value,
        ?int $ignoreId = null
    ): string {
        $base = strtoupper(
            Str::slug($value, '-')
        );

        if ($base === '') {
            $base = 'COHORT';
        }

        $base = substr($base, 0, 64);
        $code = $base;
        $counter = 2;

        while (true) {
            $query = DB::table('nurselink_enterprise_cohorts')
                ->where('code', $code);

            if ($ignoreId !== null) {
                $query->where('id', '!=', $ignoreId);
            }

            if (! $query->exists()) {
                return $code;
            }

            $code = substr($base, 0, 70)
                . '-'
                . $counter;
            $counter++;
        }
    }

    private function authorizePartner(Request $request): array
    {
        $user = $request->user();
        abort_unless($user, 401);

        $access = DB::table('nurselink_partner_access')
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->first();

        abort_unless($access, 403, 'NurseLink partner access is required.');

        $organization = DB::table('nurselink_partner_organizations')
            ->where('id', $access->partner_organization_id)
            ->where('status', 'verified')
            ->first();

        abort_unless(
            $organization,
            403,
            'Verified partner organization access is required.'
        );

        $role = strtolower((string) $access->role);

        abort_unless(
            in_array($role, ['viewer', 'recruiter', 'manager'], true),
            403
        );

        return [
            'role' => $role,
            'organization' => $organization,
        ];
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

        $role = Schema::hasTable('nurselink_reviewer_access')
            ? strtolower((string) (
                DB::table('nurselink_reviewer_access')
                    ->where('user_id', $userId)
                    ->where('active', true)
                    ->value('role')
                ?? ''
            ))
            : '';

        $super = Schema::hasTable('nurselink_super_admin_access')
            && DB::table('nurselink_super_admin_access')
                ->where('user_id', $userId)
                ->where('active', true)
                ->exists();

        abort_unless(
            $super
            || in_array($role, ['admin', 'super_admin'], true)
            || (bool) ($user->is_admin ?? false)
            || (bool) ($user->is_super_admin ?? false),
            403,
            'Administrator access is required for enterprise platform management.'
        );
    }

    private function audit(
        Request $request,
        string $action,
        string $targetType,
        string $targetId,
        $before,
        $after
    ): void {
        if (! Schema::hasTable('nurselink_review_audit')) {
            return;
        }

        DB::table('nurselink_review_audit')->insert([
            'reviewer_user_id' =>
                (string) $request->user()->getKey(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before_state' => $before !== null
                ? json_encode($before, JSON_UNESCAPED_UNICODE)
                : null,
            'after_state' => $after !== null
                ? json_encode($after, JSON_UNESCAPED_UNICODE)
                : null,
            'created_at' => now(),
        ]);
    }
}
