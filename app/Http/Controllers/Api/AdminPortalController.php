<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminPortalController extends Controller
{
    private const ELEVATION_TTL_SECONDS = 28800; // 8 hours

    public function session(Request $request): JsonResponse
    {
        $access = $this->requireElevatedSession($request);

        return response()->json([
            'data' => [
                'user' => $this->presentCurrentUser($request->user()),
                'access' => $access,
                'expires_at' => (int) $request->session()->get(
                    'nurselink_admin_expires_at',
                    0
                ),
            ],
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $access = $this->requireElevatedSession($request);

        return response()->json([
            'data' => [
                'access' => $access,
                'counts' => [
                    'membership_pending' => Schema::hasTable('nurselink_memberships')
                        ? DB::table('nurselink_memberships')
                            ->whereIn('status', [
                                'submitted',
                                'under_review',
                                'needs_information',
                                'ready_for_approval',
                            ])
                            ->count()
                        : 0,
                    'credentials_pending' => Schema::hasTable('nurselink_credentials_registry')
                        ? DB::table('nurselink_credentials_registry')
                            ->whereIn('verification_status', ['unverified', 'pending'])
                            ->count()
                        : 0,
                    'job_applications_active' => Schema::hasTable('nurselink_job_applications')
                        ? DB::table('nurselink_job_applications')
                            ->whereNotIn('status', ['declined', 'withdrawn'])
                            ->count()
                        : 0,
                    'active_jobs' => Schema::hasTable('nurselink_job_opportunities')
                        ? DB::table('nurselink_job_opportunities')
                            ->where('status', 'active')
                            ->count()
                        : 0,
                    'privileged_users' => $this->privilegedUserIds()->count(),
                    'super_administrators' => Schema::hasTable('nurselink_super_admin_access')
                        ? DB::table('nurselink_super_admin_access')
                            ->where('active', true)
                            ->count()
                        : 0,
                ],
            ],
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $access = $this->requireElevatedSession($request);

        abort_unless(
            $access['is_admin'],
            403,
            'Administrator access is required to view staff access.'
        );

        $ids = $this->privilegedUserIds();

        if ($ids->isEmpty()) {
            return response()->json([
                'data' => [],
                'permissions' => [
                    'can_manage_access' => $access['is_super_admin'],
                ],
            ]);
        }

        $users = DB::table('users')
            ->whereIn('id', $ids->all())
            ->orderBy('email')
            ->get()
            ->keyBy(fn ($user) => (string) $user->id);

        $reviewerRows = Schema::hasTable('nurselink_reviewer_access')
            ? DB::table('nurselink_reviewer_access')
                ->whereIn('user_id', $ids->all())
                ->get()
                ->keyBy(fn ($row) => (string) $row->user_id)
            : collect();

        $superRows = Schema::hasTable('nurselink_super_admin_access')
            ? DB::table('nurselink_super_admin_access')
                ->whereIn('user_id', $ids->all())
                ->get()
                ->keyBy(fn ($row) => (string) $row->user_id)
            : collect();

        $rows = $ids->map(function ($id) use (
            $users,
            $reviewerRows,
            $superRows,
            $request
        ): array {
            $key = (string) $id;
            $user = $users->get($key);
            $review = $reviewerRows->get($key);
            $super = $superRows->get($key);

            $superActive = (bool) ($super?->active ?? false);
            $reviewActive = (bool) ($review?->active ?? false);

            $role = $superActive
                ? 'super_admin'
                : ($reviewActive ? (string) $review->role : 'revoked');

            return [
                'user_id' => $key,
                'name' => $this->displayName($user),
                'email' => (string) ($user->email ?? ''),
                'role' => $role,
                'role_label' => $this->roleLabel($role),
                'active' => $superActive || $reviewActive,
                'is_super_admin' => $superActive,
                'is_current_user' => $key === (string) $request->user()->getKey(),
                'updated_at' => $super?->updated_at
                    ?? $review?->updated_at
                    ?? null,
            ];
        })->sortBy(function (array $row): string {
            return ($row['active'] ? '0' : '1')
                . '|'
                . strtolower((string) $row['email']);
        })->values();

        return response()->json([
            'data' => $rows,
            'permissions' => [
                'can_manage_access' => $access['is_super_admin'],
                'cannot_revoke_self' => true,
                'protect_last_super_admin' => true,
            ],
        ]);
    }

    public function grant(Request $request): JsonResponse
    {
        $access = $this->requireElevatedSession($request);
        $this->requireSuperAdmin($access);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in([
                'reviewer',
                'admin',
                'super_admin',
            ])],
        ]);

        $user = DB::table('users')
            ->where('email', $data['email'])
            ->first();

        abort_unless($user, 404, 'No NurseLink user was found with that email.');

        $targetId = (string) $user->id;
        $currentId = (string) $request->user()->getKey();

        if (
            $targetId === $currentId
            && $data['role'] !== 'super_admin'
            && $access['is_super_admin']
        ) {
            return response()->json([
                'message' => 'Use another Super Administrator account to change your own highest-level access.',
            ], 422);
        }

        $before = $this->accessState($targetId);

        if (
            $before['super_admin']
            && $data['role'] !== 'super_admin'
            && $this->activeExplicitSuperAdmins() <= 1
        ) {
            return response()->json([
                'message' => 'The last active Super Administrator cannot be demoted.',
            ], 422);
        }

        $this->setReviewerAccess(
            $targetId,
            $data['role'] === 'reviewer' ? 'reviewer' : 'admin'
        );

        if ($data['role'] === 'super_admin') {
            $this->setSuperAdminAccess($targetId, true);
        } else {
            $this->setSuperAdminAccess($targetId, false);
        }

        $after = $this->accessState($targetId);

        $this->audit(
            $request,
            'staff_access.granted_or_changed',
            $targetId,
            $before,
            $after
        );

        return response()->json([
            'message' => $this->roleLabel($data['role'])
                . ' access saved for ' . (string) $user->email . '.',
            'data' => [
                'user_id' => $targetId,
                'email' => (string) $user->email,
                'role' => $data['role'],
                'role_label' => $this->roleLabel($data['role']),
            ],
        ]);
    }

    public function revoke(Request $request, string $userId): JsonResponse
    {
        $access = $this->requireElevatedSession($request);
        $this->requireSuperAdmin($access);

        $currentId = (string) $request->user()->getKey();

        if ($userId === $currentId) {
            return response()->json([
                'message' => 'A Super Administrator cannot revoke their own access from the browser.',
            ], 422);
        }

        $user = DB::table('users')->where('id', $userId)->first();
        abort_unless($user, 404);

        $before = $this->accessState($userId);

        if (
            $before['super_admin']
            && $this->activeExplicitSuperAdmins() <= 1
        ) {
            return response()->json([
                'message' => 'The last active Super Administrator cannot be revoked.',
            ], 422);
        }

        if (Schema::hasTable('nurselink_reviewer_access')) {
            DB::table('nurselink_reviewer_access')
                ->where('user_id', $userId)
                ->update([
                    'active' => false,
                    'updated_at' => now(),
                ]);
        }

        $this->setSuperAdminAccess($userId, false);

        $after = $this->accessState($userId);

        $this->audit(
            $request,
            'staff_access.revoked',
            $userId,
            $before,
            $after
        );

        return response()->json([
            'message' => 'Privileged NurseLink access revoked for '
                . (string) ($user->email ?? $userId) . '.',
        ]);
    }

    private function requireElevatedSession(Request $request): array
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
            && hash_equals($sessionUserId, (string) $user->getKey())
            && $elevatedAt > 0
            && $expiresAt >= time()
            && (time() - $elevatedAt) <= self::ELEVATION_TTL_SECONDS,
            403,
            'A separate NurseLink administrator sign-in is required.'
        );

        $access = $this->resolveAccess($user);

        abort_unless(
            $access['privileged'],
            403,
            'Administrator or reviewer access is required.'
        );

        return $access;
    }

    private function requireSuperAdmin(array $access): void
    {
        abort_unless(
            $access['is_super_admin'],
            403,
            'Super Administrator access is required to manage staff roles.'
        );
    }

    private function resolveAccess($user): array
    {
        $userId = $user->getKey();

        $reviewerAccess = Schema::hasTable('nurselink_reviewer_access')
            ? DB::table('nurselink_reviewer_access')
                ->where('user_id', $userId)
                ->where('active', true)
                ->first()
            : null;

        $explicitSuperAdmin = Schema::hasTable('nurselink_super_admin_access')
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

        $modelSuperAdmin = (bool) ($user->is_super_admin ?? false)
            || in_array($modelRole, [
                'super_admin',
                'super-administrator',
                'super_administrator',
                'superadministrator',
            ], true);

        $reviewRole = strtolower((string) ($reviewerAccess->role ?? ''));

        $isSuperAdmin = $explicitSuperAdmin
            || $modelSuperAdmin
            || $reviewRole === 'super_admin';

        $isAdmin = $isSuperAdmin
            || (bool) ($user->is_admin ?? false)
            || in_array($modelRole, ['admin', 'administrator'], true)
            || in_array($reviewRole, ['admin', 'super_admin'], true);

        $isReviewer = $isAdmin || $reviewRole === 'reviewer';

        $role = match (true) {
            $isSuperAdmin => 'super_admin',
            $isAdmin => 'admin',
            $isReviewer => 'reviewer',
            default => 'user',
        };

        return [
            'privileged' => $isReviewer,
            'role' => $role,
            'label' => $this->roleLabel($role),
            'is_super_admin' => $isSuperAdmin,
            'is_admin' => $isAdmin,
            'is_reviewer' => $isReviewer,
        ];
    }

    private function privilegedUserIds()
    {
        $ids = collect();

        if (Schema::hasTable('nurselink_reviewer_access')) {
            $ids = $ids->merge(
                DB::table('nurselink_reviewer_access')
                    ->pluck('user_id')
                    ->map(fn ($value) => (string) $value)
            );
        }

        if (Schema::hasTable('nurselink_super_admin_access')) {
            $ids = $ids->merge(
                DB::table('nurselink_super_admin_access')
                    ->pluck('user_id')
                    ->map(fn ($value) => (string) $value)
            );
        }

        return $ids->unique()->values();
    }

    private function setReviewerAccess(string $userId, string $role): void
    {
        if (! Schema::hasTable('nurselink_reviewer_access')) {
            abort(500, 'NurseLink reviewer access table is unavailable.');
        }

        $exists = DB::table('nurselink_reviewer_access')
            ->where('user_id', $userId)
            ->exists();

        $payload = [
            'role' => $role,
            'active' => true,
            'updated_at' => now(),
        ];

        if ($exists) {
            DB::table('nurselink_reviewer_access')
                ->where('user_id', $userId)
                ->update($payload);
        } else {
            DB::table('nurselink_reviewer_access')->insert([
                ...$payload,
                'user_id' => $userId,
                'created_at' => now(),
            ]);
        }
    }

    private function setSuperAdminAccess(string $userId, bool $active): void
    {
        if (! Schema::hasTable('nurselink_super_admin_access')) {
            abort(500, 'NurseLink Super Administrator access table is unavailable.');
        }

        $exists = DB::table('nurselink_super_admin_access')
            ->where('user_id', $userId)
            ->exists();

        if (! $exists && ! $active) {
            return;
        }

        $payload = [
            'active' => $active,
            'granted_at' => $active ? now() : null,
            'revoked_at' => $active ? null : now(),
            'updated_at' => now(),
        ];

        if ($exists) {
            DB::table('nurselink_super_admin_access')
                ->where('user_id', $userId)
                ->update($payload);
        } else {
            DB::table('nurselink_super_admin_access')->insert([
                ...$payload,
                'user_id' => $userId,
                'note' => 'Granted from NurseLink Administrator Portal',
                'created_at' => now(),
            ]);
        }
    }

    private function activeExplicitSuperAdmins(): int
    {
        return Schema::hasTable('nurselink_super_admin_access')
            ? (int) DB::table('nurselink_super_admin_access')
                ->where('active', true)
                ->count()
            : 0;
    }

    private function accessState(string $userId): array
    {
        $review = Schema::hasTable('nurselink_reviewer_access')
            ? DB::table('nurselink_reviewer_access')
                ->where('user_id', $userId)
                ->first()
            : null;

        $super = Schema::hasTable('nurselink_super_admin_access')
            ? DB::table('nurselink_super_admin_access')
                ->where('user_id', $userId)
                ->first()
            : null;

        return [
            'review_role' => $review?->role,
            'review_active' => (bool) ($review?->active ?? false),
            'super_admin' => (bool) ($super?->active ?? false),
        ];
    }

    private function audit(
        Request $request,
        string $action,
        string $targetId,
        ?array $before,
        ?array $after
    ): void {
        if (! Schema::hasTable('nurselink_review_audit')) {
            return;
        }

        DB::table('nurselink_review_audit')->insert([
            'reviewer_user_id' => (string) $request->user()->getKey(),
            'action' => $action,
            'target_type' => 'staff_access',
            'target_id' => $targetId,
            'before_state' => $before ? json_encode($before) : null,
            'after_state' => $after ? json_encode($after) : null,
            'created_at' => now(),
        ]);
    }

    private function presentCurrentUser($user): array
    {
        return [
            'id' => (string) $user->getKey(),
            'name' => $this->displayName($user),
            'email' => (string) ($user->email ?? ''),
        ];
    }

    private function displayName(?object $user): string
    {
        if (! $user) return 'Unknown User';

        $name = trim((string) ($user->name ?? ''));

        if ($name !== '') return $name;

        $combined = trim(
            (string) ($user->first_name ?? '')
            . ' '
            . (string) ($user->last_name ?? '')
        );

        if ($combined !== '') return $combined;

        return (string) ($user->email ?? $user->id ?? 'Unknown User');
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'super_admin' => 'Super Administrator',
            'admin' => 'Administrator',
            'reviewer' => 'Reviewer',
            'revoked' => 'Revoked',
            default => 'User',
        };
    }
}
