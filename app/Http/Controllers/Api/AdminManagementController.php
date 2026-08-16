<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class AdminManagementController extends Controller
{
    private const ELEVATION_TTL_SECONDS = 28800;
    private const INVITATION_TTL_DAYS = 7;

    private const ROLES = [
        'super_administrator' => [
            'label' => 'Super Administrator',
            'description' => 'Full NurseLink administration, role management and governance.',
            'scopes' => ['*'],
        ],
        'membership_administrator' => [
            'label' => 'Membership Administrator',
            'description' => 'Membership applications, standing, onboarding and member registry.',
            'scopes' => ['membership', 'reports'],
        ],
        'verification_officer' => [
            'label' => 'Verification Officer',
            'description' => 'Credential and membership verification workflows.',
            'scopes' => ['verification', 'membership', 'reports'],
        ],
        'content_administrator' => [
            'label' => 'Content Administrator',
            'description' => 'Member-facing content, benefits content and controlled communications.',
            'scopes' => ['content', 'communications'],
        ],
        'program_administrator' => [
            'label' => 'Program Administrator',
            'description' => 'Programs, chapters, mentoring, engagement and enterprise programs.',
            'scopes' => ['programs', 'training', 'reports'],
        ],
        'employment_administrator' => [
            'label' => 'Employment Administrator',
            'description' => 'Employment opportunities, applications and institutional workforce workflows.',
            'scopes' => ['employment', 'reports'],
        ],
        'training_events_administrator' => [
            'label' => 'Training & Events Administrator',
            'description' => 'Training, events and related program operations.',
            'scopes' => ['training', 'programs'],
        ],
        'communications_administrator' => [
            'label' => 'Communications Administrator',
            'description' => 'Controlled member notifications and communication workflows.',
            'scopes' => ['communications'],
        ],
        'support_officer' => [
            'label' => 'Support Officer',
            'description' => 'Member support cases and support communications.',
            'scopes' => ['support', 'communications'],
        ],
        'finance_treasurer' => [
            'label' => 'Finance / Treasurer',
            'description' => 'Finance-oriented administration and authorized reporting.',
            'scopes' => ['finance', 'reports'],
        ],
        'reports_analytics' => [
            'label' => 'Reports & Analytics',
            'description' => 'Administrative reports and analytics.',
            'scopes' => ['reports'],
        ],
        'auditor_read_only' => [
            'label' => 'Auditor / Read Only',
            'description' => 'Read-only review across Administrator Portal areas; no record changes.',
            'scopes' => ['read_only'],
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $access = $this->requireElevatedSession($request);
        $this->ensureTables();

        $profiles = DB::table('nurselink_admin_profiles')->get()->keyBy(fn ($row) => (string) $row->user_id);
        $reviewerRows = Schema::hasTable('nurselink_reviewer_access')
            ? DB::table('nurselink_reviewer_access')->get()->keyBy(fn ($row) => (string) $row->user_id)
            : collect();
        $superRows = Schema::hasTable('nurselink_super_admin_access')
            ? DB::table('nurselink_super_admin_access')->get()->keyBy(fn ($row) => (string) $row->user_id)
            : collect();

        $userIds = collect($profiles->keys())
            ->merge($reviewerRows->keys())
            ->merge($superRows->keys())
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        $users = $userIds->isEmpty()
            ? collect()
            : DB::table('users')->whereIn('id', $userIds->all())->get()->keyBy(fn ($row) => (string) $row->id);

        $roles = $userIds->isEmpty()
            ? collect()
            : DB::table('nurselink_admin_role_assignments')
                ->whereIn('user_id', $userIds->all())
                ->where('active', true)
                ->orderBy('role_key')
                ->get()
                ->groupBy(fn ($row) => (string) $row->user_id);

        $administrators = $userIds->map(function ($id) use ($profiles, $reviewerRows, $superRows, $users, $roles, $request): array {
            $profile = $profiles->get($id);
            $review = $reviewerRows->get($id);
            $super = $superRows->get($id);
            $user = $users->get($id);
            $roleKeys = $roles->get($id, collect())->pluck('role_key')->map(fn ($role) => (string) $role)->values()->all();

            $legacySuper = (bool) ($super?->active ?? false) || ((bool) ($review?->active ?? false) && strtolower((string) ($review?->role ?? '')) === 'super_admin');
            $legacyActive = $legacySuper || (bool) ($review?->active ?? false);
            $active = $profile ? (bool) $profile->active : $legacyActive;
            $legacyRole = $legacySuper ? 'super_admin' : ($legacyActive ? (string) ($review?->role ?? 'admin') : null);
            $roleLabels = array_map(fn ($role) => self::ROLES[$role]['label'] ?? $role, $roleKeys);

            if ($roleKeys === [] && $legacyRole) {
                $roleLabels = [match (strtolower($legacyRole)) {
                    'super_admin' => 'Super Administrator (Legacy)',
                    'reviewer' => 'Reviewer (Legacy)',
                    default => 'Administrator (Legacy)',
                }];
            }

            return [
                'user_id' => $id,
                'name' => $this->displayName($user),
                'email' => (string) ($user->email ?? ''),
                'department_unit' => (string) ($profile->department_unit ?? ''),
                'roles' => $roleKeys,
                'role_labels' => $roleLabels,
                'legacy_role' => $legacyRole,
                'managed_by_granular_roles' => $roleKeys !== [],
                'status' => $active ? 'active' : 'revoked',
                'active' => $active,
                'accepted_at' => $profile->accepted_at ?? null,
                'activated_at' => $profile->activated_at ?? null,
                'is_current_user' => $id === (string) $request->user()->getKey(),
                'updated_at' => $profile->updated_at ?? $super?->updated_at ?? $review?->updated_at ?? null,
            ];
        })->sortBy(function (array $row): string {
            return ($row['active'] ? '0' : '1') . '|' . strtolower((string) $row['email']);
        })->values();

        $invitations = DB::table('nurselink_admin_invitations')
            ->whereIn('status', ['invitation_sent', 'accepted'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($row) => $this->presentInvitation($row, false))
            ->values();

        return response()->json([
            'data' => [
                'administrators' => $administrators,
                'invitations' => $invitations,
                'roles' => $this->roleCatalog(),
            ],
            'permissions' => [
                'can_manage_administrators' => $access['is_super_admin'],
                'cannot_revoke_self' => true,
                'protect_last_super_admin' => true,
            ],
        ]);
    }

    public function myPermissions(Request $request): JsonResponse
    {
        $access = $this->requireElevatedSession($request);
        $this->ensureTables();

        $roles = $this->activeRoles((string) $request->user()->getKey());
        $scopes = $this->scopesForRoles($roles);

        if ($roles === []) {
            // Existing administrators retain full legacy access until explicitly converted.
            $scopes = $access['is_super_admin'] ? ['*'] : ['legacy'];
        }

        return response()->json([
            'data' => [
                'roles' => $roles,
                'role_labels' => array_map(fn ($role) => self::ROLES[$role]['label'] ?? $role, $roles),
                'scopes' => $scopes,
                'is_super_admin' => $access['is_super_admin'] || in_array('super_administrator', $roles, true),
                'read_only' => in_array('auditor_read_only', $roles, true),
            ],
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $access = $this->requireElevatedSession($request);
        $this->requireSuperAdmin($access);
        $this->ensureTables();

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'department_unit' => ['nullable', 'string', 'max:190'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::in(array_keys(self::ROLES))],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'approval_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $email = strtolower(trim($data['email']));
        $roles = array_values(array_unique($data['roles']));
        $department = trim((string) ($data['department_unit'] ?? '')) ?: null;
        $reason = trim((string) $data['reason']);
        $approvalNotes = trim((string) ($data['approval_notes'] ?? '')) ?: null;

        $supersededInvitations = DB::table('nurselink_admin_invitations')
            ->where('email', $email)
            ->whereIn('status', ['invitation_sent', 'accepted'])
            ->get(['id', 'status']);

        DB::table('nurselink_admin_invitations')
            ->where('email', $email)
            ->whereIn('status', ['invitation_sent', 'accepted'])
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);

        [$token, $link] = $this->newInvitationToken();
        $now = now();

        $id = DB::table('nurselink_admin_invitations')->insertGetId([
            'email' => $email,
            'department_unit' => $department,
            'roles_json' => json_encode($roles),
            'token_hash' => hash('sha256', $token),
            'status' => 'invitation_sent',
            'invited_by_user_id' => (string) $request->user()->getKey(),
            'delivery_status' => 'pending',
            'sent_at' => $now,
            'expires_at' => $now->copy()->addDays(self::INVITATION_TTL_DAYS),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($supersededInvitations as $superseded) {
            $this->audit(
                $request,
                'administrator.invitation_superseded',
                (string) $superseded->id,
                ['email' => $email, 'status' => (string) $superseded->status],
                ['email' => $email, 'status' => 'cancelled', 'superseded_by_invitation_id' => (string) $id],
                $reason,
                $approvalNotes,
                $email,
                'invitation'
            );
        }

        [$deliveryStatus, $deliveryError] = $this->sendInvitationEmail($email, $roles, $department, $link);

        DB::table('nurselink_admin_invitations')->where('id', $id)->update([
            'delivery_status' => $deliveryStatus,
            'delivery_error' => $deliveryError,
            'updated_at' => now(),
        ]);

        $row = DB::table('nurselink_admin_invitations')->where('id', $id)->first();

        $this->audit($request, 'administrator.invitation_sent', (string) $id, null, [
            'email' => $email,
            'roles' => $roles,
            'department_unit' => $department,
            'delivery_status' => $deliveryStatus,
        ], $reason, $approvalNotes, $email, 'invitation');

        return response()->json([
            'message' => $deliveryStatus === 'sent'
                ? 'Administrator invitation sent to ' . $email . '.'
                : 'Invitation created. Email delivery was unavailable; copy the secure invitation link instead.',
            'data' => array_merge($this->presentInvitation($row, false), [
                'invitation_link' => $link,
            ]),
        ], 201);
    }

    public function resend(Request $request, string $invitationId): JsonResponse
    {
        $access = $this->requireElevatedSession($request);
        $this->requireSuperAdmin($access);
        $this->ensureTables();

        $row = DB::table('nurselink_admin_invitations')->where('id', $invitationId)->first();
        abort_unless($row, 404, 'Administrator invitation was not found.');
        abort_unless(in_array((string) $row->status, ['invitation_sent'], true), 422, 'Only pending invitations can be resent.');

        [$token, $link] = $this->newInvitationToken();
        $roles = $this->decodeRoles($row->roles_json);
        [$deliveryStatus, $deliveryError] = $this->sendInvitationEmail((string) $row->email, $roles, $row->department_unit, $link);

        DB::table('nurselink_admin_invitations')->where('id', $invitationId)->update([
            'token_hash' => hash('sha256', $token),
            'delivery_status' => $deliveryStatus,
            'delivery_error' => $deliveryError,
            'sent_at' => now(),
            'expires_at' => now()->addDays(self::INVITATION_TTL_DAYS),
            'updated_at' => now(),
        ]);

        $this->audit($request, 'administrator.invitation_resent', (string) $invitationId, null, [
            'email' => (string) $row->email,
            'delivery_status' => $deliveryStatus,
        ], 'Pending Administrator invitation was resent.', null, (string) $row->email, 'invitation');

        return response()->json([
            'message' => $deliveryStatus === 'sent' ? 'Administrator invitation resent.' : 'Invitation refreshed; copy the secure link because email delivery was unavailable.',
            'data' => ['invitation_link' => $link, 'delivery_status' => $deliveryStatus],
        ]);
    }

    public function cancel(Request $request, string $invitationId): JsonResponse
    {
        $access = $this->requireElevatedSession($request);
        $this->requireSuperAdmin($access);
        $this->ensureTables();

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'approval_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = DB::table('nurselink_admin_invitations')->where('id', $invitationId)->first();
        abort_unless($row, 404, 'Administrator invitation was not found.');
        abort_unless((string) $row->status === 'invitation_sent', 422, 'Only pending invitations can be cancelled.');

        DB::table('nurselink_admin_invitations')->where('id', $invitationId)->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($request, 'administrator.invitation_cancelled', (string) $invitationId, [
            'email' => (string) $row->email,
            'status' => (string) $row->status,
        ], [
            'email' => (string) $row->email,
            'status' => 'cancelled',
        ], trim((string) $data['reason']), trim((string) ($data['approval_notes'] ?? '')) ?: null, (string) $row->email, 'invitation');

        return response()->json(['message' => 'Administrator invitation cancelled.']);
    }

    public function accept(Request $request): JsonResponse
    {
        $this->ensureTables();

        $data = $request->validate([
            'token' => ['required', 'string', 'min:32', 'max:500'],
        ]);

        $user = $request->user();
        abort_unless($user, 401);

        if (method_exists($user, 'hasVerifiedEmail')) {
            abort_unless($user->hasVerifiedEmail(), 403, 'Verify your email address before accepting Administrator access.');
        }

        $row = DB::table('nurselink_admin_invitations')
            ->where('token_hash', hash('sha256', $data['token']))
            ->first();

        abort_unless($row, 404, 'Administrator invitation is invalid or no longer available.');
        abort_unless((string) $row->status === 'invitation_sent', 422, 'Administrator invitation is no longer pending.');
        abort_unless(! $row->expires_at || now()->timestamp <= strtotime((string) $row->expires_at), 422, 'Administrator invitation has expired. Ask a Super Administrator to resend it.');

        $userEmail = strtolower(trim((string) ($user->email ?? '')));
        abort_unless(hash_equals(strtolower((string) $row->email), $userEmail), 403, 'This Administrator invitation belongs to a different verified email address.');

        $roles = $this->decodeRoles($row->roles_json);
        $userId = (string) $user->getKey();

        DB::transaction(function () use ($row, $roles, $userId): void {
            DB::table('nurselink_admin_invitations')->where('id', $row->id)->update([
                'status' => 'accepted',
                'accepted_by_user_id' => $userId,
                'accepted_at' => now(),
                'updated_at' => now(),
            ]);

            $this->saveAssignments($userId, $roles, (string) $row->invited_by_user_id);
            $this->saveProfile($userId, $row->department_unit, true, true);
            $this->syncLegacyAccess($userId, $roles);

            DB::table('nurselink_admin_invitations')->where('id', $row->id)->update([
                'status' => 'active',
                'activated_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->audit($request, 'administrator.invitation_accepted_and_activated', (string) $row->id, [
            'email' => (string) $row->email,
            'status' => 'invitation_sent',
        ], [
            'user_id' => $userId,
            'email' => $userEmail,
            'roles' => $roles,
            'status' => 'active',
        ], 'Accepted by the verified invitation recipient.', null, $userEmail, 'invitation');

        return response()->json([
            'message' => 'Administrator invitation accepted. Your NurseLink Administrator access is now active.',
            'data' => [
                'status' => 'active',
                'roles' => $roles,
                'administrator_login' => '/nurselink-admin-login.html',
            ],
        ]);
    }

    public function governanceHistory(Request $request): JsonResponse
    {
        $access = $this->requireElevatedSession($request);
        $this->requireSuperAdmin($access);
        $this->ensureTables();

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:190'],
            'action' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = DB::table('nurselink_admin_governance_audit')->orderByDesc('id');
        $search = trim((string) ($data['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $like = '%' . $search . '%';
                $inner->where('subject_email', 'like', $like)
                    ->orWhere('action', 'like', $like)
                    ->orWhere('reason', 'like', $like)
                    ->orWhere('approval_notes', 'like', $like);
            });
        }

        $action = trim((string) ($data['action'] ?? ''));
        if ($action !== '') $query->where('action', $action);

        $rows = $query->limit((int) ($data['limit'] ?? 50))->get();
        $actorIds = $rows->pluck('actor_user_id')->filter()->map(fn ($id) => (string) $id)->unique()->values();
        $actors = $actorIds->isEmpty()
            ? collect()
            : DB::table('users')->whereIn('id', $actorIds->all())->get()->keyBy(fn ($row) => (string) $row->id);

        $history = $rows->map(function ($row) use ($actors): array {
            $actor = $actors->get((string) $row->actor_user_id);
            return [
                'id' => (string) $row->id,
                'action' => (string) $row->action,
                'subject_type' => (string) $row->subject_type,
                'subject_id' => $row->subject_id === null ? null : (string) $row->subject_id,
                'subject_email' => (string) ($row->subject_email ?? ''),
                'reason' => (string) ($row->reason ?? ''),
                'approval_notes' => (string) ($row->approval_notes ?? ''),
                'before_state' => $this->decodeState($row->before_state ?? null),
                'after_state' => $this->decodeState($row->after_state ?? null),
                'actor' => [
                    'user_id' => (string) $row->actor_user_id,
                    'name' => $this->displayName($actor),
                    'email' => (string) ($actor->email ?? ''),
                ],
                'created_at' => $row->created_at,
            ];
        })->values();

        $actions = DB::table('nurselink_admin_governance_audit')
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->map(fn ($value) => (string) $value)
            ->values();

        return response()->json([
            'data' => [
                'history' => $history,
                'actions' => $actions,
                'immutable' => true,
            ],
        ]);
    }

    public function update(Request $request, string $userId): JsonResponse
    {
        $access = $this->requireElevatedSession($request);
        $this->requireSuperAdmin($access);
        $this->ensureTables();

        $data = $request->validate([
            'department_unit' => ['nullable', 'string', 'max:190'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::in(array_keys(self::ROLES))],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'approval_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = DB::table('users')->where('id', $userId)->first();
        abort_unless($user, 404, 'Administrator user was not found.');

        $roles = array_values(array_unique($data['roles']));
        $currentRoles = $this->activeRoles($userId);
        $currentId = (string) $request->user()->getKey();
        $currentIsSuper = in_array('super_administrator', $currentRoles, true) || $this->legacyAccess($user)['is_super_admin'];
        $willRemainSuper = in_array('super_administrator', $roles, true);

        if ($userId === $currentId && $currentIsSuper && ! $willRemainSuper) {
            return response()->json(['message' => 'Use another Super Administrator account to remove your own Super Administrator role.'], 422);
        }

        if ($currentIsSuper && ! $willRemainSuper && $this->activeEffectiveSuperAdmins() <= 1) {
            return response()->json(['message' => 'The last active Super Administrator cannot be demoted.'], 422);
        }

        $before = [
            'roles' => $currentRoles,
            'department_unit' => $this->profileDepartment($userId),
        ];

        DB::transaction(function () use ($userId, $roles, $data, $request): void {
            $this->saveAssignments($userId, $roles, (string) $request->user()->getKey());
            $this->saveProfile($userId, trim((string) ($data['department_unit'] ?? '')) ?: null, true, false);
            $this->syncLegacyAccess($userId, $roles);
        });

        $after = [
            'roles' => $roles,
            'department_unit' => trim((string) ($data['department_unit'] ?? '')) ?: null,
        ];

        $this->audit(
            $request,
            'administrator.permissions_updated',
            $userId,
            $before,
            $after,
            trim((string) $data['reason']),
            trim((string) ($data['approval_notes'] ?? '')) ?: null,
            (string) $user->email,
            'administrator'
        );

        return response()->json([
            'message' => 'Administrator roles and permissions saved for ' . (string) $user->email . '.',
            'data' => $after,
        ]);
    }

    public function revoke(Request $request, string $userId): JsonResponse
    {
        $access = $this->requireElevatedSession($request);
        $this->requireSuperAdmin($access);
        $this->ensureTables();

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'approval_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $currentId = (string) $request->user()->getKey();
        if ($userId === $currentId) {
            return response()->json(['message' => 'A Super Administrator cannot revoke their own Administrator access from the browser.'], 422);
        }

        $user = DB::table('users')->where('id', $userId)->first();
        abort_unless($user, 404, 'Administrator user was not found.');

        $roles = $this->activeRoles($userId);
        $isEffectiveSuper = in_array('super_administrator', $roles, true) || $this->legacyAccess($user)['is_super_admin'];
        if ($isEffectiveSuper && $this->activeEffectiveSuperAdmins() <= 1) {
            return response()->json(['message' => 'The last active Super Administrator cannot be revoked.'], 422);
        }

        DB::transaction(function () use ($userId): void {
            DB::table('nurselink_admin_role_assignments')->where('user_id', $userId)->where('active', true)->update([
                'active' => false,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('nurselink_admin_profiles')->where('user_id', $userId)->update([
                'active' => false,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncLegacyAccess($userId, []);
        });

        $this->audit(
            $request,
            'administrator.access_revoked',
            $userId,
            ['roles' => $roles, 'active' => true],
            ['roles' => [], 'active' => false],
            trim((string) $data['reason']),
            trim((string) ($data['approval_notes'] ?? '')) ?: null,
            (string) $user->email,
            'administrator'
        );

        return response()->json(['message' => 'Administrator access revoked for ' . (string) $user->email . '.']);
    }

    private function ensureTables(): void
    {
        foreach (['nurselink_admin_profiles', 'nurselink_admin_role_assignments', 'nurselink_admin_invitations', 'nurselink_admin_governance_audit'] as $table) {
            abort_unless(Schema::hasTable($table), 503, 'NurseLink Administrator Management is not installed completely.');
        }
    }

    private function roleCatalog(): array
    {
        $rows = [];
        foreach (self::ROLES as $key => $meta) {
            $rows[] = ['key' => $key] + $meta;
        }
        return $rows;
    }

    private function decodeRoles(?string $value): array
    {
        $decoded = json_decode((string) $value, true);
        if (! is_array($decoded)) return [];
        return array_values(array_filter(array_unique(array_map('strval', $decoded)), fn ($role) => isset(self::ROLES[$role])));
    }

    private function activeRoles(string $userId): array
    {
        return DB::table('nurselink_admin_role_assignments')
            ->where('user_id', $userId)
            ->where('active', true)
            ->orderBy('role_key')
            ->pluck('role_key')
            ->map(fn ($role) => (string) $role)
            ->values()
            ->all();
    }

    private function scopesForRoles(array $roles): array
    {
        if (in_array('super_administrator', $roles, true)) return ['*'];
        if (in_array('auditor_read_only', $roles, true)) return ['read_only'];

        $scopes = ['portal'];
        foreach ($roles as $role) {
            foreach (self::ROLES[$role]['scopes'] ?? [] as $scope) $scopes[] = $scope;
        }
        return array_values(array_unique($scopes));
    }

    private function saveAssignments(string $userId, array $roles, string $actorId): void
    {
        DB::table('nurselink_admin_role_assignments')->where('user_id', $userId)->whereNotIn('role_key', $roles)->where('active', true)->update([
            'active' => false,
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($roles as $role) {
            DB::table('nurselink_admin_role_assignments')->updateOrInsert(
                ['user_id' => $userId, 'role_key' => $role],
                [
                    'active' => true,
                    'assigned_by_user_id' => $actorId ?: null,
                    'assigned_at' => now(),
                    'revoked_at' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function saveProfile(string $userId, ?string $department, bool $active, bool $accepted): void
    {
        $existing = DB::table('nurselink_admin_profiles')->where('user_id', $userId)->first();
        $values = [
            'department_unit' => $department,
            'active' => $active,
            'revoked_at' => $active ? null : now(),
            'updated_at' => now(),
        ];

        if ($active) $values['activated_at'] = now();
        if ($accepted) $values['accepted_at'] = now();

        if ($existing) {
            DB::table('nurselink_admin_profiles')->where('user_id', $userId)->update($values);
        } else {
            DB::table('nurselink_admin_profiles')->insert($values + [
                'user_id' => $userId,
                'created_at' => now(),
            ]);
        }
    }

    private function syncLegacyAccess(string $userId, array $roles): void
    {
        $active = $roles !== [];
        $isSuper = in_array('super_administrator', $roles, true);

        if (Schema::hasTable('nurselink_reviewer_access')) {
            DB::table('nurselink_reviewer_access')->updateOrInsert(
                ['user_id' => $userId],
                [
                    'role' => $isSuper ? 'admin' : 'admin',
                    'active' => $active,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        if (Schema::hasTable('nurselink_super_admin_access')) {
            $existing = DB::table('nurselink_super_admin_access')->where('user_id', $userId)->first();
            $values = [
                'active' => $isSuper,
                'note' => 'Synchronized by NurseLink Administrator Management v5.5.7',
                'granted_at' => $isSuper ? now() : ($existing->granted_at ?? null),
                'revoked_at' => $isSuper ? null : now(),
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('nurselink_super_admin_access')->where('user_id', $userId)->update($values);
            } else {
                DB::table('nurselink_super_admin_access')->insert($values + ['user_id' => $userId, 'created_at' => now()]);
            }
        }
    }

    private function newInvitationToken(): array
    {
        $token = Str::random(80);
        $link = 'https://app.amsertech.com/login?return=' . rawurlencode('/dashboard?admin_invite=' . $token);
        return [$token, $link];
    }

    private function sendInvitationEmail(string $email, array $roles, ?string $department, string $link): array
    {
        $labels = array_map(fn ($role) => self::ROLES[$role]['label'] ?? $role, $roles);
        $body = "You have been invited to NurseLink Administrator access.\n\n"
            . 'Roles: ' . implode(', ', $labels) . "\n"
            . ($department ? 'Department / Unit: ' . $department . "\n" : '')
            . "\nTo accept, sign in with this exact email address, verify the email if required, then open:\n"
            . $link
            . "\n\nThis invitation expires in " . self::INVITATION_TTL_DAYS . " days. Access is not activated until the invitation is accepted with a verified email address.";

        try {
            Mail::raw($body, function ($message) use ($email): void {
                $message->to($email)->subject('NurseLink Administrator Invitation');
            });
            return ['sent', null];
        } catch (Throwable $e) {
            return ['manual_link_required', Str::limit($e->getMessage(), 1000, '')];
        }
    }

    private function presentInvitation($row, bool $includeSensitive): array
    {
        $roles = $this->decodeRoles($row->roles_json ?? null);
        $payload = [
            'id' => (string) $row->id,
            'email' => (string) $row->email,
            'department_unit' => (string) ($row->department_unit ?? ''),
            'roles' => $roles,
            'role_labels' => array_map(fn ($role) => self::ROLES[$role]['label'] ?? $role, $roles),
            'status' => (string) $row->status,
            'delivery_status' => (string) ($row->delivery_status ?? ''),
            'sent_at' => $row->sent_at,
            'expires_at' => $row->expires_at,
            'accepted_at' => $row->accepted_at,
            'activated_at' => $row->activated_at,
        ];

        if ($includeSensitive) $payload['delivery_error'] = $row->delivery_error;
        return $payload;
    }

    private function profileDepartment(string $userId): ?string
    {
        $value = DB::table('nurselink_admin_profiles')->where('user_id', $userId)->value('department_unit');
        return $value === null ? null : (string) $value;
    }

    private function activeEffectiveSuperAdmins(): int
    {
        $ids = DB::table('nurselink_admin_role_assignments')
            ->where('role_key', 'super_administrator')
            ->where('active', true)
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id);

        if (Schema::hasTable('nurselink_super_admin_access')) {
            $ids = $ids->merge(
                DB::table('nurselink_super_admin_access')
                    ->where('active', true)
                    ->pluck('user_id')
                    ->map(fn ($id) => (string) $id)
            );
        }

        if (Schema::hasTable('nurselink_reviewer_access')) {
            $ids = $ids->merge(
                DB::table('nurselink_reviewer_access')
                    ->where('active', true)
                    ->where('role', 'super_admin')
                    ->pluck('user_id')
                    ->map(fn ($id) => (string) $id)
            );
        }

        return $ids->unique()->count();
    }

    private function requireElevatedSession(Request $request): array
    {
        $user = $request->user();
        abort_unless($user, 401);

        $sessionUserId = (string) $request->session()->get('nurselink_admin_elevated_user_id', '');
        $elevatedAt = (int) $request->session()->get('nurselink_admin_elevated_at', 0);
        $expiresAt = (int) $request->session()->get('nurselink_admin_expires_at', 0);

        abort_unless(
            $sessionUserId !== ''
            && hash_equals($sessionUserId, (string) $user->getKey())
            && $elevatedAt > 0
            && $expiresAt >= time()
            && (time() - $elevatedAt) <= self::ELEVATION_TTL_SECONDS,
            403,
            'A separate NurseLink administrator sign-in is required.'
        );

        $access = $this->legacyAccess($user);
        abort_unless($access['privileged'], 403, 'Administrator access is required.');
        return $access;
    }

    private function requireSuperAdmin(array $access): void
    {
        abort_unless($access['is_super_admin'], 403, 'Super Administrator access is required to manage NurseLink administrators.');
    }

    private function legacyAccess($user): array
    {
        $userId = $user->getKey();
        $reviewerAccess = Schema::hasTable('nurselink_reviewer_access')
            ? DB::table('nurselink_reviewer_access')->where('user_id', $userId)->where('active', true)->first()
            : null;
        $explicitSuperAdmin = Schema::hasTable('nurselink_super_admin_access')
            && DB::table('nurselink_super_admin_access')->where('user_id', $userId)->where('active', true)->exists();
        $modelRole = strtolower(trim((string) ($user->role ?? $user->user_role ?? $user->user_type ?? '')));
        $modelSuper = (bool) ($user->is_super_admin ?? false) || in_array($modelRole, ['super_admin', 'super-administrator', 'super_administrator', 'superadministrator'], true);
        $reviewRole = strtolower((string) ($reviewerAccess->role ?? ''));
        $isSuper = $explicitSuperAdmin || $modelSuper || $reviewRole === 'super_admin';
        $isAdmin = $isSuper || (bool) ($user->is_admin ?? false) || in_array($modelRole, ['admin', 'administrator'], true) || in_array($reviewRole, ['admin', 'super_admin'], true);
        return ['privileged' => $isAdmin || $reviewRole === 'reviewer', 'is_admin' => $isAdmin, 'is_super_admin' => $isSuper];
    }

    private function audit(
        Request $request,
        string $action,
        string $targetId,
        ?array $before,
        ?array $after,
        ?string $reason = null,
        ?string $approvalNotes = null,
        ?string $subjectEmail = null,
        string $subjectType = 'administrator'
    ): void {
        $actorId = (string) $request->user()->getKey();

        if (Schema::hasTable('nurselink_admin_governance_audit')) {
            DB::table('nurselink_admin_governance_audit')->insert([
                'actor_user_id' => $actorId,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $targetId,
                'subject_email' => $subjectEmail,
                'reason' => $reason,
                'approval_notes' => $approvalNotes,
                'before_state' => $before ? json_encode($before) : null,
                'after_state' => $after ? json_encode($after) : null,
                'request_ip_hash' => $request->ip() ? hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')) : null,
                'user_agent_hash' => $request->userAgent() ? hash_hmac('sha256', (string) $request->userAgent(), (string) config('app.key')) : null,
                'created_at' => now(),
            ]);
        }

        if (Schema::hasTable('nurselink_review_audit')) {
            DB::table('nurselink_review_audit')->insert([
                'reviewer_user_id' => $actorId,
                'action' => $action,
                'target_type' => 'administrator_management',
                'target_id' => $targetId,
                'before_state' => $before ? json_encode($before) : null,
                'after_state' => $after ? json_encode($after) : null,
                'created_at' => now(),
            ]);
        }
    }

    private function decodeState($value): ?array
    {
        if ($value === null || $value === '') return null;
        if (is_array($value)) return $value;
        if (is_object($value)) return (array) $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function displayName(?object $user): string
    {
        if (! $user) return 'Unknown User';
        $name = trim((string) ($user->name ?? ''));
        if ($name !== '') return $name;
        $combined = trim((string) ($user->first_name ?? '') . ' ' . (string) ($user->last_name ?? ''));
        return $combined !== '' ? $combined : (string) ($user->email ?? $user->id ?? 'Unknown User');
    }
}
