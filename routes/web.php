<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'product' => 'NurseLink by KAPIT-BISIG',
    'build' => config('operations.build'),
    'api' => '/api/health',
]));

// Fortify needs this named route even when views are disabled so reset notifications can resolve.
Route::get('/reset-password/{token}', function (string $token) {
    $frontend = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

    return redirect($frontend.'/reset-password?token='.$token.'&email='.urlencode((string) request('email')));
})->name('password.reset');

// Laravel's verified middleware redirects browser requests here. Keeping the
// named route in the API application prevents an unverified request from
// becoming a 500 when the frontend omits an Accept: application/json header.
Route::get('/email/verify', function () {
    $frontend = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

    return redirect($frontend.'/login?verification=required');
})->name('verification.notice');

/* NURSELINK_CORS_PREFLIGHT_V261_START */
Route::options('/{any}', function (Request $request) {
    $origin = (string) $request->headers->get('Origin', '');

    if ($origin !== 'https://app.amsertech.com') {
        return response('', 403);
    }

    $requestedHeaders = (string) $request->headers->get(
        'Access-Control-Request-Headers',
        'content-type,x-xsrf-token,x-requested-with,accept,authorization'
    );

    return response('', 204)->withHeaders([
        'Access-Control-Allow-Origin' => $origin,
        'Access-Control-Allow-Credentials' => 'true',
        'Access-Control-Allow-Methods' => 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
        'Access-Control-Allow-Headers' => $requestedHeaders,
        'Access-Control-Max-Age' => '600',
        'Vary' => 'Origin',
    ]);
})->where('any', '.*');
/* NURSELINK_CORS_PREFLIGHT_V261_END */

/* NURSELINK_VERIFICATION_RECOVERY_V560_START */
Route::get('/verify-email/{id}/{hash}', function (string $id, string $hash) {
    $user = User::query()->findOrFail($id);
    abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new Verified($user));
    }

    return redirect(rtrim((string) config('app.frontend_url'), '/').'/dashboard?verified=1');
})->middleware(['signed', 'throttle:6,1'])->name('nurselink.verification.verify');

/* NURSELINK_VERIFICATION_RECOVERY_V560_END */
