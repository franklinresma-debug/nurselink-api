<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminMembershipLifecycleController extends Controller
{
    private const ADMIN_ELEVATION_TTL_SECONDS = 28800;

    private const TRANSITIONS = [
        'active' => ['suspended', 'inactive'],
        'suspended' => ['active', 'inactive'],
        'inactive' => ['active'],
    ];

    public function summary(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $base = DB::table('nurselink_memberships')
            ->where('status', 'approved');

        $counts = [];

        foreach (['active', 'suspended', 'inactive'] as $standing) {
            $counts[$standing] = (clone $base)
                ->where('standing', $standing)
                ->count();
        }

        return response()->json([
            'data' => [
                'counts' => $counts,
                'total_approved' => array_sum($counts),
                'active_access_required' => true,
                'standing_model' => [
                    'application_status' => 'approved',
                    'professional_standing' => [
                        'active',
                        'suspended',
                        'inactive',
                    ],
                ],
            ],
        ]);
    }

    public function show(Request $request, int $membershipId): JsonResponse
    {
        $access = $this->requireAdmin($request);

        $membership = DB::table('nurselink_memberships')
            ->where('id', $membershipId)
            ->where('status', 'approved')
            ->first();

        abort_unless($membership, 404);

        $standing = $this->normalizedStanding($membership);
        $isSelf = (string) $membership->user_id
            === (string) $request->user()->getKey();

        return response()->json([
            'data' => [
                'membership_id' => (int) $membership->id,
                'user_id' => (string) $membership->user_id,
                'member_number' => $membership->member_number,
                'standing' => $standing,
                'standing_label' => $this->standingLabel($standing),
                'standing_reason' => $membership->standing_reason ?? null,
                'standing_changed_by' =>
                    $membership->standing_changed_by ?? null,
                'standing_changed_at' =>
                    $membership->standing_changed_at ?? null,
                'suspended_at' => $membership->suspended_at ?? null,
                'inactive_at' => $membership->inactive_at ?? null,
                'reactivated_at' => $membership->reactivated_at ?? null,
                'allowed_transitions' =>
                    self::TRANSITIONS[$standing] ?? [],
                'is_self' => $isSelf,
                'self_action_requires_super_admin_confirmation' => $isSelf,
                'can_manage' => $access['is_admin'],
                'is_super_admin' => $access['is_super_admin'],
            ],
        ]);
    }

    public function transition(
        Request $request,
        int $membershipId
    ): JsonResponse {
        $access = $this->requireAdmin($request);

        $data = $request->validate([
            'standing' => [
                'required',
                'string',
                Rule::in(['active', 'suspended', 'inactive']),
            ],
            'reason' => ['required', 'string', 'min:3', 'max:3000'],
            'confirm_self_action' => ['nullable', 'boolean'],
        ]);

        $before = DB::table('nurselink_memberships')
            ->where('id', $membershipId)
            ->where('status', 'approved')
            ->first();

        abort_unless($before, 404);

        $current = $this->normalizedStanding($before);
        $target = $data['standing'];
        $allowed = self::TRANSITIONS[$current] ?? [];

        if (! in_array($target, $allowed, true)) {
            return response()->json([
                'message' => 'That membership-standing transition is not allowed.',
                'current_standing' => $current,
                'allowed_transitions' => $allowed,
            ], 422);
        }

        $isSelf = (string) $before->user_id
            === (string) $request->user()->getKey();

        if ($isSelf) {
            if (! $access['is_super_admin']) {
                return response()->json([
                    'message' => 'Administrators cannot change the standing of their own membership.',
                ], 422);
            }

            if (! ($data['confirm_self_action'] ?? false)) {
                return response()->json([
                    'message' => 'Explicit Super Administrator self-action confirmation is required.',
                    'confirmation_required' => true,
                ], 422);
            }
        }

        $now = now();

        $update = [
            'standing' => $target,
            'standing_reason' => trim($data['reason']),
            'standing_changed_by' =>
                (string) $request->user()->getKey(),
            'standing_changed_at' => $now,
            'updated_at' => $now,
        ];

        if ($target === 'suspended') {
            $update['suspended_at'] = $now;
        }

        if ($target === 'inactive') {
            $update['inactive_at'] = $now;
        }

        if ($target === 'active') {
            $update['reactivated_at'] = $now;
        }

        DB::table('nurselink_memberships')
            ->where('id', $membershipId)
            ->update($update);

        $after = DB::table('nurselink_memberships')
            ->where('id', $membershipId)
            ->first();

        $this->audit(
            $request,
            $isSelf
                ? 'membership.standing_self_action_super_admin'
                : 'membership.standing_changed',
            $membershipId,
            $before,
            $after
        );

        $this->notifyStandingChange($after);

        return response()->json([
            'message' => 'Membership standing changed to '
                . $this->standingLabel($target)
                . '.',
            'data' => [
                'membership_id' => (int) $after->id,
                'member_number' => $after->member_number,
                'standing' => $target,
                'standing_label' => $this->standingLabel($target),
                'standing_reason' => $after->standing_reason,
                'standing_changed_at' => $after->standing_changed_at,
                'self_action_audited' => $isSelf,
            ],
        ]);
    }

    private function normalizedStanding(object $membership): string
    {
        $standing = strtolower(trim((string) (
            $membership->standing ?? ''
        )));

        return in_array(
            $standing,
            ['active', 'suspended', 'inactive'],
            true
        ) ? $standing : 'active';
    }

    private function requireAdmin(Request $request): array
    {
        $user = $request->user();
        abort_unless($user, 401);

        $userId = (string) $user->getKey();

        $elevatedUserId = (string) $request->session()->get(
            'nurselink_admin_elevated_user_id',
            ''
        );

        $elevatedAt = (int) $request->session()->get(
            'nurselink_admin_elevated_at',
            0
        );

        $expiresAt = (int) $request->session()->get(
            'nurselink_admin_expires_at',
            0
        );

        abort_unless(
            $elevatedUserId !== ''
            && hash_equals($elevatedUserId, $userId)
            && $elevatedAt > 0
            && $expiresAt >= time()
            && (time() - $elevatedAt)
                <= self::ADMIN_ELEVATION_TTL_SECONDS,
            403,
            'A separate NurseLink Administrator Portal sign-in is required.'
        );

        $reviewRole = Schema::hasTable('nurselink_reviewer_access')
            ? strtolower((string) (
                DB::table('nurselink_reviewer_access')
                    ->where('user_id', $userId)
                    ->where('active', true)
                    ->value('role')
                ?? ''
            ))
            : '';

        $explicitSuperAdmin = Schema::hasTable(
            'nurselink_super_admin_access'
        )
            && DB::table('nurselink_super_admin_access')
                ->where('user_id', $userId)
                ->where('active', true)
                ->exists();

        $modelRole = strtolower(trim((string) (
            $user->role
            ?? $user->user_role
            ?? $user->user_type
            ?? ''
        )));

        $modelSuperAdmin = (bool) (
            $user->is_super_admin
            ?? false
        ) || in_array(
            $modelRole,
            [
                'super_admin',
                'super-administrator',
                'super_administrator',
                'superadministrator',
            ],
            true
        );

        $isSuperAdmin = $explicitSuperAdmin
            || $reviewRole === 'super_admin'
            || $modelSuperAdmin;

        $isAdmin = $isSuperAdmin
            || $reviewRole === 'admin'
            || (bool) ($user->is_admin ?? false)
            || in_array(
                $modelRole,
                ['admin', 'administrator'],
                true
            );

        abort_unless(
            $isAdmin,
            403,
            'Administrator access is required to manage membership standing.'
        );

        return [
            'is_admin' => $isAdmin,
            'is_super_admin' => $isSuperAdmin,
        ];
    }

    private function audit(
        Request $request,
        string $action,
        int $membershipId,
        object $before,
        object $after
    ): void {
        if (! Schema::hasTable('nurselink_review_audit')) {
            return;
        }

        DB::table('nurselink_review_audit')->insert([
            'reviewer_user_id' =>
                (string) $request->user()->getKey(),
            'action' => $action,
            'target_type' => 'membership',
            'target_id' => (string) $membershipId,
            'before_state' => json_encode(
                $before,
                JSON_UNESCAPED_UNICODE
            ),
            'after_state' => json_encode(
                $after,
                JSON_UNESCAPED_UNICODE
            ),
            'created_at' => now(),
        ]);
    }

    private function notifyStandingChange(object $membership): void
    {
        if (! Schema::hasTable('nurselink_notifications')) {
            return;
        }

        $standing = $this->normalizedStanding($membership);

        $copy = [
            'active' => [
                'success',
                'NurseLink membership reactivated',
                'Your NurseLink membership is in Active standing and member-only services are available.',
            ],
            'suspended' => [
                'warning',
                'NurseLink membership suspended',
                'Your NurseLink membership is currently Suspended. Member-only services are temporarily unavailable. Review your membership status for details.',
            ],
            'inactive' => [
                'warning',
                'NurseLink membership inactive',
                'Your NurseLink membership is currently Inactive. Member-only services are unavailable until the membership is reactivated.',
            ],
        ];

        [$severity, $title, $message] = $copy[$standing];

        DB::table('nurselink_notifications')->insert([
            'user_id' => $membership->user_id,
            'type' => 'membership.standing.' . $standing,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'action_url' => '/application-status',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function standingLabel(string $standing): string
    {
        return match ($standing) {
            'active' => 'Active',
            'suspended' => 'Suspended',
            'inactive' => 'Inactive',
            default => ucfirst($standing),
        };
    }
}
