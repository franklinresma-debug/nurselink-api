<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PolicyConsentController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->status($request)]);
    }

    public function accept(Request $request, AuditLogger $audit): JsonResponse
    {
        $request->validate([
            'terms_accepted' => ['required', 'accepted'],
            'privacy_accepted' => ['required', 'accepted'],
        ]);

        $user = $request->user();
        $user->update([
            'terms_accepted_at' => now(),
            'terms_version' => config('registration.terms_version'),
            'privacy_accepted_at' => now(),
            'privacy_version' => config('registration.privacy_version'),
        ]);

        $audit->write('auth.policy_consent_accepted', $user, 'user', $user->id, [
            'terms_version' => $user->terms_version,
            'privacy_version' => $user->privacy_version,
        ], $request);

        return response()->json(['data' => $this->status($request)]);
    }

    private function status(Request $request): array
    {
        $user = $request->user()->refresh();
        $termsVersion = (string) config('registration.terms_version');
        $privacyVersion = (string) config('registration.privacy_version');
        $current = $user->terms_accepted_at
            && $user->privacy_accepted_at
            && hash_equals($termsVersion, (string) $user->terms_version)
            && hash_equals($privacyVersion, (string) $user->privacy_version);

        return [
            'current' => (bool) $current,
            'terms_version' => $termsVersion,
            'privacy_version' => $privacyVersion,
            'terms_accepted_at' => $user->terms_accepted_at?->toIso8601String(),
            'privacy_accepted_at' => $user->privacy_accepted_at?->toIso8601String(),
        ];
    }
}
