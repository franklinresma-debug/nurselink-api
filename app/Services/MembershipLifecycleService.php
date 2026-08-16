<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MembershipLifecycleService
{
    private const HISTORY = 'nurselink_membership_status_history';
    private const DELIVERY = 'nurselink_membership_notification_deliveries';

    public function recordTransition(
        object $membership,
        ?string $fromStatus,
        string $toStatus,
        ?string $actorUserId,
        string $actorType,
        ?string $reason = null,
        array $metadata = []
    ): void {
        if (! Schema::hasTable(self::HISTORY)) return;

        DB::table(self::HISTORY)->insert([
            'membership_id' => (int) $membership->id,
            'user_id' => $membership->user_id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actorUserId,
            'actor_type' => $actorType,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'metadata' => $metadata !== []
                ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'created_at' => now(),
        ]);
    }

    public function historyForMembership(int $membershipId, int $limit = 100): array
    {
        if (! Schema::hasTable(self::HISTORY)) return [];

        return DB::table(self::HISTORY)
            ->where('membership_id', $membershipId)
            ->orderBy('id')
            ->limit(max(1, min($limit, 250)))
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'from_status' => $row->from_status,
                'to_status' => $row->to_status,
                'actor_type' => $row->actor_type,
                'reason' => $row->reason,
                'metadata' => $row->metadata ? json_decode($row->metadata, true) : null,
                'created_at' => $row->created_at,
            ])
            ->values()
            ->all();
    }

    public function historyForApplicant(int $membershipId, int $limit = 100): array
    {
        return array_map(function (array $item): array {
            $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];

            return [
                'id' => $item['id'],
                'from_status' => $item['from_status'],
                'to_status' => $item['to_status'],
                'actor_type' => $item['actor_type'],
                'reason' => isset($metadata['applicant_visible_reason'])
                    && trim((string) $metadata['applicant_visible_reason']) !== ''
                        ? trim((string) $metadata['applicant_visible_reason'])
                        : null,
                'created_at' => $item['created_at'],
            ];
        }, $this->historyForMembership($membershipId, $limit));
    }

    public function latestApplicantReason(int $membershipId): ?string
    {
        $history = array_reverse($this->historyForApplicant($membershipId));
        foreach ($history as $item) {
            $reason = trim((string) ($item['reason'] ?? ''));
            if ($reason !== '') return $reason;
        }

        return null;
    }

    public function notifyEvent(object $membership, string $eventKey, ?string $context = null): array
    {
        $copy = $this->messageCopy($eventKey, $context);
        if (! $copy) return ['in_app' => false, 'email' => 'not_applicable'];

        [$severity, $title, $message, $url] = $copy;
        $inApp = false;

        if (Schema::hasTable('nurselink_notifications')) {
            DB::table('nurselink_notifications')->insert([
                'user_id' => $membership->user_id,
                'type' => 'membership.' . $eventKey,
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'action_url' => $url,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inApp = true;
        }

        $delivery = $this->queueEmailDelivery($membership, $eventKey, $title, $message);

        return [
            'in_app' => $inApp,
            'email' => $delivery['status'] ?? 'unavailable',
            'delivery_id' => $delivery['id'] ?? null,
        ];
    }

    public function ensureOnboarding(object $membership): bool
    {
        if (
            (string) ($membership->status ?? '') !== 'approved'
            || ! Schema::hasTable('nurselink_membership_onboarding')
        ) {
            return false;
        }

        $exists = DB::table('nurselink_membership_onboarding')
            ->where('membership_id', $membership->id)
            ->exists();

        if ($exists) return false;

        DB::table('nurselink_membership_onboarding')->insert([
            'membership_id' => (int) $membership->id,
            'user_id' => $membership->user_id,
            'status' => 'pending',
            'assigned_admin_user_id' => null,
            'due_at' => now()->addDays(14),
            'welcome_viewed_at' => null,
            'orientation_started_at' => null,
            'orientation_completed_at' => null,
            'last_member_activity_at' => null,
            'last_admin_action_at' => null,
            'completed_at' => null,
            'admin_note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    public function retryEmailDelivery(int $deliveryId): array
    {
        if (! Schema::hasTable(self::DELIVERY)) {
            return ['id' => $deliveryId, 'status' => 'unavailable'];
        }

        $row = DB::table(self::DELIVERY)->where('id', $deliveryId)->first();
        if (! $row) abort(404, 'Notification delivery record not found.');
        if ($row->channel !== 'email') abort(422, 'Only email deliveries can be retried here.');

        return $this->attemptEmail($row);
    }

    public function deliverySummary(int $membershipId): array
    {
        if (! Schema::hasTable(self::DELIVERY)) {
            return [
                'available' => false,
                'total' => 0,
                'delivered' => 0,
                'failed' => 0,
                'pending' => 0,
                'recent' => [],
            ];
        }

        $base = DB::table(self::DELIVERY)->where('membership_id', $membershipId);
        $recent = (clone $base)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'event_key' => $row->event_key,
                'channel' => $row->channel,
                'recipient' => $row->recipient,
                'status' => $row->status,
                'attempts' => (int) $row->attempts,
                'last_attempt_at' => $row->last_attempt_at,
                'delivered_at' => $row->delivered_at,
                'last_error' => $row->last_error,
                'created_at' => $row->created_at,
            ])
            ->values()
            ->all();

        return [
            'available' => true,
            'total' => (clone $base)->count(),
            'delivered' => (clone $base)->where('status', 'delivered')->count(),
            'failed' => (clone $base)->where('status', 'failed')->count(),
            'pending' => (clone $base)->whereIn('status', ['pending', 'retrying'])->count(),
            'recent' => $recent,
        ];
    }

    private function queueEmailDelivery(
        object $membership,
        string $eventKey,
        string $subject,
        string $message
    ): array {
        if (! Schema::hasTable(self::DELIVERY)) {
            return ['status' => 'unavailable'];
        }

        $email = trim((string) DB::table('users')
            ->where('id', $membership->user_id)
            ->value('email'));

        $status = $email !== '' ? 'pending' : 'skipped';
        $id = DB::table(self::DELIVERY)->insertGetId([
            'membership_id' => (int) $membership->id,
            'user_id' => $membership->user_id,
            'event_key' => $eventKey,
            'channel' => 'email',
            'recipient' => $email !== '' ? $email : null,
            'status' => $status,
            'subject' => $subject,
            'message' => $message,
            'attempts' => 0,
            'last_attempt_at' => null,
            'delivered_at' => null,
            'last_error' => $email === '' ? 'No applicant email address is available.' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($email === '') return ['id' => $id, 'status' => 'skipped'];

        $row = DB::table(self::DELIVERY)->where('id', $id)->first();
        return $this->attemptEmail($row);
    }

    private function attemptEmail(object $row): array
    {
        if ($row->status === 'delivered') {
            return ['id' => (int) $row->id, 'status' => 'delivered'];
        }

        $attempts = (int) $row->attempts + 1;
        DB::table(self::DELIVERY)->where('id', $row->id)->update([
            'status' => 'retrying',
            'attempts' => $attempts,
            'last_attempt_at' => now(),
            'last_error' => null,
            'updated_at' => now(),
        ]);

        try {
            Mail::raw((string) $row->message, function ($mail) use ($row): void {
                $mail->to((string) $row->recipient)
                    ->subject((string) $row->subject);
            });

            DB::table(self::DELIVERY)->where('id', $row->id)->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

            return ['id' => (int) $row->id, 'status' => 'delivered'];
        } catch (Throwable $error) {
            $safeError = mb_substr($error->getMessage(), 0, 3000);

            DB::table(self::DELIVERY)->where('id', $row->id)->update([
                'status' => 'failed',
                'last_error' => $safeError,
                'updated_at' => now(),
            ]);

            // Email failure is deliberately non-fatal. Membership transitions
            // and the in-app notification remain committed and administrators
            // can retry the delivery from the membership command workflow.
            return [
                'id' => (int) $row->id,
                'status' => 'failed',
                'error' => $safeError,
            ];
        }
    }

    private function messageCopy(string $eventKey, ?string $context): ?array
    {
        $context = trim((string) $context);
        $needsInfo = 'Your membership review needs additional information. Open Smart Registration to review the request, update your application, and resubmit.';
        if ($context !== '') $needsInfo .= ' Reviewer note: ' . $context;

        return [
            'submitted' => [
                'info',
                'Membership application received',
                'Your NurseLink membership application is in the review queue.',
                '/application-status',
            ],
            'resubmitted' => [
                'info',
                'Membership application resubmitted',
                'Your updated Smart Registration has been returned to the NurseLink review queue.',
                '/application-status',
            ],
            'under_review' => [
                'info',
                'Membership review started',
                'An authorized NurseLink reviewer is reviewing your membership application.',
                '/application-status',
            ],
            'needs_information' => [
                'warning',
                'Additional information is needed',
                $needsInfo,
                '/smart-registration?smartstep=4',
            ],
            'ready_for_approval' => [
                'info',
                'Membership is ready for final approval',
                'Your application has completed reviewer checks and is awaiting final administrator approval.',
                '/application-status',
            ],
            'approved' => [
                'success',
                'NurseLink membership approved',
                'Congratulations. Your NurseLink membership has been approved. Open the Membership Welcome Center to complete onboarding and activate your member tools.',
                '/nurselink-membership-welcome.html',
            ],
            'declined' => [
                'error',
                'Membership review completed',
                'Your NurseLink membership application was not approved. Review the decision notes in Application Status.',
                '/application-status',
            ],
        ][$eventKey] ?? null;
    }
}
