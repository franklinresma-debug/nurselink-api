<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'product' => 'NurseLink by KAPIT-BISIG',
    'build' => 'NL-011.2-cpanel',
    'api' => '/api/health',
]));

// Fortify needs this named route even when views are disabled so reset notifications can resolve.
Route::get('/reset-password/{token}', function (string $token) {
    $frontend = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
    return redirect($frontend.'/reset-password?token='.$token.'&email='.urlencode((string) request('email')));
})->name('password.reset');

/* NURSELINK_CORS_PREFLIGHT_V261_START */
\Illuminate\Support\Facades\Route::options('/{any}', function (\Illuminate\Http\Request $request) {
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
    $user = \App\Models\User::query()->findOrFail($id);
    abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new \Illuminate\Auth\Events\Verified($user));
    }
    return redirect(rtrim((string) config('app.frontend_url'), '/').'/dashboard?verified=1');
})->middleware(['signed', 'throttle:6,1'])->name('nurselink.verification.verify');

/* NURSELINK_VERIFICATION_RECOVERY_V560_END */
