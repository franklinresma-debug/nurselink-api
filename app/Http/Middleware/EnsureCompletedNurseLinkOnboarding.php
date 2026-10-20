<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompletedNurseLinkOnboarding
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user, 401);
        $access=app(\App\Services\ApplicantPortalAccess::class);
        if($access->eligible($user)){$access->ownRecord($user);return $next($request);}

        $membership = DB::table('nurselink_memberships')
            ->where('user_id', (string) $user->getKey())
            ->where('status', 'approved')
            ->first();

        abort_unless(
            $membership,
            403,
            'Approved NurseLink membership is required.'
        );

        $standing = strtolower(trim((string) ($membership->standing ?? 'active')));

        abort_unless(
            $standing === '' || $standing === 'active',
            403,
            'Active NurseLink membership standing is required.'
        );

        $onboarding = DB::table('nurselink_membership_onboarding')
            ->where('membership_id', $membership->id)
            ->first();

        abort_unless(
            $onboarding && (string) $onboarding->status === 'completed',
            403,
            'Complete NurseLink member onboarding before using this feature.'
        );

        return $next($request);
    }
}
