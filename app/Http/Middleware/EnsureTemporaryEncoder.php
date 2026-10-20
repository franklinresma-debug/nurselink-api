<?php

namespace App\Http\Middleware;

use App\Models\TemporaryEncoderAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTemporaryEncoder
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $account = app(\App\Services\AdministratorEncoderContext::class)->resolve($request)
            ?? Auth::guard('encoder')->user();

        abort_unless(
            $account instanceof TemporaryEncoderAccount,
            401,
            'Temporary Encoder authentication is required.'
        );

        abort_unless(
            $account->active,
            403,
            'Temporary Encoder access has been disabled.'
        );

        abort_unless(
            $account->expires_at
            && $account->expires_at->isFuture(),
            403,
            'Temporary Encoder access has expired.'
        );

        return $next($request);
    }
}
