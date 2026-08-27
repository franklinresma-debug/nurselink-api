<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CoreMembershipActivationService;
use App\Services\MembershipLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MembershipCycleHealthController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $membership = DB::table('nurselink_memberships')->where('id', $id)->first();
        abort_unless($membership, 404, 'Membership not found.');

        $approved = strtolower((string) $membership->status) === 'approved';
        $memberNumber = trim((string) ($membership->member_number ?? ''));

        $registrationArtifacts = [
            'smart_profile' => $this->smartProfileExists($membership->user_id),
            'evidence' => $this->currentEvidenceExists($membership->user_id),
        ];
        $checks = [
            'history' => $this->historyExists((int) $membership->id),
            'member_number' => ! $approved || $memberNumber !== '',
            'core_activation' => ! $approved || $this->coreActivationMatches(
                $membership->user_id,
                $memberNumber
            ),
            'onboarding' => ! $approved || $this->onboardingExists((int) $membership->id),
        ];

        $repairable = $approved
            && $memberNumber !== ''
            && (! $checks['core_activation'] || ! $checks['onboarding']);
        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'data' => [
                'membership_id' => (int) $membership->id,
                'status' => $healthy ? 'healthy' : ($repairable ? 'action_required' : 'check_needed'),
                'checks' => $checks,
                'registration_artifacts' => $registrationArtifacts,
                'warnings' => array_keys(array_filter(
                    $registrationArtifacts,
                    static fn (bool $present): bool => ! $present
                )),
                'repairable' => $repairable,
            ],
        ]);
    }

    public function reconcile(
        Request $request,
        int $id,
        MembershipLifecycleService $lifecycle,
        CoreMembershipActivationService $activation
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);

        $membership = DB::table('nurselink_memberships')->where('id', $id)->first();
        abort_unless($membership, 404, 'Membership not found.');
        abort_unless(
            strtolower((string) $membership->status) === 'approved',
            409,
            'Only approved memberships can be reconciled.'
        );

        $memberNumber = trim((string) ($membership->member_number ?? ''));
        abort_if($memberNumber === '', 409, 'Missing member number requires manual review.');

        $actorId = $request->user() ? (string) $request->user()->getAuthIdentifier() : null;

        DB::transaction(function () use (
            $membership,
            $memberNumber,
            $actorId,
            $data,
            $lifecycle,
            $activation
        ): void {
            $activation->sync((string) $membership->user_id, $memberNumber);
            $this->syncLegacyUserMembership($membership->user_id, $memberNumber);
            $lifecycle->ensureOnboarding($membership);
            $lifecycle->recordTransition(
                $membership,
                'approved',
                'approved',
                $actorId,
                'administrator',
                'Derived-state reconciliation: ' . trim($data['reason']),
                ['reconcile' => true]
            );
        });

        return $this->show($id);
    }

    private function smartProfileExists(mixed $userId): bool
    {
        return Schema::hasTable('nurselink_smart_registration_profiles')
            && DB::table('nurselink_smart_registration_profiles')->where('user_id', $userId)->exists();
    }

    private function currentEvidenceExists(mixed $userId): bool
    {
        if (! Schema::hasTable('nurselink_smart_registration_documents')) return false;

        $query = DB::table('nurselink_smart_registration_documents')->where('user_id', $userId);
        if (Schema::hasColumn('nurselink_smart_registration_documents', 'is_current')) {
            $query->where('is_current', true);
        } elseif (Schema::hasColumn('nurselink_smart_registration_documents', 'active')) {
            $query->where('active', true);
        }

        return $query->exists();
    }

    private function historyExists(int $membershipId): bool
    {
        return Schema::hasTable('nurselink_membership_status_history')
            && DB::table('nurselink_membership_status_history')
                ->where('membership_id', $membershipId)
                ->exists();
    }

    private function onboardingExists(int $membershipId): bool
    {
        return Schema::hasTable('nurselink_membership_onboarding')
            && DB::table('nurselink_membership_onboarding')
                ->where('membership_id', $membershipId)
                ->exists();
    }

    private function coreActivationMatches(mixed $userId, string $memberNumber): bool
    {
        if (! Schema::hasTable('users')) return false;

        $user = DB::table('users')->where('id', $userId)->first();
        if (! $user) return false;

        if (Schema::hasColumn('users', 'member_number')) {
            if (trim((string) ($user->member_number ?? '')) !== $memberNumber) return false;
        }
        if (Schema::hasColumn('users', 'is_member') && ! (bool) ($user->is_member ?? false)) {
            return false;
        }

        return true;
    }

    private function syncLegacyUserMembership(mixed $userId, string $memberNumber): void
    {
        $updates = [];
        if (Schema::hasColumn('users', 'member_number')) $updates['member_number'] = $memberNumber;
        if (Schema::hasColumn('users', 'is_member')) $updates['is_member'] = true;
        if (Schema::hasColumn('users', 'membership_approved_at')) {
            $updates['membership_approved_at'] = now();
        }
        if (Schema::hasColumn('users', 'updated_at')) $updates['updated_at'] = now();

        if ($updates !== []) DB::table('users')->where('id', $userId)->update($updates);
    }
}
