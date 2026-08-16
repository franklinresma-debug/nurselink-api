<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserRoleController extends Controller
{
    public function update(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'code')],
        ]);

        $actor = $request->user();
        $requested = collect($validated['roles'])->unique()->values();

        if ($requested->contains('super_administrator') && !$actor->hasRole('super_administrator')) {
            return response()->json([
                'message' => 'Only a Super Administrator may grant the Super Administrator role.',
                'code' => 'super_admin_assignment_forbidden',
            ], 403);
        }

        if ($actor->is($user) && $actor->hasRole('super_administrator') && !$requested->contains('super_administrator')) {
            return response()->json([
                'message' => 'A Super Administrator cannot remove their own Super Administrator role.',
                'code' => 'self_lockout_prevented',
            ], 422);
        }

        $before = $user->roles()->pluck('code')->all();
        $roles = Role::query()->whereIn('code', $requested)->get();

        $sync = $roles->mapWithKeys(fn ($role) => [$role->id => ['assigned_at' => now()]])->all();
        $user->roles()->sync($sync);

        $mfaRequired = $roles->contains(fn ($role) => in_array($role->code, ['administrator', 'super_administrator'], true));
        $user->forceFill(['mfa_required' => $mfaRequired])->save();

        $after = $roles->pluck('code')->all();
        $audit->write('user.roles_changed', $actor, 'user', $user->id, [
            'before' => $before,
            'after' => $after,
        ], $request);

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'roles' => $after,
                'mfa_required' => $mfaRequired,
            ],
        ]);
    }
}
