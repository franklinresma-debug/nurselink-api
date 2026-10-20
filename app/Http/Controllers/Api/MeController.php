<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $access=app(\App\Services\ApplicantPortalAccess::class);
        $access->ownRecord($request->user());
        $user = $request->user()->load('roles.permissions','application:id,user_id,application_no,status,progress_percent','member:id,user_id,member_no,status');
        $permissions = $user->roles->flatMap(fn($role) => $role->permissions->pluck('code'))->unique()->values();
        if($access->eligible($user))$permissions=$permissions->merge(\App\Services\ApplicantPortalAccess::PERMISSIONS)->unique()->values();
        return response()->json(['data' => [
            'id'=>$user->id,'name'=>$user->name,'email'=>$user->email,'email_verified'=>(bool)$user->email_verified_at,
            'email_verification_required'=>(bool)config('nurselink.applicant_email_verification_required',true) || !$user->hasRole('applicant'),
            'applicant_portal_access'=>$access->eligible($user),'approval_required'=>!$access->enabled(),
            'status'=>$user->status,'mfa_required'=>(bool)$user->mfa_required,'mfa_confirmed'=>(bool)$user->two_factor_confirmed_at,
            'roles'=>$user->roles->pluck('code')->values(),'permissions'=>$permissions,
            'application'=>$user->application,'member'=>$user->member,
        ]]);
    }
}
