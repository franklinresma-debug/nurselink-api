<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MembershipLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminMembershipCommandController extends Controller
{
    private const ELEVATION_TTL_SECONDS = 28800;

    private const REVIEWER_TRANSITIONS = [
        'submitted' => [
            'under_review',
            'needs_information',
        ],
        'under_review' => [
            'needs_information',
            'ready_for_approval',
        ],
        'needs_information' => [
            'under_review',
            'ready_for_approval',
        ],
        'ready_for_approval' => [
            'under_review',
            'needs_information',
        ],
    ];

    private const ADMIN_TRANSITIONS = [
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

    public function summary(Request $request): JsonResponse
    {
        $access = $this->requireElevatedSession($request);

        $counts = collect([
            'submitted',
            'under_review',
            'needs_information',
            'ready_for_approval',
            'approved',
            'declined',
        ])->mapWithKeys(function (string $status): array {
            return [
                $status => DB::table('nurselink_memberships')
                    ->where('status', $status)
                    ->count(),
            ];
        });

        return response()->json([
            'data' => [
                'access' => $access,
                'counts' => $counts,
                'pending_total' => collect([
                    'submitted',
                    'under_review',
                    'needs_information',
                    'ready_for_approval',
                ])->sum(fn (string $status) => (int) ($counts[$status] ?? 0)),
                'approval_requires_ready_for_approval' => true,
                'self_action_requires_super_admin_confirmation' => true,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $access = $this->requireElevatedSession($request);

        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                'submitted',
                'under_review',
                'needs_information',
                'ready_for_approval',
                'approved',
                'declined',
            ])],
            'search' => ['nullable', 'string', 'max:190'],
        ]);

        $query = DB::table('nurselink_memberships as m');

        if (! empty($data['status'])) {
            $query->where('m.status', $data['status']);
        }

        $memberships = $query
            ->orderByRaw("CASE m.status
                WHEN 'submitted' THEN 1
                WHEN 'under_review' THEN 2
                WHEN 'needs_information' THEN 3
                WHEN 'ready_for_approval' THEN 4
                WHEN 'approved' THEN 5
                WHEN 'declined' THEN 6
                ELSE 7 END")
            ->orderByDesc('m.updated_at')
            ->limit(500)
            ->get();

        $users = $this->userMap($memberships->pluck('user_id')->all());
        $reviewers = $this->userMap(
            $memberships
                ->pluck('reviewed_by')
                ->filter()
                ->all()
        );

        $search = strtolower(trim((string) ($data['search'] ?? '')));

        $rows = $memberships->map(function ($row) use (
            $users,
            $reviewers,
            $request,
            $access
        ): array {
            $user = $users[(string) $row->user_id] ?? null;
            $reviewer = $row->reviewed_by
                ? ($reviewers[(string) $row->reviewed_by] ?? null)
                : null;

            return [
                'id' => (int) $row->id,
                'user_id' => (string) $row->user_id,
                'name' => $user['name'] ?? (string) $row->user_id,
                'email' => $user['email'] ?? '',
                'status' => $row->status,
                'status_label' => $this->statusLabel((string) $row->status),
                'member_number' => $row->member_number,
                'reviewer_notes' => $row->reviewer_notes,
                'reviewed_by' => $row->reviewed_by,
                'reviewer_name' => $reviewer['name'] ?? null,
                'reviewer_email' => $reviewer['email'] ?? null,
                'reviewed_at' => $row->reviewed_at,
                'approved_at' => $row->approved_at,
                'declined_at' => $row->declined_at,
                'updated_at' => $row->updated_at,
                'is_self' => (string) $row->user_id
                    === (string) $request->user()->getKey(),
                'allowed_actions' => $this->allowedTransitions(
                    (string) $row->status,
                    $access
                ),
            ];
        });

        if ($search !== '') {
            $rows = $rows->filter(function (array $row) use ($search): bool {
                $haystack = strtolower(
                    ($row['name'] ?? '')
                    . ' '
                    . ($row['email'] ?? '')
                    . ' '
                    . ($row['member_number'] ?? '')
                );

                return str_contains($haystack, $search);
            });
        }

        return response()->json([
            'data' => $rows->values(),
            'permissions' => [
                'role' => $access['role'],
                'is_reviewer' => $access['is_reviewer'],
                'is_admin' => $access['is_admin'],
                'is_super_admin' => $access['is_super_admin'],
                'can_final_decide' => $access['is_admin'],
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $access = $this->requireElevatedSession($request);

        $membership = DB::table('nurselink_memberships')
            ->where('id', $id)
            ->first();

        abort_unless($membership, 404);

        $user = $this->userMap([(string) $membership->user_id])[
            (string) $membership->user_id
        ] ?? null;

        $credentialSummary = Schema::hasTable('nurselink_credentials_registry')
            ? [
                'total' => DB::table('nurselink_credentials_registry')
                    ->where('user_id', $membership->user_id)
                    ->count(),
                'verified' => DB::table('nurselink_credentials_registry')
                    ->where('user_id', $membership->user_id)
                    ->where('verification_status', 'verified')
                    ->count(),
                'pending' => DB::table('nurselink_credentials_registry')
                    ->where('user_id', $membership->user_id)
                    ->whereIn('verification_status', ['unverified', 'pending'])
                    ->count(),
                'expired' => DB::table('nurselink_credentials_registry')
                    ->where('user_id', $membership->user_id)
                    ->where('verification_status', 'expired')
                    ->count(),
            ]
            : null;

        $employmentCount = Schema::hasTable('nurselink_employment_histories')
            ? DB::table('nurselink_employment_histories')
                ->where('user_id', $membership->user_id)
                ->count()
            : null;

        $smartProfile = Schema::hasTable('nurselink_smart_registration_profiles')
            ? DB::table('nurselink_smart_registration_profiles')
                ->where('user_id', $membership->user_id)
                ->first()
            : null;

        $smartDocuments = Schema::hasTable('nurselink_smart_registration_documents')
            ? DB::table('nurselink_smart_registration_documents')
                ->where('user_id', $membership->user_id)
                ->orderByDesc('id')
                ->get()
            : collect();

        $smartApplication = ($smartProfile || $smartDocuments->isNotEmpty())
            ? [
                'profile' => $smartProfile ? [
                    'first_name' => $smartProfile->first_name,
                    'middle_name' => $smartProfile->middle_name,
                    'last_name' => $smartProfile->last_name,
                    'birth_date' => $smartProfile->birth_date,
                    'sex' => $smartProfile->sex,
                    'nationality' => $smartProfile->nationality,
                    'phone' => $smartProfile->phone,
                    'address_line1' => $smartProfile->address_line1,
                    'city' => $smartProfile->city,
                    'province' => $smartProfile->province,
                    'country' => $smartProfile->country,
                    'professional_title' => $smartProfile->professional_title,
                    'years_experience' => $smartProfile->years_experience !== null
                        ? (int) $smartProfile->years_experience
                        : null,
                    'current_position' => $smartProfile->current_position,
                    'current_employer' => $smartProfile->current_employer,
                    'specialty' => $smartProfile->specialty,
                    'primary_license_number' => $smartProfile->primary_license_number,
                    'primary_license_country' => $smartProfile->primary_license_country,
                    'primary_license_expiry' => $smartProfile->primary_license_expiry,
                    'highest_nursing_education' => $smartProfile->highest_nursing_education,
                    'graduation_year' => $smartProfile->graduation_year !== null
                        ? (int) $smartProfile->graduation_year
                        : null,
                    'confirmed_sources' => $smartProfile->confirmed_sources
                        ? json_decode($smartProfile->confirmed_sources, true)
                        : null,
                    'last_extracted_at' => $smartProfile->last_extracted_at,
                ] : null,
                'documents' => $smartDocuments->map(function ($document) use ($membership): array {
                    return [
                        'id' => (int) $document->id,
                        'name' => $document->original_name,
                        'mime_type' => $document->mime_type,
                        'file_size' => (int) $document->file_size,
                        'document_type' => $document->document_type,
                        'extraction_status' => $document->extraction_status,
                        'extracted_fields' => $document->extracted_fields
                            ? json_decode($document->extracted_fields, true)
                            : null,
                        'extraction_message' => $document->extraction_message,
                        'version' => property_exists($document, 'version') ? (int) $document->version : 1,
                        'is_current' => property_exists($document, 'is_current') ? (bool) $document->is_current : true,
                        'replaces_document_id' => property_exists($document, 'replaces_document_id') && $document->replaces_document_id !== null
                            ? (int) $document->replaces_document_id
                            : null,
                        'replaced_at' => property_exists($document, 'replaced_at') ? $document->replaced_at : null,
                        'created_at' => $document->created_at,
                        'download_url' => '/api/nurselink/admin/membership-command/'
                            . (int) $membership->id
                            . '/smart-document/'
                            . (int) $document->id,
                    ];
                })->values(),
            ]
            : null;

        return response()->json([
            'data' => [
                'membership' => [
                    'id' => (int) $membership->id,
                    'user_id' => (string) $membership->user_id,
                    'status' => $membership->status,
                    'status_label' => $this->statusLabel(
                        (string) $membership->status
                    ),
                    'member_number' => $membership->member_number,
                    'reviewer_notes' => $membership->reviewer_notes,
                    'reviewed_by' => $membership->reviewed_by,
                    'reviewed_at' => $membership->reviewed_at,
                    'approved_at' => $membership->approved_at,
                    'declined_at' => $membership->declined_at,
                    'updated_at' => $membership->updated_at,
                ],
                'applicant' => $user,
                'profile' => [
                    'profile_photo_uploaded' => $user['profile_photo_uploaded']
                        ?? false,
                    'employment_records' => $employmentCount,
                    'credentials' => $credentialSummary,
                ],
                'smart_application' => $smartApplication,
                'lifecycle' => [
                    'status_history' => app(MembershipLifecycleService::class)
                        ->historyForMembership((int) $membership->id),
                    'notification_delivery' => app(MembershipLifecycleService::class)
                        ->deliverySummary((int) $membership->id),
                ],
                'review' => [
                    'allowed_actions' => $this->allowedTransitions(
                        (string) $membership->status,
                        $access
                    ),
                    'is_self' => (string) $membership->user_id
                        === (string) $request->user()->getKey(),
                    'requires_self_confirmation' => (string) $membership->user_id
                        === (string) $request->user()->getKey(),
                    'final_decision_requires_admin' => true,
                    'approval_requires_ready_for_approval' => true,
                ],
            ],
        ]);
    }

    public function smartDocument(Request $request, int $id, int $documentId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->requireElevatedSession($request);

        $membership = DB::table('nurselink_memberships')
            ->where('id', $id)
            ->first();

        abort_unless($membership, 404);
        abort_unless(Schema::hasTable('nurselink_smart_registration_documents'), 404);

        $document = DB::table('nurselink_smart_registration_documents')
            ->where('id', $documentId)
            ->where('user_id', $membership->user_id)
            ->first();

        abort_unless($document, 404);
        abort_unless(
            is_string($document->storage_path)
            && $document->storage_path !== ''
            && Storage::disk('local')->exists($document->storage_path),
            404
        );

        $this->audit(
            $request,
            'membership.smart_document_accessed',
            'membership',
            (string) $membership->id,
            null,
            [
                'document_id' => (int) $document->id,
                'document_type' => $document->document_type,
                'original_name' => $document->original_name,
            ]
        );

        $filename = trim(str_replace(["\r", "\n"], '', (string) $document->original_name));
        if ($filename === '') $filename = 'nurselink-application-document';

        return Storage::disk('local')->download(
            $document->storage_path,
            $filename,
            [
                'Content-Type' => $document->mime_type ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ]
        );
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $this->requireElevatedSession($request);

        $membership = DB::table('nurselink_memberships')
            ->where('id', $id)
            ->first();

        abort_unless($membership, 404);

        if (! Schema::hasTable('nurselink_review_audit')) {
            return response()->json(['data' => []]);
        }

        $rows = DB::table('nurselink_review_audit')
            ->where('target_type', 'membership')
            ->where('target_id', (string) $id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $reviewers = $this->userMap(
            $rows->pluck('reviewer_user_id')->all()
        );

        return response()->json([
            'data' => $rows->map(function ($row) use ($reviewers): array {
                $reviewer = $reviewers[
                    (string) $row->reviewer_user_id
                ] ?? null;

                return [
                    'id' => (int) $row->id,
                    'action' => $row->action,
                    'reviewer_user_id' => (string) $row->reviewer_user_id,
                    'reviewer_name' => $reviewer['name'] ?? null,
                    'reviewer_email' => $reviewer['email'] ?? null,
                    'before' => $row->before_state
                        ? json_decode($row->before_state, true)
                        : null,
                    'after' => $row->after_state
                        ? json_decode($row->after_state, true)
                        : null,
                    'created_at' => $row->created_at,
                ];
            })->values(),
        ]);
    }

    public function retryNotification(Request $request, int $id, int $deliveryId): JsonResponse
    {
        $this->requireElevatedSession($request);

        $membership = DB::table('nurselink_memberships')->where('id', $id)->first();
        abort_unless($membership, 404);
        abort_unless(Schema::hasTable('nurselink_membership_notification_deliveries'), 404);

        $delivery = DB::table('nurselink_membership_notification_deliveries')
            ->where('id', $deliveryId)
            ->where('membership_id', $id)
            ->first();
        abort_unless($delivery, 404);

        $result = app(MembershipLifecycleService::class)
            ->retryEmailDelivery($deliveryId);

        $this->audit(
            $request,
            'membership.notification_retried',
            'membership',
            (string) $id,
            $delivery,
            $result
        );

        return response()->json([
            'message' => ($result['status'] ?? '') === 'delivered'
                ? 'Membership notification email delivered.'
                : 'Membership notification retry completed. Review the delivery status for details.',
            'data' => $result,
        ]);
    }

    public function transition(Request $request, int $id): JsonResponse
    {
        $access = $this->requireElevatedSession($request);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in([
                'under_review',
                'needs_information',
                'ready_for_approval',
                'approved',
                'declined',
            ])],
            'reviewer_notes' => ['nullable', 'string', 'max:6000'],
            'decision_reason' => ['nullable', 'string', 'max:3000'],
            'confirm_self_action' => ['nullable', 'boolean'],
        ]);

        $before = DB::table('nurselink_memberships')
            ->where('id', $id)
            ->first();

        abort_unless($before, 404);

        if (in_array($before->status, ['approved', 'declined'], true)) {
            return response()->json([
                'message' => 'Approved or declined memberships are closed in this command-center workflow.',
            ], 422);
        }

        $allowed = $this->allowedTransitions(
            (string) $before->status,
            $access
        );

        if (! in_array($data['status'], $allowed, true)) {
            return response()->json([
                'message' => 'That membership status transition is not allowed for your role or the current application state.',
                'allowed_actions' => $allowed,
            ], 422);
        }

        $isFinal = in_array(
            $data['status'],
            ['approved', 'declined'],
            true
        );

        if ($isFinal && ! $access['is_admin']) {
            abort(
                403,
                'Administrator access is required for final membership decisions.'
            );
        }

        if (
            $data['status'] === 'approved'
            && $before->status !== 'ready_for_approval'
        ) {
            return response()->json([
                'message' => 'Membership must be Ready for Approval before final approval.',
            ], 422);
        }

        if (
            in_array(
                $data['status'],
                ['needs_information', 'declined'],
                true
            )
            && trim((string) ($data['decision_reason'] ?? '')) === ''
        ) {
            return response()->json([
                'message' => 'A decision reason is required when requesting information or declining membership.',
            ], 422);
        }

        $isSelf = (string) $before->user_id
            === (string) $request->user()->getKey();

        if ($isSelf) {
            if (! $access['is_super_admin']) {
                return response()->json([
                    'message' => 'Reviewers and Administrators cannot take membership actions on their own application.',
                ], 422);
            }

            if (! ($data['confirm_self_action'] ?? false)) {
                return response()->json([
                    'message' => 'Explicit Super Administrator self-action confirmation is required.',
                    'confirmation_required' => true,
                ], 422);
            }
        }

        $notes = trim((string) ($data['reviewer_notes'] ?? ''));
        $reason = trim((string) ($data['decision_reason'] ?? ''));

        if ($reason !== '') {
            $notes = trim(
                $notes
                . ($notes !== '' ? "\n\n" : '')
                . 'Decision reason: '
                . $reason
            );
        }

        $update = [
            'status' => $data['status'],
            'reviewer_notes' => $notes !== '' ? $notes : null,
            'reviewed_by' => (string) $request->user()->getKey(),
            'reviewed_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn(
            'nurselink_memberships',
            'last_admin_action_at'
        )) {
            $update['last_admin_action_at'] = now();
        }

        if (
            $data['status'] === 'under_review'
            && Schema::hasColumn(
                'nurselink_memberships',
                'review_started_at'
            )
            && empty($before->review_started_at)
        ) {
            $update['review_started_at'] = now();
        }

        if (
            Schema::hasColumn(
                'nurselink_memberships',
                'assigned_reviewer_user_id'
            )
            && empty($before->assigned_reviewer_user_id)
        ) {
            $update['assigned_reviewer_user_id'] =
                (string) $request->user()->getKey();
        }

        if ($data['status'] === 'approved') {
            $memberNumber = $before->member_number
                ?: $this->generateMemberNumber($before);

            $verificationCode = $before->verification_code
                ?: Str::lower(Str::random(40));

            $update['member_number'] = $memberNumber;
            $update['verification_code'] = $verificationCode;
            $update['approved_at'] = $before->approved_at ?: now();
            $update['declined_at'] = null;
            $update['standing'] = 'active';
            $update['standing_reason'] = 'Initial membership approval';
            $update['standing_changed_by'] =
                (string) $request->user()->getKey();
            $update['standing_changed_at'] = now();
            $update['reactivated_at'] = $before->reactivated_at ?: now();

        } elseif ($data['status'] === 'declined') {
            $update['declined_at'] = now();
        } else {
            $update['declined_at'] = null;
        }

        if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_at')) {
            $update['last_status_changed_at'] = now();
        }
        if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_by')) {
            $update['last_status_changed_by'] = (string) $request->user()->getKey();
        }

        $actorType = $access['is_super_admin']
            ? 'super_administrator'
            : ($access['is_admin'] ? 'administrator' : 'reviewer');
        $actorUserId = (string) $request->user()->getKey();
        $historyReason = $reason !== '' ? $reason : ($notes !== '' ? $notes : null);

        $after = DB::transaction(function () use ($id, $before, $update, $actorType, $actorUserId, $historyReason, $isSelf, $notes): object {
            $locked = DB::table('nurselink_memberships')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($locked, 404);
            abort_if(
                (string) $locked->status !== (string) $before->status
                    || (string) $locked->updated_at !== (string) $before->updated_at,
                409,
                'This membership application changed in another administrator session. Refresh the record before applying another decision.'
            );

            DB::table('nurselink_memberships')
                ->where('id', $id)
                ->update($update);

            $after = DB::table('nurselink_memberships')
                ->where('id', $id)
                ->first();

            if ($after->status === 'approved' && $after->member_number) {
                // Approval, core-member activation and onboarding are one DB transaction.
                // A failure in any of these steps rolls back the membership decision.
                $this->syncCoreMembership(
                    (string) $after->user_id,
                    (string) $after->member_number
                );
                app(MembershipLifecycleService::class)->ensureOnboarding($after);
            }

            app(MembershipLifecycleService::class)->recordTransition(
                $after,
                (string) $before->status,
                (string) $after->status,
                $actorUserId,
                $actorType,
                $historyReason,
                [
                    'self_action' => $isSelf,
                    'reviewer_notes_present' => $notes !== '',
                    'applicant_visible_reason' => in_array((string) $after->status, ['needs_information', 'declined'], true)
                        ? ($historyReason ?: null)
                        : null,
                ]
            );

            return $after;
        });

        $action = $isSelf
            ? 'membership.self_action_super_admin'
            : 'membership.command_center_status_changed';

        $this->audit(
            $request,
            $action,
            'membership',
            (string) $id,
            $before,
            $after
        );

        $lifecycle = app(MembershipLifecycleService::class);
        $delivery = $lifecycle->notifyEvent(
            $after,
            (string) $after->status,
            $data['status'] === 'needs_information' ? ($reason !== '' ? $reason : $notes) : null
        );

        return response()->json([
            'message' => $this->statusLabel($after->status)
                . ' saved for membership application.',
            'data' => [
                'id' => (int) $after->id,
                'status' => $after->status,
                'status_label' => $this->statusLabel($after->status),
                'member_number' => $after->member_number,
                'reviewer_notes' => $after->reviewer_notes,
                'reviewed_at' => $after->reviewed_at,
                'approved_at' => $after->approved_at,
                'declined_at' => $after->declined_at,
                'self_action_audited' => $isSelf,
                'notification_delivery' => $delivery,
            ],
        ]);
    }

    private function allowedTransitions(
        string $status,
        array $access
    ): array {
        if (in_array($status, ['approved', 'declined'], true)) {
            return [];
        }

        if ($access['is_admin']) {
            return self::ADMIN_TRANSITIONS[$status] ?? [];
        }

        return self::REVIEWER_TRANSITIONS[$status] ?? [];
    }

    private function requireElevatedSession(Request $request): array
    {
        $user = $request->user();
        abort_unless($user, 401);

        $sessionUserId = (string) $request->session()->get(
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
            $sessionUserId !== ''
            && hash_equals(
                $sessionUserId,
                (string) $user->getKey()
            )
            && $elevatedAt > 0
            && $expiresAt >= time()
            && (time() - $elevatedAt)
                <= self::ELEVATION_TTL_SECONDS,
            403,
            'A separate NurseLink administrator sign-in is required.'
        );

        $access = $this->resolveAccess($user);

        abort_unless(
            $access['is_reviewer'],
            403,
            'Reviewer or Administrator access is required.'
        );

        return $access;
    }

    private function resolveAccess($user): array
    {
        $userId = $user->getKey();

        $reviewerAccess = Schema::hasTable(
            'nurselink_reviewer_access'
        )
            ? DB::table('nurselink_reviewer_access')
                ->where('user_id', $userId)
                ->where('active', true)
                ->first()
            : null;

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

        $reviewRole = strtolower(
            (string) ($reviewerAccess->role ?? '')
        );

        $isSuperAdmin = $explicitSuperAdmin
            || $modelSuperAdmin
            || $reviewRole === 'super_admin';

        $isAdmin = $isSuperAdmin
            || (bool) ($user->is_admin ?? false)
            || in_array(
                $modelRole,
                ['admin', 'administrator'],
                true
            )
            || in_array(
                $reviewRole,
                ['admin', 'super_admin'],
                true
            );

        $isReviewer = $isAdmin
            || $reviewRole === 'reviewer';

        $role = match (true) {
            $isSuperAdmin => 'super_admin',
            $isAdmin => 'admin',
            $isReviewer => 'reviewer',
            default => 'user',
        };

        return [
            'role' => $role,
            'label' => match ($role) {
                'super_admin' => 'Super Administrator',
                'admin' => 'Administrator',
                'reviewer' => 'Reviewer',
                default => 'User',
            },
            'is_super_admin' => $isSuperAdmin,
            'is_admin' => $isAdmin,
            'is_reviewer' => $isReviewer,
        ];
    }

    private function userMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(
                fn ($id) => (string) $id,
                $ids
            )
        )));

        if ($ids === []) {
            return [];
        }

        $columns = ['id'];

        foreach ([
            'email',
            'name',
            'first_name',
            'last_name',
            'profile_photo_path',
        ] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $columns[] = $column;
            }
        }

        $rows = DB::table('users')
            ->whereIn('id', $ids)
            ->get($columns);

        $map = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row->name ?? ''));

            if ($name === '') {
                $name = trim(
                    (string) ($row->first_name ?? '')
                    . ' '
                    . (string) ($row->last_name ?? '')
                );
            }

            if ($name === '') {
                $name = (string) ($row->email ?? $row->id);
            }

            $map[(string) $row->id] = [
                'id' => (string) $row->id,
                'name' => $name,
                'email' => (string) ($row->email ?? ''),
                'profile_photo_uploaded' =>
                    ! empty($row->profile_photo_path ?? null),
            ];
        }

        return $map;
    }

    private function generateMemberNumber(object $membership): string
    {
        $year = now()->format('Y');

        return sprintf(
            'NL-%s-%06d',
            $year,
            (int) $membership->id
        );
    }

    private function syncCoreMembership(
        string $userId,
        string $memberNumber
    ): void {
        app(\App\Services\CoreMembershipActivationService::class)
            ->sync($userId, $memberNumber);

        $updates = [];

        if (Schema::hasColumn('users', 'member_number')) {
            $updates['member_number'] = $memberNumber;
        }

        if (Schema::hasColumn('users', 'is_member')) {
            $updates['is_member'] = true;
        }

        if (Schema::hasColumn(
            'users',
            'membership_approved_at'
        )) {
            $updates['membership_approved_at'] = now();
        }

        if ($updates !== []) {
            DB::table('users')
                ->where('id', $userId)
                ->update($updates);
        }
    }

    private function notifyMembershipStatus(
        object $membership
    ): void {
        if (! Schema::hasTable('nurselink_notifications')) {
            return;
        }

        $copy = [
            'under_review' => [
                'info',
                'Membership review started',
                'An authorized NurseLink reviewer is reviewing your membership application.',
                '/application-status',
            ],
            'needs_information' => [
                'warning',
                'Additional information is needed',
                'Your membership review needs additional information. Open Smart Registration to review the request, update your application, and resubmit.',
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
        ];

        $selected = $copy[$membership->status] ?? null;

        if (! $selected) {
            return;
        }

        [$severity, $title, $message, $url] = $selected;

        DB::table('nurselink_notifications')->insert([
            'user_id' => $membership->user_id,
            'type' => 'membership.' . $membership->status,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'action_url' => $url,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function audit(
        Request $request,
        string $action,
        string $targetType,
        string $targetId,
        mixed $before,
        mixed $after
    ): void {
        if (! Schema::hasTable('nurselink_review_audit')) {
            return;
        }

        DB::table('nurselink_review_audit')->insert([
            'reviewer_user_id' => (string) $request
                ->user()
                ->getKey(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before_state' => $before
                ? json_encode(
                    $before,
                    JSON_UNESCAPED_UNICODE
                )
                : null,
            'after_state' => $after
                ? json_encode(
                    $after,
                    JSON_UNESCAPED_UNICODE
                )
                : null,
            'created_at' => now(),
        ]);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'submitted' => 'Submitted',
            'under_review' => 'Under Review',
            'needs_information' => 'Needs Information',
            'ready_for_approval' => 'Ready for Approval',
            'approved' => 'Approved',
            'declined' => 'Declined',
            default => ucwords(
                str_replace('_', ' ', $status)
            ),
        };
    }
}
