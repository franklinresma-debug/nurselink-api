<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->status !== 'active') {
            auth()->guard('web')->logout();

            return response()->json([
                'message' => 'This NurseLink account is not active.',
                'code' => 'account_inactive',
            ], 403);
        }

        return $next($request);
    }
}
