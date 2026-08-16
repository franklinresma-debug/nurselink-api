<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureNurseLinkAdminPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $userId = (string) $user->getKey();
        $roles = $this->rolesFor($userId);

        if (in_array('super_administrator', $roles, true) || $this->legacySuperAdmin($user)) {
            return $next($request);
        }

        // Legacy privileged users remain compatible until their first granular role assignment.
        if ($roles === []) {
            if ($this->legacyPrivileged($user)) {
                return $next($request);
            }

            abort(403, 'Administrator permission is required for this NurseLink area.');
        }

        $scope = $this->scopeFor($request);
        $method = strtoupper($request->method());

        if (in_array('auditor_read_only', $roles, true)) {
            abort_unless(in_array($method, ['GET', 'HEAD', 'OPTIONS'], true), 403, 'Auditor / Read Only access cannot change NurseLink records.');
            abort_unless($scope !== 'admin_management', 403, 'Administrator Management is restricted to Super Administrators.');
            return $next($request);
        }

        $allowed = false;
        foreach ($roles as $role) {
            if (in_array($scope, $this->scopesForRole($role), true)) {
                $allowed = true;
                break;
            }
        }

        abort_unless($allowed, 403, 'Your Administrator role does not include access to this NurseLink area.');

        return $next($request);
    }

    private function rolesFor(string $userId): array
    {
        if (! Schema::hasTable('nurselink_admin_role_assignments')) {
            return [];
        }

        return DB::table('nurselink_admin_role_assignments')
            ->where('user_id', $userId)
            ->where('active', true)
            ->orderBy('role_key')
            ->pluck('role_key')
            ->map(fn ($role) => strtolower((string) $role))
            ->values()
            ->all();
    }

    private function scopeFor(Request $request): string
    {
        $path = '/' . ltrim($request->path(), '/');

        if (str_contains($path, '/admin/management/me')) return 'portal';
        if (str_contains($path, '/admin/users') || str_contains($path, '/admin/management')) return 'admin_management';
        if (str_contains($path, '/operations-center/support-cases')) return 'support';
        if (str_contains($path, '/operations-center/communications')) return 'communications';
        if (str_contains($path, '/operations-center/audit-log')) return 'reports';
        if (str_contains($path, '/operations-center/settings')) return 'admin_management';
        if (str_contains($path, '/operations-center/summary')) return 'portal';
        if (str_contains($path, 'credential') || str_contains($path, '/reviewer/credentials')) return 'verification';
        if (str_contains($path, 'membership') || str_contains($path, 'member-registry') || str_contains($path, 'onboarding')) return 'membership';
        if (str_contains($path, 'job-') || str_contains($path, 'opportunit') || str_contains($path, 'partner-organizations') || str_contains($path, 'partner-access')) return 'employment';
        if (str_contains($path, 'event')) return 'training';
        if (str_contains($path, 'notification') || str_contains($path, 'communication')) return 'communications';
        if (str_contains($path, 'support-case') || str_contains($path, '/support')) return 'support';
        if (str_contains($path, 'analytics') || str_contains($path, '/reports') || str_contains($path, '/audit')) return 'reports';
        if (str_contains($path, 'benefit') || str_contains($path, 'content')) return 'content';
        if (str_contains($path, 'chapter') || str_contains($path, 'mentor') || str_contains($path, 'engagement') || str_contains($path, 'enterprise')) return 'programs';
        if (str_contains($path, 'production-readiness') || str_contains($path, 'operations-center') || str_contains($path, '/health')) return 'health';

        return 'portal';
    }

    private function scopesForRole(string $role): array
    {
        return match ($role) {
            'membership_administrator' => ['portal', 'membership', 'reports'],
            'verification_officer' => ['portal', 'verification', 'membership', 'reports'],
            'content_administrator' => ['portal', 'content', 'communications'],
            'program_administrator' => ['portal', 'programs', 'training', 'reports'],
            'employment_administrator' => ['portal', 'employment', 'reports'],
            'training_events_administrator' => ['portal', 'training', 'programs'],
            'communications_administrator' => ['portal', 'communications'],
            'support_officer' => ['portal', 'support', 'communications'],
            'finance_treasurer' => ['portal', 'finance', 'reports'],
            'reports_analytics' => ['portal', 'reports'],
            default => [],
        };
    }

    private function legacySuperAdmin($user): bool
    {
        $userId = $user->getKey();

        if (Schema::hasTable('nurselink_super_admin_access') && DB::table('nurselink_super_admin_access')->where('user_id', $userId)->where('active', true)->exists()) {
            return true;
        }

        $modelRole = strtolower(trim((string) ($user->role ?? $user->user_role ?? $user->user_type ?? '')));
        return (bool) ($user->is_super_admin ?? false) || in_array($modelRole, ['super_admin', 'super-administrator', 'super_administrator', 'superadministrator'], true);
    }

    private function legacyPrivileged($user): bool
    {
        if ($this->legacySuperAdmin($user)) return true;

        $userId = $user->getKey();
        if (Schema::hasTable('nurselink_reviewer_access') && DB::table('nurselink_reviewer_access')->where('user_id', $userId)->where('active', true)->exists()) {
            return true;
        }

        $modelRole = strtolower(trim((string) ($user->role ?? $user->user_role ?? $user->user_type ?? '')));
        return (bool) ($user->is_admin ?? false) || in_array($modelRole, ['admin', 'administrator'], true);
    }
}
