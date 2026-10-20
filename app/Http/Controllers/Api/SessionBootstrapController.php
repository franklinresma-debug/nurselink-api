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
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        abort_unless(
            $frontend !== '' && hash_equals($frontend, $origin),
            403,
            'NurseLink frontend origin required.'
        );

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

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

        // Clear host-only cookies and cookies scoped to this deployment's domain.
        // Local HTTP installations need non-Secure deletion cookies.
        $domains = [null];
        $domain = config('session.domain');
        if (is_string($domain) && $domain !== '') {
            $domains[] = $domain;
        }

        foreach ($names as $name) {
            foreach ($domains as $cookieDomain) {
                $response->headers->setCookie(
                    Cookie::create($name)
                        ->withValue('')
                        ->withExpires(1)
                        ->withPath('/')
                        ->withDomain($cookieDomain)
                        ->withSecure((bool) config('session.secure', $request->isSecure()))
                        ->withHttpOnly($name !== 'XSRF-TOKEN')
                        ->withSameSite(Cookie::SAMESITE_LAX)
                );
            }
        }

        return $response;
    }
}
