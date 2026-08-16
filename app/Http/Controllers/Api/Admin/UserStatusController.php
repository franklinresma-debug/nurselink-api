<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserStatusController extends Controller
{
    public function update(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'deactivated'])],
        ]);

        if ($request->user()->is($user) && $validated['status'] !== 'active') {
            return response()->json(['message' => 'You cannot deactivate your own account.', 'code' => 'self_lockout_prevented'], 422);
        }

        $before = $user->status;
        $user->forceFill(['status' => $validated['status']])->save();

        $audit->write('user.status_changed', $request->user(), 'user', $user->id, [
            'before' => $before,
            'after' => $validated['status'],
        ], $request);

        return response()->json(['data' => ['user_id' => $user->id, 'status' => $user->status]]);
    }
}
