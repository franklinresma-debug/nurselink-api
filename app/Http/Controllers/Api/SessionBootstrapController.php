<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class SessionBootstrapController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $origin = (string) $request->headers->get('Origin', '');

        abort_unless(
            hash_equals('https://app.amsertech.com', $origin),
            403,
            'NurseLink frontend origin required.'
        );

        $configured = (string) config('session.cookie', 'laravel_session');

        $names = array_values(array_unique(array_filter([
            $configured,
            'laravel_session',
            'nurselink_session',
            'XSRF-TOKEN',
        ])));

        $response = response()->json([
            'bootstrap' => true,
            'message' => 'NurseLink client session bootstrap completed.',
        ]);

        foreach ($names as $name) {
            $httpOnly = $name !== 'XSRF-TOKEN';

            // Expire an old host-only cookie previously set by api.amsertech.com.
            $response->headers->setCookie(
                Cookie::create($name)
                    ->withValue('')
                    ->withExpires(1)
                    ->withPath('/')
                    ->withSecure(true)
                    ->withHttpOnly($httpOnly)
                    ->withSameSite(Cookie::SAMESITE_LAX)
            );

            // Expire a shared cookie from the current deployment model.
            $response->headers->setCookie(
                Cookie::create($name)
                    ->withValue('')
                    ->withExpires(1)
                    ->withPath('/')
                    ->withDomain('.amsertech.com')
                    ->withSecure(true)
                    ->withHttpOnly($httpOnly)
                    ->withSameSite(Cookie::SAMESITE_LAX)
            );

            // Expire an older explicit API-domain cookie variant.
            $response->headers->setCookie(
                Cookie::create($name)
                    ->withValue('')
                    ->withExpires(1)
                    ->withPath('/')
                    ->withDomain('api.amsertech.com')
                    ->withSecure(true)
                    ->withHttpOnly($httpOnly)
                    ->withSameSite(Cookie::SAMESITE_LAX)
            );
        }

        return $response;
    }
}
