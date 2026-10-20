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
            $origin !== '' && hash_equals(rtrim((string) config('app.frontend_url'), '/'), $origin),
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

        $verificationRequired = (bool)config('nurselink.applicant_email_verification_required',true) || !$user->hasRole('applicant');
        $delivery = $verificationRequired ? 'unavailable' : 'disabled_for_applicant_testing';
        if ($verificationRequired && ! in_array(config('mail.default'), ['log', 'array'], true)) {
            try {
                event(new Registered($user));
                $delivery = 'submitted';
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Registration verification email dispatch failed.', ['user_id' => $user->getKey(), 'exception_type' => get_class($e)]);
                $delivery = 'failed';
            }
        }

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        return response()->json([
            'authenticated' => true,
            'message' => 'NurseLink account created successfully.',
            'verification_required' => false,
            'email_verification_required' => $verificationRequired,
            'email_verification_pending' => $verificationRequired && ! $user->hasVerifiedEmail(),
            'verification_delivery' => $delivery,
        ], 201);
    }
}
