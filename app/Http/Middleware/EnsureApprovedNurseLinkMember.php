<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureApprovedNurseLinkMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user, 401);

        $userId = (string) $user->getKey();

        /*
         * Super Administrator Test Mode is an explicit, short-lived,
         * server-confirmed QA capability. It bypasses this member-only gate
         * only while the same user also has an active Administrator Portal
         * elevation and active Super Administrator authorization.
         *
         * It does NOT mutate membership status, issue a member number, change
         * public verification, or bypass partner-organization tenancy.
         */
        if ($this->superAdminTestModeActive($request, $user)) {
            $response = $next($request);
            $response->headers->set(
                'X-NurseLink-Test-Mode',
                'super-admin'
            );

            return $response;
        }

        $membership = DB::table('nurselink_memberships')
            ->where('user_id', $userId)
            ->first();

        if ($membership && $membership->status === 'approved') {
            $standing = strtolower(trim((string) (
                $membership->standing ?? 'active'
            )));

            if ($standing === '' || $standing === 'active') {
                return $next($request);
            }

            abort(
                403,
                'Active NurseLink membership standing is required for this member-only service. Current standing: '
                . ucfirst($standing)
                . '.'
            );
        }

        /*
         * Compatibility bridge for members approved before the dedicated
         * nurselink_memberships table existed. Only an existing core
         * users.member_number is trusted.
         */
        $coreMemberNumber = null;

        if (Schema::hasColumn('users', 'member_number')) {
            $candidate = trim((string) ($user->member_number ?? ''));

            if ($candidate !== '') {
                $coreMemberNumber = $candidate;
            }
        }

        if ($coreMemberNumber !== null) {
            if ($membership) {
                DB::table('nurselink_memberships')
                    ->where('id', $membership->id)
                    ->update([
                        'status' => 'approved',
                        'member_number' => $membership->member_number ?: $coreMemberNumber,
                        'verification_code' => $membership->verification_code
                            ?: Str::lower(Str::random(40)),
                        'approved_at' => $membership->approved_at ?: now(),
                        'standing' => 'active',
                        'standing_changed_at' => $membership->standing_changed_at ?: now(),
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('nurselink_memberships')->insert([
                    'user_id' => $userId,
                    'status' => 'approved',
                    'member_number' => $coreMemberNumber,
                    'verification_code' => Str::lower(Str::random(40)),
                    'approved_at' => now(),
                    'standing' => 'active',
                    'standing_changed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $next($request);
        }

        abort(
            403,
            'Approved NurseLink membership is required for this member-only service.'
        );
    }
    private function superAdminTestModeActive(
        Request $request,
        $user
    ): bool {
        $userId = (string) $user->getKey();

        $modeUserId = (string) $request->session()->get(
            'nurselink_super_admin_test_mode_user_id',
            ''
        );

        $modeExpiresAt = (int) $request->session()->get(
            'nurselink_super_admin_test_mode_expires_at',
            0
        );

        $elevatedUserId = (string) $request->session()->get(
            'nurselink_admin_elevated_user_id',
            ''
        );

        $adminExpiresAt = (int) $request->session()->get(
            'nurselink_admin_expires_at',
            0
        );

        if (
            $modeUserId === ''
            || ! hash_equals($modeUserId, $userId)
            || $modeExpiresAt < time()
            || $elevatedUserId === ''
            || ! hash_equals($elevatedUserId, $userId)
            || $adminExpiresAt < time()
        ) {
            return false;
        }

        $explicitSuperAdmin = Schema::hasTable(
            'nurselink_super_admin_access'
        )
            && DB::table('nurselink_super_admin_access')
                ->where('user_id', $userId)
                ->where('active', true)
                ->exists();

        $reviewRole = Schema::hasTable(
            'nurselink_reviewer_access'
        )
            ? strtolower((string) (
                DB::table('nurselink_reviewer_access')
                    ->where('user_id', $userId)
                    ->where('active', true)
                    ->value('role')
                ?? ''
            ))
            : '';

        $modelRole = strtolower(trim((string) (
            $user->role
            ?? $user->user_role
            ?? $user->user_type
            ?? ''
        )));

        $modelSuperAdmin = (bool) (
            $user->is_super_admin
            ?? false
        ) || in_array(
            $modelRole,
            [
                'super_admin',
                'super-administrator',
                'super_administrator',
                'superadministrator',
            ],
            true
        );

        return $explicitSuperAdmin
            || $reviewRole === 'super_admin'
            || $modelSuperAdmin;
    }

}
