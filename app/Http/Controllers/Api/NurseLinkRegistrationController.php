<?php

namespace App\Http\Controllers\Api;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NurseLinkRegistrationController extends Controller
{
    public function store(
        Request $request,
        CreateNewUser $creator
    ): JsonResponse {
        $origin = (string) $request->headers->get('Origin', '');

        abort_unless(
            hash_equals('https://app.amsertech.com', $origin),
            403,
            'NurseLink frontend origin required.'
        );

        $user = $creator->create(
            $request->only([
                'name',
                'email',
                'password',
                'password_confirmation',
                'terms_accepted',
                'privacy_accepted',
            ])
        );

        event(new Registered($user));

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        return response()->json([
            'authenticated' => true,
            'message' => 'NurseLink account created successfully.',
            'verification_required' => ! $user->hasVerifiedEmail(),
        ], 201);
    }
}
