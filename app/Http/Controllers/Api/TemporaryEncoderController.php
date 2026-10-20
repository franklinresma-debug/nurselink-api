<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\TemporaryEncoderAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Rules\Password;

class TemporaryEncoderController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Super Administrator management
    |--------------------------------------------------------------------------
    */

    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireSuperAdministrator($request);

        $accounts = TemporaryEncoderAccount::query()
            ->orderByDesc('active')
            ->orderBy('expires_at')
            ->get()
            ->map(function (TemporaryEncoderAccount $account): array {
                $assignments = DB::table('temporary_encoder_assignments')
                    ->where('encoder_account_id', $account->id)
                    ->where('active', true)
                    ->whereNull('revoked_at')
                    ->get();

                return [
                    'id' => $account->id,
                    'username' => $account->username,
                    'email' => $account->email,
                    'display_name' => $account->display_name,
                    'active' => (bool) $account->active,
                    'expires_at' => $account->expires_at?->toIso8601String(),
                    'expired' => ! $account->expires_at || $account->expires_at->isPast(),
                    'last_login_at' => $account->last_login_at?->toIso8601String(),
                    'assignment_count' => $assignments->count(),
                    'created_at' => $account->created_at?->toIso8601String(),
                ];
            })
            ->values();

        return response()->json([
            'data' => $accounts,
        ]);
    }

    public function adminCreate(Request $request): JsonResponse
    {
        $this->requireSuperAdministrator($request);

        $data = $request->validate([
            'username' => [
                'required',
                'string',
                'min:3',
                'max:80',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('temporary_encoder_accounts', 'username'),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(
                    'temporary_encoder_accounts',
                    'email'
                ),
            ],
            'display_name' => [
                'required',
                'string',
                'max:160',
            ],
            'password' => [
                'required',
                'string',
                new Password,
            ],
            'expires_at' => [
                'required',
                'date',
                'after:now',
            ],
        ]);

        $account = TemporaryEncoderAccount::query()->create([
            'username' => mb_strtolower(trim($data['username'])),
            'email' => mb_strtolower(trim($data['email'])),
            'display_name' => trim($data['display_name']),
            'password' => Hash::make($data['password']),
            'active' => true,
            'expires_at' => $data['expires_at'],
            'created_by_user_id' => (string) $request->user()->getKey(),
        ]);

        $this->adminAudit(
            $request,
            'temporary_encoder.account_created',
            (string) $account->id,
            null,
            [
                'username' => $account->username,
                'email' => $account->email,
                'display_name' => $account->display_name,
                'active' => true,
                'expires_at' => $account->expires_at?->toIso8601String(),
            ]
        );

        return response()->json([
            'message' => 'Temporary Encoder account created.',
            'data' => [
                'id' => $account->id,
                'username' => $account->username,
                'email' => $account->email,
                'display_name' => $account->display_name,
                'active' => true,
                'expires_at' => $account->expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function adminUpdate(
        Request $request,
        int $encoderId
    ): JsonResponse {
        $this->requireSuperAdministrator($request);

        $account = TemporaryEncoderAccount::query()
            ->findOrFail($encoderId);

        $data = $request->validate([
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(
                    'temporary_encoder_accounts',
                    'email'
                )->ignore($account->id),
            ],
            'display_name' => [
                'sometimes',
                'required',
                'string',
                'max:160',
            ],
            'active' => [
                'sometimes',
                'boolean',
            ],
            'expires_at' => [
                'sometimes',
                'required',
                'date',
                'after:now',
            ],
        ]);

        if (array_key_exists('email', $data)) {
            $data['email'] =
                mb_strtolower(
                    trim($data['email'])
                );
        }

        $before = [
            'email' => $account->email,
            'display_name' => $account->display_name,
            'active' => (bool) $account->active,
            'expires_at' => $account->expires_at?->toIso8601String(),
        ];

        $account->fill($data);
        $account->save();

        $after = [
            'email' => $account->email,
            'display_name' => $account->display_name,
            'active' => (bool) $account->active,
            'expires_at' => $account->expires_at?->toIso8601String(),
        ];

        $this->adminAudit(
            $request,
            'temporary_encoder.account_updated',
            (string) $account->id,
            $before,
            $after
        );

        return response()->json([
            'message' => 'Temporary Encoder account updated.',
            'data' => $after,
        ]);
    }

    public function adminResetPassword(
        Request $request,
        int $encoderId
    ): JsonResponse {
        $this->requireSuperAdministrator($request);

        $account = TemporaryEncoderAccount::query()
            ->findOrFail($encoderId);

        $data = $request->validate([
            'password' => [
                'required',
                'string',
                new Password,
            ],
        ]);

        $account->forceFill([
            'password' => Hash::make($data['password']),
            'remember_token' => null,
        ])->save();

        $this->adminAudit(
            $request,
            'temporary_encoder.password_reset',
            (string) $account->id,
            null,
            [
                'password_body_excluded_from_audit' => true,
            ]
        );

        return response()->json([
            'message' => 'Temporary Encoder password reset.',
        ]);
    }

    public function adminAssignments(
        Request $request,
        int $encoderId
    ): JsonResponse {
        $this->requireSuperAdministrator($request);

        TemporaryEncoderAccount::query()
            ->findOrFail($encoderId);

        $rows = DB::table('temporary_encoder_assignments as a')
            ->join('users as u', 'u.id', '=', 'a.nurse_user_id')
            ->leftJoin(
                'nurselink_memberships as nm',
                'nm.user_id',
                '=',
                'u.id'
            )
            ->where('a.encoder_account_id', $encoderId)
            ->orderByDesc('a.active')
            ->orderBy('u.name')
            ->get([
                'a.id',
                'a.nurse_user_id',
                'a.active',
                'a.expires_at',
                'a.assignment_reason',
                'a.revoked_at',
                'u.name',
                'u.email',
                'nm.member_number',
                'nm.status as membership_status',
            ]);

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function applicationHandoff(Request $request, \App\Models\Application $application): JsonResponse
    {
        $this->requireSuperAdministrator($request);

        return DB::transaction(function () use ($request, $application): JsonResponse {
            // Resolve ownership from the completed import, never from client input.
            $source = DB::table('nurselink_bulk_intake_candidates as c')
                ->join('nurselink_bulk_intake_batches as b', 'b.id', '=', 'c.batch_id')
                ->where('c.imported_application_id', $application->id)
                ->where('c.imported_user_id', $application->user_id)
                ->whereNotNull('c.imported_at')
                ->select('b.encoder_account_id')->first();
            abort_unless($source, 422, 'This application has no completed Temporary Encoder import.');
            abort_unless(in_array($application->status, ['draft', 'in_progress', 'returned'], true),
                422, 'Only applications awaiting completion can be sent to a Temporary Encoder.');
            $account = TemporaryEncoderAccount::query()->lockForUpdate()->find($source->encoder_account_id);
            abort_unless($account && $account->isUsable(), 422,
                'The originating Temporary Encoder account is inactive or expired. Renew it in Temporary Encoder management first.');

            $assignmentRequest = clone $request;
            $assignmentRequest->merge([
                'nurse_identifier' => (string) $application->user_id,
                'expires_at' => null,
                'assignment_reason' => 'Continue data completion for imported application '.$application->id,
            ]);
            return $this->adminAssignNurse($assignmentRequest, (int) $account->id);
        });
    }

    public function adminAssignNurse(
        Request $request,
        int $encoderId
    ): JsonResponse {
        $this->requireSuperAdministrator($request);

        $account = TemporaryEncoderAccount::query()
            ->findOrFail($encoderId);

        $data = $request->validate([
            'nurse_identifier' => [
                'required',
                'string',
                'max:255',
            ],
            'expires_at' => [
                'nullable',
                'date',
                'after:now',
            ],
            'assignment_reason' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $user = $this->resolveNurse(
            trim($data['nurse_identifier'])
        );

        $expiry = ! empty($data['expires_at'])
            ? $data['expires_at']
            : $account->expires_at;

        $existing = DB::table('temporary_encoder_assignments')
            ->where('encoder_account_id', $account->id)
            ->where('nurse_user_id', $user->id)
            ->first();

        $values = [
            'active' => true,
            'expires_at' => $expiry,
            'assigned_by_user_id' =>
                (string) $request->user()->getKey(),
            'assignment_reason' =>
                trim((string) ($data['assignment_reason'] ?? ''))
                    ?: null,
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('temporary_encoder_assignments')
                ->where('id', $existing->id)
                ->update($values);

            $assignmentId = $existing->id;
        } else {
            $assignmentId = DB::table(
                'temporary_encoder_assignments'
            )->insertGetId([
                'encoder_account_id' => $account->id,
                'nurse_user_id' => $user->id,
                ...$values,
                'created_at' => now(),
            ]);
        }

        $this->adminAudit(
            $request,
            'temporary_encoder.nurse_assigned',
            (string) $assignmentId,
            null,
            [
                'encoder_account_id' => $account->id,
                'nurse_user_id' => $user->id,
                'expires_at' => $expiry,
            ]
        );

        return response()->json([
            'message' => 'Nurse assigned to Temporary Encoder.',
            'data' => [
                'assignment_id' => $assignmentId,
                'nurse_user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'expires_at' => $expiry,
            ],
        ]);
    }

    public function adminRevokeAssignment(
        Request $request,
        int $encoderId,
        int $assignmentId
    ): JsonResponse {
        $this->requireSuperAdministrator($request);

        $row = DB::table('temporary_encoder_assignments')
            ->where('id', $assignmentId)
            ->where('encoder_account_id', $encoderId)
            ->first();

        abort_unless($row, 404);

        DB::table('temporary_encoder_assignments')
            ->where('id', $assignmentId)
            ->update([
                'active' => false,
                'revoked_at' => now(),
                'revoked_by_user_id' =>
                    (string) $request->user()->getKey(),
                'updated_at' => now(),
            ]);

        $this->adminAudit(
            $request,
            'temporary_encoder.assignment_revoked',
            (string) $assignmentId,
            [
                'active' => (bool) $row->active,
                'nurse_user_id' => $row->nurse_user_id,
            ],
            [
                'active' => false,
                'nurse_user_id' => $row->nurse_user_id,
            ]
        );

        return response()->json([
            'message' => 'Temporary Encoder assignment revoked.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Encoder workspace
    |--------------------------------------------------------------------------
    */

    public function assignments(): JsonResponse
    {
        $account = $this->encoder();

        $rows = DB::table('temporary_encoder_assignments as a')
            ->join('users as u', 'u.id', '=', 'a.nurse_user_id')
            ->leftJoin(
                'nurselink_memberships as nm',
                'nm.user_id',
                '=',
                'u.id'
            )
            ->where('a.encoder_account_id', $account->id)
            ->where('a.active', true)
            ->whereNull('a.revoked_at')
            ->where(function ($query): void {
                $query->whereNull('a.expires_at')
                    ->orWhere('a.expires_at', '>', now());
            })
            ->orderBy('u.name')
            ->get([
                'a.id as assignment_id',
                'a.nurse_user_id',
                'a.expires_at',
                'u.name',
                'u.email',
                'nm.member_number',
                'nm.status as membership_status',
            ]);

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function showNurse(
        string $userId
    ): JsonResponse {
        $account = $this->encoder();

        $this->requireAssignment(
            $account,
            $userId
        );

        $user = DB::table('users')
            ->where('id', $userId)
            ->first();

        abort_unless($user, 404);

        $membership = DB::table('nurselink_memberships')
            ->where('user_id', $userId)
            ->first();

        $member = Member::query()
            ->where('user_id', $userId)
            ->with('profile')
            ->first();

        $smartProfile = DB::table(
            'nurselink_smart_registration_profiles'
        )
            ->where('user_id', $userId)
            ->first();

        $applicationProfile =
            $this->applicationProfileForEncoder(
                $userId
            );

        $mode = $member
            ? 'approved_member'
            : 'applicant';

        return response()->json([
            'data' => [
                'mode' => $mode,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'membership' => $membership,
                'profile' => $member
                    ? $member->profile
                    : (
                        $smartProfile
                            ?: (object) $applicationProfile
                    ),
                'permissions' => [
                    'can_edit_personal' => true,
                    'can_edit_professional' => true,
                    'can_submit_application' => true,
                    'can_review_membership' => true,
                    'can_approve_membership' => true,
                    'can_verify_credentials' => true,
                    'can_change_membership_status' => true,
                    'assigned_nurse_only' => true,
                    'temporary_access' => true,
                ],
            ],
        ]);
    }

    public function updatePersonal(
        Request $request,
        string $userId
    ): JsonResponse {
        $account = $this->encoder();

        $this->requireAssignment(
            $account,
            $userId
        );

        $data = $request->validate([
            'first_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'suffix' => ['sometimes', 'nullable', 'string', 'max:40'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'sex' => [
                'sometimes',
                'nullable',
                Rule::in([
                    'female',
                    'male',
                    'non_binary',
                    'prefer_not_to_say',
                    'other',
                ]),
            ],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:80'],
            'mobile_phone' => ['sometimes', 'nullable', 'string', 'max:80'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'province' => ['sometimes', 'nullable', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $member = Member::query()
            ->where('user_id', $userId)
            ->first();

        if ($member) {
            $before = MemberProfile::query()
                ->where('member_id', $member->id)
                ->first();

            $mapped = array_filter([
                'first_name' => $data['first_name'] ?? null,
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'suffix' => $data['suffix'] ?? null,
                'date_of_birth' =>
                    $data['date_of_birth']
                    ?? $data['birth_date']
                    ?? null,
                'nationality' => $data['nationality'] ?? null,
                'mobile_phone' =>
                    $data['mobile_phone']
                    ?? $data['phone']
                    ?? null,
                'city' => $data['city'] ?? null,
                'region' =>
                    $data['region']
                    ?? $data['province']
                    ?? null,
                'country' => $data['country'] ?? null,
            ], fn ($value) => $value !== null);

            $profile = MemberProfile::query()
                ->updateOrCreate(
                    ['member_id' => $member->id],
                    $mapped
                );

            $this->encoderAudit(
                $request,
                $account,
                $userId,
                'encoder.personal_profile_updated',
                'member_profile',
                (string) $profile->id,
                $before?->toArray(),
                $profile->fresh()->toArray()
            );

            return response()->json([
                'message' => 'Personal information saved.',
                'data' => $profile->fresh(),
            ]);
        }

        return $this->updateApplicantProfile(
            $request,
            $account,
            $userId,
            'personal',
            $data
        );
    }

    public function updateProfessional(
        Request $request,
        string $userId
    ): JsonResponse {
        $account = $this->encoder();

        $this->requireAssignment(
            $account,
            $userId
        );

        $data = $request->validate([
            'professional_title' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],
            'years_experience' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                'max:80',
            ],
            'current_position' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],
            'current_employer' => [
                'sometimes',
                'nullable',
                'string',
                'max:190',
            ],
            'specialty' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],
            'primary_license_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:160',
            ],
            'primary_license_country' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],
            'primary_license_expiry' => [
                'sometimes',
                'nullable',
                'date',
            ],
            'highest_nursing_education' => [
                'sometimes',
                'nullable',
                'string',
                'max:190',
            ],
            'graduation_year' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1940',
                'max:' . (now()->year + 1),
            ],
        ]);

        $member = Member::query()
            ->where('user_id', $userId)
            ->first();

        if ($member) {
            $before = MemberProfile::query()
                ->where('member_id', $member->id)
                ->first();

            $mapped = array_filter([
                'professional_title' =>
                    $data['professional_title'] ?? null,
                'years_experience' =>
                    $data['years_experience'] ?? null,
                'current_position' =>
                    $data['current_position'] ?? null,
                'current_employer' =>
                    $data['current_employer'] ?? null,
            ], fn ($value) => $value !== null);

            $profile = MemberProfile::query()
                ->updateOrCreate(
                    ['member_id' => $member->id],
                    $mapped
                );

            $this->encoderAudit(
                $request,
                $account,
                $userId,
                'encoder.professional_profile_updated',
                'member_profile',
                (string) $profile->id,
                $before?->toArray(),
                $profile->fresh()->toArray()
            );

            return response()->json([
                'message' =>
                    'Professional information saved.',
                'data' => $profile->fresh(),
            ]);
        }

        return $this->updateApplicantProfile(
            $request,
            $account,
            $userId,
            'professional',
            $data
        );
    }


    public function documents(
        string $userId
    ): JsonResponse {
        $account = $this->encoder();

        $this->requireAssignment(
            $account,
            $userId
        );

        $documents = [];

        /*
         * Current Smart Registration documents.
         */
        if (
            Schema::hasTable(
                'nurselink_smart_registration_documents'
            )
        ) {
            $query = DB::table(
                'nurselink_smart_registration_documents'
            )
                ->where(
                    'user_id',
                    $userId
                );

            if (
                Schema::hasColumn(
                    'nurselink_smart_registration_documents',
                    'is_current'
                )
            ) {
                $query->where(
                    'is_current',
                    true
                );
            }

            foreach (
                $query
                    ->orderByDesc('id')
                    ->get()
                as $row
            ) {
                $documents[] = [
                    'source' =>
                        'smart_registration',

                    'id' =>
                        (int) $row->id,

                    'name' =>
                        $row->original_name,

                    'mime_type' =>
                        $row->mime_type,

                    'file_size' =>
                        (int) $row->file_size,

                    'document_type' =>
                        $row->document_type
                        ?: 'other',

                    'security_status' =>
                        $row->security_status
                        ?? 'unknown',

                    'extraction_status' =>
                        $row->extraction_status
                        ?? null,

                    'created_at' =>
                        $row->created_at,

                    'version' =>
                        isset($row->version)
                            ? (int) $row->version
                            : 1,

                    'imported_source' =>
                        false,

                    'preview_url' =>
                        '/api/nurselink/encoder/nurses/'
                        . rawurlencode($userId)
                        . '/documents/smart_registration/'
                        . (int) $row->id
                        . '/preview',
                ];
            }
        }

        /*
         * Original governed Bulk Nurse Intake evidence.
         * Resolve candidate from the imported application snapshot.
         */
        $application = DB::table(
            'applications'
        )
            ->where(
                'user_id',
                $userId
            )
            ->orderByDesc(
                'created_at'
            )
            ->first();

        if ($application) {
            $profile = json_decode(
                (string) (
                    $application->profile_data
                    ?? '{}'
                ),
                true
            );

            if (! is_array($profile)) {
                $profile = [];
            }

            $snapshot =
                isset(
                    $profile[
                        '_bulk_professional_snapshot'
                    ]
                )
                && is_array(
                    $profile[
                        '_bulk_professional_snapshot'
                    ]
                )
                    ? $profile[
                        '_bulk_professional_snapshot'
                    ]
                    : [];

            $candidateId =
                $snapshot['candidate_id']
                ?? (
                    $profile['_bulk_intake'][
                        'candidate_id'
                    ]
                    ?? null
                );

            if (
                $candidateId
                && Schema::hasTable(
                    'nurselink_bulk_intake_files'
                )
            ) {
                $bulkRows = DB::table(
                    'nurselink_bulk_intake_files'
                )
                    ->where(
                        'candidate_id',
                        $candidateId
                    )
                    ->orderByDesc('id')
                    ->get();

                foreach ($bulkRows as $row) {
                    $documents[] = [
                        'source' =>
                            'bulk_intake',

                        'id' =>
                            (int) $row->id,

                        'name' =>
                            $row->original_name,

                        'mime_type' =>
                            $row->mime_type,

                        'file_size' =>
                            (int) $row->file_size,

                        'document_type' =>
                            $row->document_type
                            ?: 'other',

                        'security_status' =>
                            $row->security_status
                            ?? 'unknown',

                        'extraction_status' =>
                            $row->extraction_status
                            ?? null,

                        'created_at' =>
                            $row->created_at,

                        'version' =>
                            1,

                        'imported_source' =>
                            true,

                        'preview_url' =>
                            '/api/nurselink/encoder/nurses/'
                            . rawurlencode($userId)
                            . '/documents/bulk_intake/'
                            . (int) $row->id
                            . '/preview',
                    ];
                }
            }
        }

        usort(
            $documents,
            static fn (array $a, array $b) =>
                strcmp(
                    (string) (
                        $b['created_at']
                        ?? ''
                    ),
                    (string) (
                        $a['created_at']
                        ?? ''
                    )
                )
        );

        return response()->json([
            'data' =>
                $documents,
        ]);
    }


    public function previewDocument(
        Request $request,
        string $userId,
        string $source,
        int $documentId
    ) {
        $account = $this->encoder();

        $this->requireAssignment(
            $account,
            $userId
        );

        abort_unless(
            in_array(
                $source,
                [
                    'smart_registration',
                    'bulk_intake',
                ],
                true
            ),
            404
        );

        if (
            $source ===
            'smart_registration'
        ) {
            $document = DB::table(
                'nurselink_smart_registration_documents'
            )
                ->where(
                    'id',
                    $documentId
                )
                ->where(
                    'user_id',
                    $userId
                )
                ->first();

            abort_unless(
                $document,
                404
            );

            abort_unless(
                in_array(
                    (string) (
                        $document->security_status
                        ?? ''
                    ),
                    [
                        'clean',
                        'unavailable',
                    ],
                    true
                ),
                423,
                'Document security scanning must complete before preview.'
            );

        } else {
            $application = DB::table(
                'applications'
            )
                ->where(
                    'user_id',
                    $userId
                )
                ->orderByDesc(
                    'created_at'
                )
                ->first();

            abort_unless(
                $application,
                404
            );

            $profile = json_decode(
                (string) (
                    $application->profile_data
                    ?? '{}'
                ),
                true
            );

            if (! is_array($profile)) {
                $profile = [];
            }

            $snapshot =
                isset(
                    $profile[
                        '_bulk_professional_snapshot'
                    ]
                )
                && is_array(
                    $profile[
                        '_bulk_professional_snapshot'
                    ]
                )
                    ? $profile[
                        '_bulk_professional_snapshot'
                    ]
                    : [];

            $candidateId =
                $snapshot['candidate_id']
                ?? (
                    $profile['_bulk_intake'][
                        'candidate_id'
                    ]
                    ?? null
                );

            abort_unless(
                $candidateId,
                404
            );

            $document = DB::table(
                'nurselink_bulk_intake_files'
            )
                ->where(
                    'id',
                    $documentId
                )
                ->where(
                    'candidate_id',
                    $candidateId
                )
                ->first();

            abort_unless(
                $document,
                404
            );

            abort_unless(
                (string) (
                    $document->security_status
                    ?? ''
                ) === 'clean',
                423,
                'Imported document security scanning must complete before preview.'
            );
        }

        $path =
            $document->storage_path
            ?? null;

        abort_unless(
            is_string($path)
            && $path !== ''
            && \Illuminate\Support\Facades\Storage::disk(
                'local'
            )->exists($path),
            404
        );

        $mime =
            $document->mime_type
            ?: \Illuminate\Support\Facades\Storage::disk(
                'local'
            )->mimeType($path)
            ?: 'application/octet-stream';

        $filename = trim(
            str_replace(
                ["\r", "\n", '"'],
                '',
                (string) (
                    $document->original_name
                    ?? 'document'
                )
            )
        );

        if ($filename === '') {
            $filename = 'document';
        }

        $this->encoderAudit(
            $request,
            $account,
            $userId,
            'encoder.document_previewed',
            $source === 'bulk_intake'
                ? 'bulk_intake_file'
                : 'smart_registration_document',
            (string) $documentId,
            null,
            [
                'source' =>
                    $source,

                'original_name' =>
                    $filename,

                'mime_type' =>
                    $mime,
            ]
        );

        return response()->file(
            \Illuminate\Support\Facades\Storage::disk(
                'local'
            )->path($path),
            [
                'Content-Type' =>
                    $mime,

                'Content-Disposition' =>
                    'inline; filename="'
                    . $filename
                    . '"',

                'Cache-Control' =>
                    'private, no-store, max-age=0',

                'X-Content-Type-Options' =>
                    'nosniff',
            ]
        );
    }


    public function uploadDocument(
        Request $request,
        string $userId
    ): JsonResponse {
        $account = $this->encoder();

        $this->requireAssignment(
            $account,
            $userId
        );

        $data = $request->validate([
            'document' => [
                'required',
                'file',
                'max:15360',
                'mimes:pdf,jpg,jpeg,png,doc,docx',
            ],

            'document_type' => [
                'nullable',
                'string',
                'max:80',
            ],
        ]);

        $file =
            $data['document'];

        abort_unless(
            $file
            && $file->isValid(),
            422,
            'The uploaded document is invalid.'
        );

        $sha256 =
            hash_file(
                'sha256',
                $file->getRealPath()
            );

        $duplicate = DB::table(
            'nurselink_smart_registration_documents'
        )
            ->where(
                'user_id',
                $userId
            )
            ->where(
                'sha256',
                $sha256
            )
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' =>
                    'This exact document is already attached to this nurse.',

                'data' => [
                    'id' =>
                        (int) $duplicate->id,
                ],
            ], 409);
        }

        $extension = strtolower(
            (string) $file
                ->getClientOriginalExtension()
        );

        $safeExtension =
            in_array(
                $extension,
                [
                    'pdf',
                    'jpg',
                    'jpeg',
                    'png',
                    'doc',
                    'docx',
                ],
                true
            )
                ? $extension
                : 'bin';

        $storedName =
            \Illuminate\Support\Str::uuid()
                ->toString()
            . '.'
            . $safeExtension;

        $directory =
            'nurselink-smart-registration/'
            . preg_replace(
                '/[^A-Za-z0-9._-]/',
                '_',
                $userId
            );

        $storagePath =
            $file->storeAs(
                $directory,
                $storedName,
                'local'
            );

        abort_unless(
            $storagePath,
            500,
            'Unable to store the uploaded document securely.'
        );

        $insert = [
            'user_id' =>
                $userId,

            'original_name' =>
                mb_substr(
                    (string) $file
                        ->getClientOriginalName(),
                    0,
                    255
                ),

            'storage_path' =>
                $storagePath,

            'mime_type' =>
                mb_substr(
                    (string) (
                        $file->getMimeType()
                        ?: $file->getClientMimeType()
                    ),
                    0,
                    160
                ),

            'file_size' =>
                (int) $file->getSize(),

            'sha256' =>
                $sha256,

            'document_type' =>
                trim(
                    (string) (
                        $data['document_type']
                        ?? ''
                    )
                ) ?: 'other',

            'extraction_status' =>
                'queued',

            'extraction_message' =>
                'Document uploaded by Temporary Encoder and queued for automatic extraction.',

            'created_at' =>
                now(),

            'updated_at' =>
                now(),
        ];

        if (
            Schema::hasColumn(
                'nurselink_smart_registration_documents',
                'security_status'
            )
        ) {
            $insert['security_status'] =
                'pending';

            $insert['security_message'] =
                'Document is waiting for security scanning.';
        }

        if (
            Schema::hasColumn(
                'nurselink_smart_registration_documents',
                'version'
            )
        ) {
            $insert['version'] = 1;
        }

        if (
            Schema::hasColumn(
                'nurselink_smart_registration_documents',
                'is_current'
            )
        ) {
            $insert['is_current'] = true;
        }

        $id = DB::table(
            'nurselink_smart_registration_documents'
        )->insertGetId($insert);

        if (
            (bool) config(
                'smart_registration.queue.enabled',
                true
            )
        ) {
            \App\Jobs\ProcessSmartRegistrationDocument::dispatch(
                $id
            );
        } else {
            app(
                \App\Services\SmartRegistration\SmartRegistrationDocumentProcessor::class
            )->process($id);
        }

        $this->encoderAudit(
            $request,
            $account,
            $userId,
            'encoder.document_uploaded',
            'smart_registration_document',
            (string) $id,
            null,
            [
                'original_name' =>
                    $file->getClientOriginalName(),

                'document_type' =>
                    $insert['document_type'],

                'sha256' =>
                    $sha256,
            ]
        );

        return response()->json([
            'message' =>
                'Document uploaded securely and queued for processing.',

            'data' => [
                'id' =>
                    $id,

                'name' =>
                    $insert['original_name'],

                'document_type' =>
                    $insert['document_type'],

                'security_status' =>
                    $insert['security_status']
                    ?? 'unknown',

                'extraction_status' =>
                    'queued',
            ],
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function updateApplicantProfile(
        Request $request,
        TemporaryEncoderAccount $account,
        string $userId,
        string $section,
        array $data
    ): JsonResponse {
        $table = 'nurselink_smart_registration_profiles';

        abort_unless(
            Schema::hasTable($table),
            500,
            'Smart Registration profile storage is unavailable.'
        );

        $before = DB::table($table)
            ->where('user_id', $userId)
            ->first();

        if (! $before) {
            $seed =
                $this->applicationProfileForEncoder(
                    $userId
                );

            DB::table($table)->insert([
                'user_id' =>
                    $userId,

                'first_name' =>
                    $seed['first_name'] ?? null,

                'middle_name' =>
                    $seed['middle_name'] ?? null,

                'last_name' =>
                    $seed['last_name'] ?? null,

                'birth_date' =>
                    $seed['birth_date'] ?? null,

                'sex' =>
                    $seed['sex'] ?? null,

                'nationality' =>
                    $seed['nationality'] ?? null,

                'phone' =>
                    $seed['phone'] ?? null,

                'address_line1' =>
                    $seed['address_line1'] ?? null,

                'city' =>
                    $seed['city'] ?? null,

                'province' =>
                    $seed['province'] ?? null,

                'country' =>
                    $seed['country'] ?? null,

                'professional_title' =>
                    $seed['professional_title'] ?? null,

                'years_experience' =>
                    $seed['years_experience'] ?? null,

                'current_position' =>
                    $seed['current_position'] ?? null,

                'current_employer' =>
                    $seed['current_employer'] ?? null,

                'specialty' =>
                    $seed['specialty'] ?? null,

                'primary_license_number' =>
                    $seed['primary_license_number'] ?? null,

                'primary_license_country' =>
                    $seed['primary_license_country'] ?? null,

                'primary_license_expiry' =>
                    $seed['primary_license_expiry'] ?? null,

                'highest_nursing_education' =>
                    $seed['highest_nursing_education'] ?? null,

                'graduation_year' =>
                    $seed['graduation_year'] ?? null,

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);
        }

        $allowed = $section === 'personal'
            ? [
                'first_name',
                'middle_name',
                'last_name',
                'birth_date',
                'sex',
                'nationality',
                'phone',
                'address_line1',
                'city',
                'province',
                'country',
            ]
            : [
                'professional_title',
                'years_experience',
                'current_position',
                'current_employer',
                'specialty',
                'primary_license_number',
                'primary_license_country',
                'primary_license_expiry',
                'highest_nursing_education',
                'graduation_year',
            ];

        $update = collect($data)
            ->only($allowed)
            ->all();

        if ($section === 'personal') {
            if (
                array_key_exists('date_of_birth', $data)
                && ! array_key_exists('birth_date', $update)
            ) {
                $update['birth_date'] = $data['date_of_birth'];
            }

            if (
                array_key_exists('mobile_phone', $data)
                && ! array_key_exists('phone', $update)
            ) {
                $update['phone'] = $data['mobile_phone'];
            }

            if (
                array_key_exists('region', $data)
                && ! array_key_exists('province', $update)
            ) {
                $update['province'] = $data['region'];
            }
        }

        $update['updated_at'] = now();

        DB::table($table)
            ->where('user_id', $userId)
            ->update($update);

        $after = DB::table($table)
            ->where('user_id', $userId)
            ->first();

        $this->encoderAudit(
            $request,
            $account,
            $userId,
            'encoder.' . $section . '_profile_updated',
            'smart_registration_profile',
            (string) $after->id,
            $before ? (array) $before : null,
            (array) $after
        );

        return response()->json([
            'message' =>
                ucfirst($section)
                . ' information saved.',
            'data' => $after,
        ]);
    }

    public function initializeImportedProfile(string $userId): void
    {
        $table = 'nurselink_smart_registration_profiles';
        if (DB::table($table)->where('user_id', $userId)->exists()) return;
        if (! DB::table('nurselink_bulk_intake_candidates')->where('imported_user_id', $userId)->whereNotNull('imported_at')->exists()) return;
        $seed = $this->applicationProfileForEncoder($userId);
        if ($seed === []) return;
        if (isset($seed['sex'])) $seed['sex'] = strtolower($seed['sex']);
        DB::table($table)->insertOrIgnore([
            'user_id' => $userId, ...$seed, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function applicationProfileForEncoder(
        string $userId
    ): array {
        $application = DB::table(
            'applications'
        )
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->first();

        if (! $application) {
            return [];
        }

        $profile = json_decode(
            (string) ($application->profile_data ?? '{}'),
            true
        );

        if (! is_array($profile)) {
            $profile = [];
        }

        $bulk =
            isset($profile['_bulk_intake'])
            && is_array($profile['_bulk_intake'])
                ? $profile['_bulk_intake']
                : [];

        $snapshot =
            isset($profile['_bulk_professional_snapshot'])
            && is_array(
                $profile['_bulk_professional_snapshot']
            )
                ? $profile['_bulk_professional_snapshot']
                : [];

        $credentials =
            isset($snapshot['credentials'])
            && is_array($snapshot['credentials'])
                ? $snapshot['credentials']
                : [];

        $primaryCredential =
            collect($credentials)
                ->first(
                    fn ($credential) =>
                        is_array($credential)
                        && in_array(
                            $credential['category'] ?? null,
                            ['license', 'registration', 'professional_license'],
                            true
                        )
                );

        if (! is_array($primaryCredential)) {
            $primaryCredential = [];
        }

        return [
            'first_name' =>
                $profile['first_name'] ?? null,

            'middle_name' =>
                $profile['middle_name'] ?? null,

            'last_name' =>
                $profile['last_name'] ?? null,

            'birth_date' =>
                $profile['date_of_birth']
                ?? null,

            'sex' =>
                $profile['sex']
                ?? null,

            'nationality' =>
                $profile['nationality']
                ?? null,

            'phone' =>
                $profile['mobile_phone']
                ?? null,

            'address_line1' =>
                $profile['address_line1']
                ?? null,

            'city' =>
                $profile['city']
                ?? null,

            'province' =>
                $profile['region']
                ?? null,

            'country' =>
                $profile['country']
                ?? null,

            'professional_title' =>
                $profile['professional_title']
                ?? null,

            'years_experience' =>
                $profile['years_experience']
                ?? null,

            'current_position' =>
                $profile['current_position']
                ?? null,

            'current_employer' =>
                $profile['current_employer']
                ?? null,

            'specialty' =>
                $bulk['specialty']
                ?? $profile['specialty']
                ?? null,

            'primary_license_number' =>
                $profile['primary_license_number']
                ?? $primaryCredential['credential_number']
                ?? null,

            'primary_license_country' =>
                $profile['primary_license_country']
                ?? $primaryCredential['country']
                ?? null,

            'primary_license_expiry' =>
                $profile['primary_license_expiry']
                ?? $primaryCredential['expires_on']
                ?? null,

            'highest_nursing_education' =>
                $bulk['highest_nursing_education']
                ?? $profile['highest_nursing_education']
                ?? null,

            'graduation_year' =>
                $bulk['graduation_year']
                ?? $profile['graduation_year']
                ?? null,
        ];
    }


    private function encoder(): TemporaryEncoderAccount
    {
        $account = Auth::guard('encoder')->user();

        abort_unless(
            $account instanceof TemporaryEncoderAccount,
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
            ->where('active', true)
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere(
                        'expires_at',
                        '>',
                        now()
                    );
            })
            ->first();

        abort_unless(
            $assignment,
            403,
            'This nurse is not assigned to your Temporary Encoder account.'
        );

        return $assignment;
    }

    private function resolveNurse(string $identifier): object
    {
        $query = DB::table('users as u')
            ->leftJoin(
                'nurselink_memberships as nm',
                'nm.user_id',
                '=',
                'u.id'
            )
            ->leftJoin(
                'members as m',
                'm.user_id',
                '=',
                'u.id'
            )
            ->leftJoin(
                'applications as a',
                'a.user_id',
                '=',
                'u.id'
            )
            ->where(function ($q) use ($identifier): void {
                $q->whereRaw(
                    'LOWER(u.email) = ?',
                    [mb_strtolower($identifier)]
                )
                    ->orWhere(
                        'u.id',
                        $identifier
                    )
                    ->orWhere(
                        'nm.member_number',
                        $identifier
                    )
                    ->orWhere(
                        'm.member_no',
                        $identifier
                    );
            })
            ->where(function ($q): void {
                $q->whereNotNull('nm.id')
                    ->orWhereNotNull('m.id')
                    ->orWhereNotNull('a.id');
            })
            ->select('u.*')
            ->first();

        abort_unless(
            $query,
            422,
            'No NurseLink nurse or applicant matches that identifier.'
        );

        return $query;
    }

    private function requireSuperAdministrator(
        Request $request
    ): void {
        $user = $request->user();

        abort_unless($user, 401);

        $userId = (string) $user->getKey();

        $granular = DB::table(
            'nurselink_admin_role_assignments'
        )
            ->where('user_id', $userId)
            ->where(
                'role_key',
                'super_administrator'
            )
            ->where('active', true)
            ->exists();

        $legacy = Schema::hasTable(
            'nurselink_super_admin_access'
        )
            && DB::table(
                'nurselink_super_admin_access'
            )
                ->where('user_id', $userId)
                ->where('active', true)
                ->exists();

        abort_unless(
            $granular || $legacy,
            403,
            'Super Administrator access is required.'
        );
    }

    private function adminAudit(
        Request $request,
        string $action,
        string $targetId,
        ?array $before,
        ?array $after
    ): void {
        if (! Schema::hasTable('nurselink_review_audit')) {
            return;
        }

        DB::table('nurselink_review_audit')->insert([
            'reviewer_user_id' =>
                (string) $request->user()->getKey(),
            'action' => $action,
            'target_type' =>
                'temporary_encoder_access',
            'target_id' => $targetId,
            'before_state' =>
                $before
                    ? json_encode($before)
                    : null,
            'after_state' =>
                $after
                    ? json_encode($after)
                    : null,
            'created_at' => now(),
        ]);
    }

    private function encoderAudit(
        Request $request,
        TemporaryEncoderAccount $account,
        string $nurseUserId,
        string $action,
        string $targetType,
        string $targetId,
        ?array $before,
        ?array $after
    ): void {
        DB::table('temporary_encoder_audit')->insert([
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
                    ? json_encode($before)
                    : null,
            'after_state' =>
                $after
                    ? json_encode($after)
                    : null,
            'ip_address' =>
                (string) $request->ip(),
            'user_agent' =>
                mb_substr(
                    (string) $request->userAgent(),
                    0,
                    2000
                ),
            'created_at' => now(),
        ]);
    }
}
