<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SessionLoginController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $origin = (string) $request->headers->get('Origin', '');

        abort_unless(
            hash_equals('https://app.amsertech.com', $origin),
            403,
            'NurseLink frontend origin required.'
        );

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = sha1(strtolower($credentials['email']) . '|' . (string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'message' => 'Too many login attempts. Please try again shortly.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        RateLimiter::hit($key, 60);

        if (! Auth::guard('web')->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ])) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return response()->json([
            'authenticated' => true,
            'message' => 'Signed in successfully.',
        ]);
    }
}
