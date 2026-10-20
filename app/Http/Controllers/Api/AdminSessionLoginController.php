<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AdminSessionLoginController extends Controller
{
    private const ELEVATION_TTL_SECONDS = 28800; // 8 hours

    public function login(Request $request): JsonResponse
    {
        $this->requireFrontendOrigin($request);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'min:3', 'max:254'],
            'password' => ['required', 'string'],
        ]);

        $key = 'nurselink-admin|'
            . sha1(strtolower($credentials['email']) . '|' . (string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'message' => 'Too many administrator login attempts. Please try again shortly.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        if (Auth::guard('encoder')->check()) {
            Auth::guard('encoder')->logout();
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
            return $this->encoderLogin($request, $credentials, $key);
        }

        $user = Auth::guard('web')->user();

        $access = $this->resolvePrivilegedAccess($user);
        if (! $access['privileged']) {
            $this->terminateSession($request);
            return $this->encoderLogin($request, $credentials, $key);
        }

        if (
            method_exists($user, 'hasVerifiedEmail')
            && ! $user->hasVerifiedEmail()
        ) {
            $this->terminateSession($request);

            return response()->json([
                'message' => 'A verified NurseLink email is required for administrator access.',
            ], 403);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $now = time();

        $request->session()->put([
            'nurselink_admin_elevated_user_id' => (string) $user->getKey(),
            'nurselink_admin_elevated_at' => $now,
            'nurselink_admin_expires_at' => $now + self::ELEVATION_TTL_SECONDS,
        ]);

        return response()->json([
            'authenticated' => true,
            'admin_session' => true,
            'expires_in_seconds' => self::ELEVATION_TTL_SECONDS,
            'redirect' => $access['role'] === 'reviewer' ? '/admin/#applications' : '/admin/#dashboard',
            'data' => [
                'role' => $access['role'],
                'label' => $access['label'],
                'is_super_admin' => $access['is_super_admin'],
                'is_admin' => $access['is_admin'],
                'is_reviewer' => $access['is_reviewer'],
            ],
            'message' => 'Administrator session established.',
        ]);
    }

    private function encoderLogin(Request $request, array $credentials, string $key): JsonResponse
    {
        $request->merge(['username' => $credentials['email']]);
        $response = app(TemporaryEncoderSessionController::class)->login($request);
        if ($response->isSuccessful()) {
            RateLimiter::clear($key);
            $payload = $response->getData(true);
            $payload['redirect'] = '/admin/encoder.html';
            $response->setData($payload);
        }
        return $response;
    }

    public function logout(Request $request): JsonResponse
    {
        $this->requireFrontendOrigin($request);

        $this->terminateSession($request);

        return response()->json([
            'message' => 'Administrator session ended.',
        ]);
    }

    private function terminateSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function requireFrontendOrigin(Request $request): void
    {
        $origin = (string) $request->headers->get('Origin', '');

        abort_unless(
            $origin !== '' && hash_equals(rtrim((string) config('app.frontend_url'), '/'), $origin),
            403,
            'NurseLink frontend origin required.'
        );
    }

    private function resolvePrivilegedAccess($user): array
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

        $granularSuperAdmin = Schema::hasTable('nurselink_admin_role_assignments')
            && DB::table('nurselink_admin_role_assignments')
                ->where('user_id', $userId)
                ->where('role_key', 'super_administrator')
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
            || $granularSuperAdmin
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
            'label' => match ($role) {
                'super_admin' => 'Super Administrator',
                'admin' => 'Administrator',
                'reviewer' => 'Reviewer',
                default => 'User',
            },
            'is_super_admin' => $isSuperAdmin,
            'is_admin' => $isAdmin,
            'is_reviewer' => $isReviewer,
        ];
    }
}
