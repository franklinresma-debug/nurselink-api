<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles.permissions','application:id,user_id,application_no,status,progress_percent','member:id,user_id,member_no,status');
        $permissions = $user->roles->flatMap(fn($role) => $role->permissions->pluck('code'))->unique()->values();
        return response()->json(['data' => [
            'id'=>$user->id,'name'=>$user->name,'email'=>$user->email,'email_verified'=>(bool)$user->email_verified_at,
            'status'=>$user->status,'mfa_required'=>(bool)$user->mfa_required,'mfa_confirmed'=>(bool)$user->two_factor_confirmed_at,
            'roles'=>$user->roles->pluck('code')->values(),'permissions'=>$permissions,
            'application'=>$user->application,'member'=>$user->member,
        ]]);
    }
}
