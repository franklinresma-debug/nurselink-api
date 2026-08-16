<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireMfaWhenRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->mfa_required && !$user->two_factor_confirmed_at) {
            return response()->json([
                'message' => 'Multi-factor authentication must be configured before this area can be used.',
                'code' => 'mfa_setup_required',
                'action' => 'enable_two_factor_authentication',
            ], 423);
        }

        return $next($request);
    }
}
