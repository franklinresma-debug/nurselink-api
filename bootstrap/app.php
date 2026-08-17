<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\RequireMfaWhenRequired;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ThrottlePublicRegistration;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->appendToGroup('web', ThrottlePublicRegistration::class);

        /*
        |--------------------------------------------------------------------------
        | Guest Redirect
        |--------------------------------------------------------------------------
        |
        | NurseLink uses the React frontend for authentication screens.
        | Protected Laravel web routes redirect guests to the NurseLink SPA.
        |
        */

        $middleware->redirectGuestsTo(function (Request $request): string {
            $frontend = rtrim(
                (string) env('FRONTEND_URL', 'https://app.amsertech.com'),
                '/'
            );

            /*
             * Preserve the signed verification URL so the user can authenticate
             * in the React application and then return to Fortify verification.
             */
            if ($request->routeIs('verification.verify')) {
                return $frontend.'/login?verification_url='.
                    urlencode($request->fullUrl());
            }

            return $frontend.'/login';
        });

        $middleware->alias([
            'active.user' => EnsureActiveUser::class,
            'mfa.required' => RequireMfaWhenRequired::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // NurseLink API exceptions use Laravel's default JSON negotiation.
    })
    ->create();
