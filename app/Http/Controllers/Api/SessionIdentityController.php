<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SessionIdentityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

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

        $modelAdmin = (bool) ($user->is_admin ?? false)
            || in_array($modelRole, ['admin', 'administrator'], true);

        $isAdmin = $isSuperAdmin
            || $modelAdmin
            || in_array($reviewRole, ['admin', 'super_admin'], true);

        $isReviewer = $isAdmin || $reviewRole === 'reviewer';

        $role = match (true) {
            $isSuperAdmin => 'super_admin',
            $isAdmin => 'admin',
            $isReviewer => 'reviewer',
            default => $modelRole !== '' ? $modelRole : 'user',
        };

        $label = match ($role) {
            'super_admin' => 'Super Administrator',
            'admin', 'administrator' => 'Administrator',
            'reviewer' => 'Reviewer',
            'applicant' => 'Applicant',
            'member' => 'Member',
            default => ucwords(str_replace(['_', '-'], ' ', $role)),
        };

        return response()->json([
            'data' => [
                'role' => $role,
                'label' => $label,
                'is_super_admin' => $isSuperAdmin,
                'is_admin' => $isAdmin,
                'is_reviewer' => $isReviewer,
                'privileged_session' => $isAdmin || $isReviewer,
            ],
            'security' => [
                'server_confirmed' => true,
                'client_role_inference' => false,
            ],
        ]);
    }
}
