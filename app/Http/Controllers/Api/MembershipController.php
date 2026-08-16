<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MembershipLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MembershipController extends Controller
{
    private const FRONTEND_URL = 'https://app.amsertech.com';
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $row = $this->ensureMembership($user);

        return response()->json([
            'data' => $this->present($row, $user),
        ]);
    }

    public function verify(string $code): JsonResponse
    {
        $membership = DB::table('nurselink_memberships')
            ->where('verification_code', $code)
            ->where('status', 'approved')
            ->first();

        abort_unless($membership, 404);

        $user = DB::table('users')
            ->where('id', $membership->user_id)
            ->first();

        $standing = $this->normalizedStanding($membership);

        return response()->json([
            'data' => [
                'valid' => true,
                'member_number' => $membership->member_number,
                'status' => 'approved',
                'standing' => $standing,
                'standing_label' => ucfirst($standing),
                'active_access' => $standing === 'active',
                'member_name' => $this->displayName($user),
                'approved_at' => $membership->approved_at,
            ],
        ]);
    }

    private function ensureMembership(object $user): object
    {
        $userId = $user->getKey();

        $existing = DB::table('nurselink_memberships')
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            if ($existing->status !== 'approved') {
                $coreMemberNumber = $this->coreMemberNumber($user);

                if ($coreMemberNumber) {
                    $reconcile = [
                        'status' => 'approved',
                        'member_number' => $coreMemberNumber,
                        'verification_code' => $existing->verification_code ?: Str::lower(Str::random(40)),
                        'approved_at' => $existing->approved_at ?: now(),
                        'standing' => 'active',
                        'standing_changed_at' => $existing->standing_changed_at ?: now(),
                        'updated_at' => now(),
                    ];
                    if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_at')) $reconcile['last_status_changed_at'] = now();
                    if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_by')) $reconcile['last_status_changed_by'] = null;

                    DB::table('nurselink_memberships')
                        ->where('id', $existing->id)
                        ->update($reconcile);

                    $beforeStatus = (string) $existing->status;
                    $existing = DB::table('nurselink_memberships')
                        ->where('id', $existing->id)
                        ->first();

                    $lifecycle = app(MembershipLifecycleService::class);
                    $lifecycle->recordTransition(
                        $existing,
                        $beforeStatus,
                        'approved',
                        null,
                        'system',
                        'Existing core member number reconciled into the NurseLink membership lifecycle.',
                        [
                            'core_member_reconciliation' => true,
                            'applicant_visible_reason' => 'Existing NurseLink membership recognized and activated.',
                        ]
                    );
                    $lifecycle->ensureOnboarding($existing);
                }
            }

            return $existing;
        }

        $coreMemberNumber = $this->coreMemberNumber($user);
        $approved = (bool) $coreMemberNumber;

        $insert = [
            'user_id' => $userId,
            'status' => $approved ? 'approved' : 'draft',
            'member_number' => $coreMemberNumber,
            'verification_code' => $approved ? Str::lower(Str::random(40)) : null,
            'approved_at' => $approved ? now() : null,
            'standing' => $approved ? 'active' : null,
            'standing_changed_at' => $approved ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_at')) $insert['last_status_changed_at'] = now();
        if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_by')) $insert['last_status_changed_by'] = (string) $userId;

        $id = DB::table('nurselink_memberships')->insertGetId($insert);
        $created = DB::table('nurselink_memberships')->where('id', $id)->first();

        app(MembershipLifecycleService::class)->recordTransition(
            $created,
            null,
            (string) $created->status,
            $approved ? null : (string) $userId,
            $approved ? 'system' : 'applicant',
            $approved ? 'Existing core member synchronized into the membership lifecycle.' : 'Membership application draft created.',
            ['applicant_visible_reason' => $approved
                ? 'Existing NurseLink membership recognized.'
                : 'Membership application draft created.']
        );

        return $created;
    }

    private function coreMemberNumber(object $user): ?string
    {
        if (Schema::hasColumn('users', 'member_number')) {
            $value = trim((string) ($user->member_number ?? ''));

            if ($value !== '') return $value;
        }

        return null;
    }

    private function present(object $row, object $user): array
    {
        $lifecycle = app(MembershipLifecycleService::class);
        $applicantReason = in_array((string) $row->status, ['needs_information', 'declined'], true)
            ? $lifecycle->latestApplicantReason((int) $row->id)
            : null;
        if (! $applicantReason && (string) $row->status === 'needs_information') {
            // Compatibility fallback for pre-v5.5.8 information requests.
            $applicantReason = $row->reviewer_notes ?? null;
        }

        return [
            'id' => (int) $row->id,
            'status' => $row->status,
            'member_number' => $row->member_number,
            'verification_code' => $row->verification_code,
            'verification_url' => $row->verification_code
                ? self::FRONTEND_URL
                    . '/nurselink-member-verify.html?code='
                    . rawurlencode($row->verification_code)
                : null,
            'reviewer_notes' => $applicantReason,
            'reviewed_at' => $row->reviewed_at,
            'submitted_at' => $row->submitted_at ?? null,
            'resubmitted_at' => $row->resubmitted_at ?? null,
            'approved_at' => $row->approved_at,
            'declined_at' => $row->declined_at,
            'standing' => $row->status === 'approved'
                ? $this->normalizedStanding($row)
                : null,
            'standing_label' => $row->status === 'approved'
                ? ucfirst($this->normalizedStanding($row))
                : null,
            'active_access' => $row->status === 'approved'
                && $this->normalizedStanding($row) === 'active',
            'standing_reason' => $row->standing_reason ?? null,
            'standing_changed_at' =>
                $row->standing_changed_at ?? null,
            'member_name' => $this->displayName($user),
            'last_status_changed_at' => $row->last_status_changed_at ?? null,
            'status_history' => app(MembershipLifecycleService::class)
                ->historyForApplicant((int) $row->id),
        ];
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

    private function displayName(?object $user): string
    {
        if (! $user) return '';

        $name = trim((string) ($user->name ?? ''));

        if ($name !== '') return $name;

        return trim(
            (string) ($user->first_name ?? '')
            . ' '
            . (string) ($user->last_name ?? '')
        );
    }
}
