<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TemporaryEncoderAccount;
use App\Models\User;
use App\Services\CoreMembershipActivationService;
use App\Services\MembershipLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use ReflectionClass;

class TemporaryEncoderWorkflowController extends Controller
{
    private const MEMBERSHIP_TRANSITIONS = [
        'submitted' => [
            'under_review',
            'needs_information',
        ],
        'under_review' => [
            'needs_information',
            'ready_for_approval',
            'declined',
        ],
        'needs_information' => [
            'under_review',
            'ready_for_approval',
            'declined',
        ],
        'ready_for_approval' => [
            'under_review',
            'needs_information',
            'approved',
            'declined',
        ],
    ];

    private const STANDING_TRANSITIONS = [
        'active' => [
            'suspended',
            'inactive',
        ],
        'suspended' => [
            'active',
            'inactive',
        ],
        'inactive' => [
            'active',
        ],
    ];

    public function show(
        Request $request,
        string $userId
    ): JsonResponse {
        $account = $this->encoder();

        $this->requireAssignment(
            $account,
            $userId
        );

        $membership = DB::table(
            'nurselink_memberships'
        )
            ->where('user_id', $userId)
            ->first();

        $credentials = $this->credentialsForUser(
            $userId
        );

        // Imported applicants acquire their membership row on valid submission.
        $draftApplication = ! $membership && DB::table('applications')
            ->where('user_id', $userId)
            ->whereIn('status', ['draft', 'in_progress', 'returned'])
            ->exists();
        $status = strtolower(
            (string) ($membership->status ?? ($draftApplication ? 'draft' : ''))
        );

        $standing = strtolower(
            (string) ($membership->standing ?? '')
        );

        if (
            ! in_array(
                $standing,
                array_keys(self::STANDING_TRANSITIONS),
                true
            )
        ) {
            $standing = 'active';
        }

        return response()->json([
            'data' => [
                'membership' => $membership,
                'allowed_membership_transitions' =>
                    self::MEMBERSHIP_TRANSITIONS[$status]
                    ?? [],
                'allowed_standing_transitions' =>
                    $status === 'approved'
                        ? (
                            self::STANDING_TRANSITIONS[$standing]
                            ?? []
                        )
                        : [],
                'can_submit' =>
                    in_array(
                        $status,
                        ['draft', 'needs_information'],
                        true
                    ),
                'credentials' => $credentials,
                'credential_decisions' => [
                    'unverified',
                    'pending',
                    'verified',
                    'document_supported',
                    'unable_to_verify',
                    'discrepancy',
                ],
                'actor' => [
                    'type' => 'temporary_encoder',
                    'encoder_account_id' =>
                        $account->id,
                    'username' =>
                        $account->username,
                    'display_name' =>
                        $account->display_name,
                    'expires_at' =>
                        $account->expires_at
                            ?->toIso8601String(),
                ],
            ],
        ]);
    }

    public function submit(
        Request $request,
        string $userId
    ): JsonResponse {
        $account = $this->encoder();

        $this->requireAssignment(
            $account,
            $userId
        );

        $user = User::query()
            ->findOrFail($userId);

        /*
         * Reuse Smart Registration completeness rules.
         *
         * We deliberately do not impersonate the nurse or execute
         * SmartRegistrationController::submit(), because that would
         * incorrectly attribute the action to the nurse.
         */
        $smart = app(
            SmartRegistrationController::class
        );

        $reflection = new ReflectionClass($smart);

        $ensureProfile =
            $reflection->getMethod('ensureProfile');

        $documentsFor =
            $reflection->getMethod('documentsFor');

        $missingFields =
            $reflection->getMethod('missingFields');

        $ensureDraftMembership =
            $reflection->getMethod(
                'ensureDraftMembership'
            );

        foreach ([
            $ensureProfile,
            $documentsFor,
            $missingFields,
            $ensureDraftMembership,
        ] as $method) {
            $method->setAccessible(true);
        }

        app(TemporaryEncoderController::class)->initializeImportedProfile($userId);

        $profile = $ensureProfile->invoke(
            $smart,
            $user
        );

        $documents = $documentsFor->invoke(
            $smart,
            $userId
        );

        $missing = $missingFields->invoke(
            $smart,
            $profile,
            $documents,
            $user
        );

        if ($missing !== []) {
            return response()->json([
                'message' =>
                    'Please complete before submitting: '.implode(', ', array_column($missing, 'label')).'.',
                'missing' => $missing,
            ], 422);
        }

        $membership =
            $ensureDraftMembership->invoke(
                $smart,
                $user
            );

        if ($membership->status === 'approved') {
            return response()->json([
                'message' =>
                    'This NurseLink membership is already approved.',
            ], 422);
        }

        if ($membership->status === 'declined') {
            return response()->json([
                'message' =>
                    'This application has a completed decision and cannot be submitted.',
            ], 422);
        }

        if (
            ! in_array(
                $membership->status,
                ['draft', 'needs_information'],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'This application cannot be submitted from its current status.',
                'status' =>
                    $membership->status,
            ], 422);
        }

        $before = DB::table(
            'nurselink_memberships'
        )
            ->where('id', $membership->id)
            ->first();

        $isResubmission =
            $before->status === 'needs_information';

        $event =
            $isResubmission
                ? 'resubmitted'
                : 'submitted';

        $reason =
            $isResubmission
                ? 'Application resubmitted by assigned Temporary Encoder.'
                : 'Application submitted by assigned Temporary Encoder.';

        $after = DB::transaction(
            function () use (
                $before,
                $account,
                $event,
                $reason
            ): object {
                $locked = DB::table(
                    'nurselink_memberships'
                )
                    ->where('id', $before->id)
                    ->lockForUpdate()
                    ->first();

                abort_unless($locked, 404);

                abort_if(
                    (string) $locked->status
                        !== (string) $before->status,
                    409,
                    'This membership changed in another session. Refresh and try again.'
                );

                $update = [
                    'status' => 'submitted',
                    'updated_at' => now(),
                ];

                if (
                    Schema::hasColumn(
                        'nurselink_memberships',
                        'submitted_at'
                    )
                ) {
                    $update['submitted_at'] =
                        $locked->submitted_at
                        ?: now();
                }

                if (
                    $event === 'resubmitted'
                    && Schema::hasColumn(
                        'nurselink_memberships',
                        'resubmitted_at'
                    )
                ) {
                    $update['resubmitted_at'] =
                        now();

                    $update['reviewed_by'] = null;
                    $update['reviewed_at'] = null;
                }

                if (
                    Schema::hasColumn(
                        'nurselink_memberships',
                        'last_status_changed_at'
                    )
                ) {
                    $update['last_status_changed_at'] =
                        now();
                }

                if (
                    Schema::hasColumn(
                        'nurselink_memberships',
                        'last_status_changed_by'
                    )
                ) {
                    $update['last_status_changed_by'] =
                        null;
                }

                DB::table(
                    'nurselink_memberships'
                )
                    ->where('id', $locked->id)
                    ->update($update);

                $after = DB::table(
                    'nurselink_memberships'
                )
                    ->where('id', $locked->id)
                    ->first();

                app(
                    MembershipLifecycleService::class
                )->recordTransition(
                    $after,
                    (string) $before->status,
                    'submitted',
                    null,
                    'temporary_encoder',
                    $reason,
                    [
                        'event' => $event,
                        'temporary_encoder_account_id' =>
                            $account->id,
                        'temporary_encoder_username' =>
                            $account->username,
                        'applicant_visible_reason' =>
                            $reason,
                    ]
                );

                return $after;
            }
        );

        $this->audit(
            $request,
            $account,
            $userId,
            'encoder.application_' . $event,
            'membership',
            (string) $after->id,
            $before,
            $after
        );

        $delivery = app(
            MembershipLifecycleService::class
        )->notifyEvent(
            $after,
            $event
        );

        return response()->json([
            'message' =>
                $isResubmission
                    ? 'Application resubmitted for review.'
                    : 'Application submitted for review.',
            'data' => [
                'membership_id' =>
                    (int) $after->id,
                'status' =>
                    $after->status,
                'notification_delivery' =>
                    $delivery,
            ],
        ]);
    }

    public function transitionMembership(
        Request $request,
        string $userId
    ): JsonResponse {
        abort_if(app(\App\Services\ApplicantPortalAccess::class)->enabled() && in_array($request->input('status'), ['ready_for_approval','approved','declined'],true),422,'Applicant access is available without registration approval.');
        $account = $this->encoder();

        $this->requireAssignment(
            $account,
            $userId
        );

        $data = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in([
                    'under_review',
                    'needs_information',
                    'ready_for_approval',
                    'approved',
                    'declined',
                ]),
            ],
            'reviewer_notes' => [
                'nullable',
                'string',
                'max:6000',
            ],
            'decision_reason' => [
                'nullable',
                'string',
                'max:3000',
            ],
        ]);

        $before = DB::table(
            'nurselink_memberships'
        )
            ->where('user_id', $userId)
            ->first();

        abort_unless($before, 404);

        if (
            in_array(
                $before->status,
                ['approved', 'declined'],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'Approved or declined memberships are closed in this review workflow.',
            ], 422);
        }

        $allowed =
            self::MEMBERSHIP_TRANSITIONS[
                (string) $before->status
            ]
            ?? [];

        if (
            ! in_array(
                $data['status'],
                $allowed,
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'That membership status transition is not allowed.',
                'allowed_actions' =>
                    $allowed,
            ], 422);
        }

        if (
            $data['status'] === 'approved'
            && $before->status
                !== 'ready_for_approval'
        ) {
            return response()->json([
                'message' =>
                    'Membership must be Ready for Approval before final approval.',
            ], 422);
        }

        $reason = trim(
            (string) (
                $data['decision_reason']
                ?? ''
            )
        );

        $notes = trim(
            (string) (
                $data['reviewer_notes']
                ?? ''
            )
        );

        if (
            in_array(
                $data['status'],
                ['needs_information', 'declined'],
                true
            )
            && $reason === ''
        ) {
            return response()->json([
                'message' =>
                    'A decision reason is required when requesting information or declining membership.',
            ], 422);
        }

        if ($reason !== '') {
            $notes = trim(
                $notes
                . (
                    $notes !== ''
                        ? "\n\n"
                        : ''
                )
                . 'Decision reason: '
                . $reason
            );
        }

        $update = [
            'status' =>
                $data['status'],
            'reviewer_notes' =>
                $notes !== ''
                    ? $notes
                    : null,

            /*
             * Do not pretend a permanent NurseLink User performed
             * this action. True actor attribution is maintained in
             * temporary_encoder_audit and lifecycle metadata.
             */
            'reviewed_by' => null,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ];

        if (
            Schema::hasColumn(
                'nurselink_memberships',
                'last_admin_action_at'
            )
        ) {
            $update['last_admin_action_at'] =
                now();
        }

        if (
            $data['status'] === 'under_review'
            && Schema::hasColumn(
                'nurselink_memberships',
                'review_started_at'
            )
            && empty($before->review_started_at)
        ) {
            $update['review_started_at'] =
                now();
        }

        if ($data['status'] === 'approved') {
            $memberNumber =
                $before->member_number
                ?: sprintf(
                    'NL-%s-%06d',
                    now()->format('Y'),
                    (int) $before->id
                );

            $verificationCode =
                $before->verification_code
                ?: Str::lower(
                    Str::random(40)
                );

            $update['member_number'] =
                $memberNumber;

            $update['verification_code'] =
                $verificationCode;

            $update['approved_at'] =
                $before->approved_at
                ?: now();

            $update['declined_at'] = null;
            $update['standing'] = 'active';

            $update['standing_reason'] =
                'Initial membership approval';

            $update['standing_changed_by'] =
                null;

            $update['standing_changed_at'] =
                now();

            $update['reactivated_at'] =
                $before->reactivated_at
                ?: now();

        } elseif (
            $data['status'] === 'declined'
        ) {
            $update['declined_at'] =
                now();
        } else {
            $update['declined_at'] =
                null;
        }

        if (
            Schema::hasColumn(
                'nurselink_memberships',
                'last_status_changed_at'
            )
        ) {
            $update['last_status_changed_at'] =
                now();
        }

        if (
            Schema::hasColumn(
                'nurselink_memberships',
                'last_status_changed_by'
            )
        ) {
            $update['last_status_changed_by'] =
                null;
        }

        $historyReason =
            $reason !== ''
                ? $reason
                : (
                    $notes !== ''
                        ? $notes
                        : null
                );

        $after = DB::transaction(
            function () use (
                $before,
                $update,
                $account,
                $historyReason,
                $notes,
                $data
            ): object {
                $locked = DB::table(
                    'nurselink_memberships'
                )
                    ->where('id', $before->id)
                    ->lockForUpdate()
                    ->first();

                abort_unless($locked, 404);

                abort_if(
                    (string) $locked->status
                        !== (string) $before->status
                    || (string) $locked->updated_at
                        !== (string) $before->updated_at,
                    409,
                    'This membership application changed in another session. Refresh before saving another decision.'
                );

                DB::table(
                    'nurselink_memberships'
                )
                    ->where('id', $before->id)
                    ->update($update);

                $after = DB::table(
                    'nurselink_memberships'
                )
                    ->where('id', $before->id)
                    ->first();

                if (
                    $after->status === 'approved'
                    && $after->member_number
                ) {
                    app(
                        CoreMembershipActivationService::class
                    )->sync(
                        (string) $after->user_id,
                        (string) $after->member_number
                    );

                    app(
                        MembershipLifecycleService::class
                    )->ensureOnboarding($after);
                }

                app(
                    MembershipLifecycleService::class
                )->recordTransition(
                    $after,
                    (string) $before->status,
                    (string) $after->status,
                    null,
                    'temporary_encoder',
                    $historyReason,
                    [
                        'temporary_encoder_account_id' =>
                            $account->id,
                        'temporary_encoder_username' =>
                            $account->username,
                        'reviewer_notes_present' =>
                            $notes !== '',
                        'applicant_visible_reason' =>
                            in_array(
                                (string) $after->status,
                                [
                                    'needs_information',
                                    'declined',
                                ],
                                true
                            )
                                ? $historyReason
                                : null,
                    ]
                );

                return $after;
            }
        );

        $this->audit(
            $request,
            $account,
            $userId,
            'encoder.membership_status_changed',
            'membership',
            (string) $after->id,
            $before,
            $after
        );

        $delivery = app(
            MembershipLifecycleService::class
        )->notifyEvent(
            $after,
            (string) $after->status,
            $data['status'] === 'needs_information'
                ? (
                    $reason !== ''
                        ? $reason
                        : $notes
                )
                : null
        );

        return response()->json([
            'message' =>
                'Membership status changed to '
                . str_replace(
                    '_',
                    ' ',
                    (string) $after->status
                )
                . '.',
            'data' => [
                'membership_id' =>
                    (int) $after->id,
                'status' =>
                    $after->status,
                'member_number' =>
                    $after->member_number,
                'reviewer_notes' =>
                    $after->reviewer_notes,
                'approved_at' =>
                    $after->approved_at,
                'declined_at' =>
                    $after->declined_at,
                'notification_delivery' =>
                    $delivery,
            ],
        ]);
    }

    public function transitionStanding(
        Request $request,
        string $userId
    ): JsonResponse {
        $account = $this->encoder();

        $this->requireAssignment(
            $account,
            $userId
        );

        $data = $request->validate([
            'standing' => [
                'required',
                'string',
                Rule::in(
                    array_keys(self::STANDING_TRANSITIONS)
                ),
            ],
            'reason' => [
                'required',
                'string',
                'min:3',
                'max:3000',
            ],
        ]);

        $before = DB::table(
            'nurselink_memberships'
        )
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->first();

        abort_unless($before, 404);

        $current = strtolower(
            trim(
                (string) (
                    $before->standing
                    ?: 'active'
                )
            )
        );

        if (
            ! in_array(
                $current,
                array_keys(self::STANDING_TRANSITIONS),
                true
            )
        ) {
            $current = 'active';
        }

        $allowed =
            self::STANDING_TRANSITIONS[
                $current
            ]
            ?? [];

        if (
            ! in_array(
                $data['standing'],
                $allowed,
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'That membership-standing transition is not allowed.',
                'current_standing' =>
                    $current,
                'allowed_transitions' =>
                    $allowed,
            ], 422);
        }

        $now = now();

        $update = [
            'standing' =>
                $data['standing'],
            'standing_reason' =>
                trim($data['reason']),
            'standing_changed_by' =>
                null,
            'standing_changed_at' =>
                $now,
            'updated_at' =>
                $now,
        ];

        if (
            $data['standing']
                === 'suspended'
        ) {
            $update['suspended_at'] =
                $now;
        }

        if (
            $data['standing']
                === 'inactive'
        ) {
            $update['inactive_at'] =
                $now;
        }

        if (
            $data['standing']
                === 'active'
        ) {
            $update['reactivated_at'] =
                $now;
        }

        DB::table(
            'nurselink_memberships'
        )
            ->where('id', $before->id)
            ->update($update);

        $after = DB::table(
            'nurselink_memberships'
        )
            ->where('id', $before->id)
            ->first();

        $this->audit(
            $request,
            $account,
            $userId,
            'encoder.membership_standing_changed',
            'membership',
            (string) $after->id,
            $before,
            $after
        );

        $this->notifyStanding(
            $after
        );

        return response()->json([
            'message' =>
                'Membership standing changed to '
                . ucfirst(
                    $data['standing']
                )
                . '.',
            'data' => [
                'membership_id' =>
                    (int) $after->id,
                'standing' =>
                    $after->standing,
                'standing_reason' =>
                    $after->standing_reason,
                'standing_changed_at' =>
                    $after->standing_changed_at,
            ],
        ]);
    }

    public function reviewCredential(
        Request $request,
        string $userId,
        string $credentialId
    ): JsonResponse {
        $account = $this->encoder();

        $this->requireAssignment(
            $account,
            $userId
        );

        $data = $request->validate([
            'verification_status' => [
                'required',
                'string',
                Rule::in([
                    'unverified',
                    'pending',
                    'verified',
                    'document_supported',
                    'unable_to_verify',
                    'discrepancy',
                ]),
            ],
            'review_notes' => [
                'nullable',
                'string',
                'max:4000',
            ],
        ]);

        $credential = DB::table(
            'professional_credentials as pc'
        )
            ->join(
                'members as m',
                'm.id',
                '=',
                'pc.member_id'
            )
            ->where(
                'pc.id',
                $credentialId
            )
            ->where(
                'm.user_id',
                $userId
            )
            ->select('pc.*')
            ->first();

        abort_unless(
            $credential,
            404,
            'Credential not found for the assigned nurse.'
        );

        $accepted = in_array(
            $data['verification_status'],
            [
                'verified',
                'document_supported',
            ],
            true
        );

        DB::table(
            'professional_credentials'
        )
            ->where('id', $credentialId)
            ->update([
                'verification_status' =>
                    $data['verification_status'],

                'verification_note' =>
                    $data['review_notes']
                    ?? null,

                /*
                 * Permanent User UUID intentionally remains null.
                 * The real reviewer is the Temporary Encoder in the
                 * dedicated encoder audit record.
                 */
                'verified_by' => null,

                'verified_at' =>
                    $accepted
                        ? now()
                        : null,

                'updated_at' =>
                    now(),
            ]);

        $after = DB::table(
            'professional_credentials'
        )
            ->where(
                'id',
                $credentialId
            )
            ->first();

        $this->audit(
            $request,
            $account,
            $userId,
            'encoder.credential_reviewed',
            'professional_credential',
            (string) $credentialId,
            $credential,
            $after
        );

        $this->notifyCredential(
            $userId,
            $after
        );

        return response()->json([
            'message' =>
                'Credential review saved.',
            'data' => [
                'id' =>
                    (string) $after->id,
                'verification_status' =>
                    $after->verification_status,
                'review_notes' =>
                    $after->verification_note,
                'reviewed_at' =>
                    $after->verified_at,
                'reviewer_type' =>
                    'temporary_encoder',
            ],
        ]);
    }

    private function credentialsForUser(
        string $userId
    ): array {
        if (
            ! Schema::hasTable(
                'professional_credentials'
            )
            || ! Schema::hasTable(
                'members'
            )
        ) {
            return [];
        }

        return DB::table(
            'professional_credentials as pc'
        )
            ->join(
                'members as m',
                'm.id',
                '=',
                'pc.member_id'
            )
            ->where(
                'm.user_id',
                $userId
            )
            ->orderByDesc(
                'pc.updated_at'
            )
            ->get([
                'pc.id',
                'pc.category',
                'pc.credential_type',
                'pc.title',
                'pc.credential_number_last4',
                'pc.issuing_authority',
                'pc.country',
                'pc.issued_on',
                'pc.expires_on',
                'pc.credential_status',
                'pc.verification_status',
                'pc.verification_note',
                'pc.verified_at',
                'pc.primary_document_id',
            ])
            ->map(
                fn ($row): array =>
                    (array) $row
            )
            ->values()
            ->all();
    }

    private function encoder():
        TemporaryEncoderAccount {
        $account =
            Auth::guard('encoder')->user();

        abort_unless(
            $account
                instanceof
                TemporaryEncoderAccount,
            401,
            'Temporary Encoder authentication is required.'
        );

        abort_unless(
            $account->isUsable(),
            403,
            'Temporary Encoder access has expired or been disabled.'
        );

        return $account;
    }

    private function requireAssignment(
        TemporaryEncoderAccount $account,
        string $userId
    ): object {
        $assignment = DB::table(
            'temporary_encoder_assignments'
        )
            ->where(
                'encoder_account_id',
                $account->id
            )
            ->where(
                'nurse_user_id',
                $userId
            )
            ->where(
                'active',
                true
            )
            ->whereNull(
                'revoked_at'
            )
            ->where(
                function ($query): void {
                    $query
                        ->whereNull(
                            'expires_at'
                        )
                        ->orWhere(
                            'expires_at',
                            '>',
                            now()
                        );
                }
            )
            ->first();

        abort_unless(
            $assignment,
            403,
            'This nurse is not assigned to your Temporary Encoder account.'
        );

        return $assignment;
    }

    private function audit(
        Request $request,
        TemporaryEncoderAccount $account,
        string $nurseUserId,
        string $action,
        string $targetType,
        string $targetId,
        mixed $before,
        mixed $after
    ): void {
        DB::table(
            'temporary_encoder_audit'
        )->insert([
            'encoder_account_id' =>
                $account->id,

            'nurse_user_id' =>
                $nurseUserId,

            'action' =>
                $action,

            'target_type' =>
                $targetType,

            'target_id' =>
                $targetId,

            'before_state' =>
                $before
                    ? json_encode(
                        $before,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    )
                    : null,

            'after_state' =>
                $after
                    ? json_encode(
                        $after,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    )
                    : null,

            'ip_address' =>
                (string) $request->ip(),

            'user_agent' =>
                mb_substr(
                    (string) $request
                        ->userAgent(),
                    0,
                    2000
                ),

            'created_at' =>
                now(),
        ]);
    }

    private function notifyStanding(
        object $membership
    ): void {
        if (
            ! Schema::hasTable(
                'nurselink_notifications'
            )
        ) {
            return;
        }

        $copy = [
            'active' => [
                'success',
                'NurseLink membership reactivated',
                'Your NurseLink membership is in Active standing and member-only services are available.',
            ],
            'suspended' => [
                'warning',
                'NurseLink membership suspended',
                'Your NurseLink membership is currently Suspended. Member-only services are temporarily unavailable.',
            ],
            'inactive' => [
                'warning',
                'NurseLink membership inactive',
                'Your NurseLink membership is currently Inactive. Member-only services are unavailable until reactivated.',
            ],
        ];

        $standing = (string) (
            $membership->standing
            ?? 'active'
        );

        if (
            ! isset(
                $copy[$standing]
            )
        ) {
            return;
        }

        [
            $severity,
            $title,
            $message,
        ] = $copy[$standing];

        DB::table(
            'nurselink_notifications'
        )->insert([
            'user_id' =>
                $membership->user_id,
            'type' =>
                'membership.standing.'
                . $standing,
            'severity' =>
                $severity,
            'title' =>
                $title,
            'message' =>
                $message,
            'action_url' =>
                '/application-status',
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);
    }

    private function notifyCredential(
        string $userId,
        object $credential
    ): void {
        if (
            ! Schema::hasTable(
                'nurselink_notifications'
            )
        ) {
            return;
        }

        $status = (string) (
            $credential
                ->verification_status
            ?? 'unverified'
        );

        DB::table(
            'nurselink_notifications'
        )->insert([
            'user_id' =>
                $userId,

            'type' =>
                'credential.'
                . $status,

            'severity' =>
                $status === 'verified'
                    ? 'success'
                    : (
                        $status === 'discrepancy'
                            ? 'warning'
                            : 'info'
                    ),

            'title' =>
                'Credential review updated',

            'message' =>
                'Your credential "'
                . (
                    $credential->title
                    ?? 'Credential'
                )
                . '" is now '
                . str_replace(
                    '_',
                    ' ',
                    $status
                )
                . '.',

            'action_url' =>
                '/credentials',

            'created_at' =>
                now(),

            'updated_at' =>
                now(),
        ]);
    }
}
