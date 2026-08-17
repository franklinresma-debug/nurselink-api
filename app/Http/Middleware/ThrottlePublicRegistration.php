<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottlePublicRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->is('register')) {
            return $next($request);
        }

        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $keys = [
            ['registration:ip:'.hash('sha256', (string) $request->ip()), 5, 60],
            ['registration:email:'.hash('sha256', $email), 3, 600],
        ];

        foreach ($keys as [$key, $maximumAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maximumAttempts)) {
                return $this->limitedResponse(RateLimiter::availableIn($key));
            }
        }

        foreach ($keys as [$key, , $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }

        return $next($request);
    }

    private function limitedResponse(int $retryAfter): JsonResponse
    {
        return response()->json([
            'message' => 'Too many registration attempts. Please wait before trying again.',
        ], 429, [
            'Retry-After' => (string) max(1, $retryAfter),
        ]);
    }
}
