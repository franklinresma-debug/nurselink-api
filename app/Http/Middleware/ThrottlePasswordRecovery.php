<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottlePasswordRecovery
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $limits = match ($request->path()) {
            'forgot-password' => [5, 60, 3, 600],
            'reset-password' => [10, 60, 5, 600],
            default => null,
        };

        if ($limits === null) {
            return $next($request);
        }

        [$ipMaximum, $ipDecay, $emailMaximum, $emailDecay] = $limits;
        $scope = str_replace('-', ':', $request->path());
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $keys = [
            ["{$scope}:ip:".hash('sha256', (string) $request->ip()), $ipMaximum, $ipDecay],
            ["{$scope}:email:".hash('sha256', $email), $emailMaximum, $emailDecay],
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
            'message' => 'Too many password recovery attempts. Please wait before trying again.',
        ], 429, [
            'Retry-After' => (string) max(1, $retryAfter),
        ]);
    }
}
