<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TemporaryEncoderAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class TemporaryEncoderSessionController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $origin = (string) $request->headers->get('Origin', '');

        abort_unless(
            $origin !== '' && hash_equals(rtrim((string) config('app.frontend_url'), '/'), $origin),
            403,
            'NurseLink frontend origin required.'
        );

        $data = $request->validate([
            'username' => [
                'required',
                'string',
                'min:3',
                'max:80',
            ],
            'password' => [
                'required',
                'string',
            ],
        ]);

        /*
         * Temporary Encoders may authenticate using either their
         * assigned username or their assigned email address.
         *
         * The request key remains "username" for backwards
         * compatibility with existing encoder clients.
         */
        $identifier = mb_strtolower(
            trim($data['username'])
        );

        $key = 'encoder-login:'
            . hash(
                'sha256',
                $identifier . '|' . (string) $request->ip()
            );

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->corsJson([
                'message' =>
                    'Too many encoder login attempts. Please try again shortly.',
                'retry_after' =>
                    RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $account = TemporaryEncoderAccount::query()
            ->where(function ($query) use ($identifier): void {
                $query
                    ->whereRaw(
                        'LOWER(username) = ?',
                        [$identifier]
                    )
                    ->orWhereRaw(
                        'LOWER(email) = ?',
                        [$identifier]
                    );
            })
            ->first();

        if ($account && str_starts_with($account->username, 'staff-admin-')) {
            return $this->corsJson(['message' => 'Use your administrator sign-in for this workspace.'], 403);
        }

        if (
            ! $account
            || ! Hash::check(
                $data['password'],
                $account->password
            )
        ) {
            return $this->corsJson([
                'message' =>
                    'The provided encoder credentials do not match our records.',
                'errors' => [
                    'username' => [
                        'The provided encoder credentials do not match our records.',
                    ],
                ],
            ], 422);
        }

        if (! $account->active) {
            return $this->corsJson([
                'message' =>
                    'This Temporary Encoder account has been disabled.',
            ], 403);
        }

        if (
            ! $account->expires_at
            || ! $account->expires_at->isFuture()
        ) {
            return $this->corsJson([
                'message' =>
                    'This Temporary Encoder account has expired.',
            ], 403);
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        if (Auth::guard('encoder')->check()) {
            Auth::guard('encoder')->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Auth::guard('encoder')->login($account);

        $request->session()->regenerate();

        $account->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => (string) $request->ip(),
        ])->save();

        RateLimiter::clear($key);

        $this->audit(
            $request,
            $account,
            'encoder.session_login'
        );

        return $this->corsJson([
            'authenticated' => true,
            'account_type' => 'temporary_encoder',
            'message' => 'Temporary Encoder signed in.',
            'data' => [
                'id' => $account->id,
                'username' => $account->username,
                'email' => $account->email,
                'display_name' => $account->display_name,
                'expires_at' =>
                    $account->expires_at?->toIso8601String(),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $account = Auth::guard('encoder')->user();

        abort_unless(
            $account instanceof TemporaryEncoderAccount,
            401
        );

        abort_unless(
            $account->isUsable(),
            403,
            'Temporary Encoder access has expired or been disabled.'
        );

        return $this->corsJson([
            'data' => [
                'id' => $account->id,
                'username' => $account->username,
                'email' => $account->email,
                'display_name' => $account->display_name,
                'expires_at' =>
                    $account->expires_at?->toIso8601String(),
                'account_type' => $request->attributes->get('administrator_encoder_context') ? 'administrator' : 'temporary_encoder',
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $account = Auth::guard('encoder')->user();

        if ($account instanceof TemporaryEncoderAccount) {
            $this->audit(
                $request,
                $account,
                'encoder.session_logout'
            );
        }

        Auth::guard('encoder')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->corsJson([
            'message' => 'Temporary Encoder signed out.',
        ]);
    }

    private function corsJson(
        array $payload,
        int $status = 200
    ): JsonResponse {
        return response()
            ->json($payload, $status)
            ->withHeaders([
                'Access-Control-Allow-Origin' =>
                    rtrim((string) config('app.frontend_url'), '/'),
                'Access-Control-Allow-Credentials' => 'true',
                'Vary' => 'Origin',
            ]);
    }

    private function audit(
        Request $request,
        TemporaryEncoderAccount $account,
        string $action
    ): void {
        \Illuminate\Support\Facades\DB::table(
            'temporary_encoder_audit'
        )->insert([
            'encoder_account_id' => $account->id,
            'nurse_user_id' => null,
            'action' => $action,
            'target_type' => 'temporary_encoder_account',
            'target_id' => (string) $account->id,
            'before_state' => null,
            'after_state' => null,
            'ip_address' => (string) $request->ip(),
            'user_agent' => mb_substr(
                (string) $request->userAgent(),
                0,
                2000
            ),
            'created_at' => now(),
        ]);
    }
}
