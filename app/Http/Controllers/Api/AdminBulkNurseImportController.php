<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Application;
use App\Models\Role;
use App\Models\User;
use App\Services\IdentifierService;

class AdminBulkNurseImportController extends Controller
{
    public function index(
        Request $request
    ): JsonResponse {
        $this->requireSuperAdministrator(
            $request
        );

        $rows = DB::table(
            'nurselink_bulk_intake_candidates as c'
        )
            ->leftJoin(
                'nurselink_bulk_intake_batches as b',
                'b.id',
                '=',
                'c.batch_id'
            )
            ->where(
                'c.import_status',
                'ready_for_admin_import'
            )
            ->whereNull(
                'c.imported_user_id'
            )
            ->orderBy(
                'c.ready_for_import_at'
            )
            ->get([
                'c.id',
                'c.batch_id',
                'b.name as batch_name',
                'c.display_name',
                'c.first_name',
                'c.second_name',
                'c.middle_initial',
                'c.middle_name',
                'c.last_name',
                'c.email',
                'c.phone',
                'c.address_line1',
                'c.city',
                'c.province',
                'c.country',
                'c.professional_title',
                'c.current_position',
                'c.current_employer',
                'c.specialty',
                'c.years_experience',
                'c.highest_nursing_education',
                'c.graduation_year',
                'c.photo_status',
                'c.photo_confidence',
                'c.ready_for_import_at',
                'c.import_status',
                'c.import_failure',
                'c.created_at',
                'c.updated_at',
            ]);

        $data = $rows
            ->map(
                function ($row): array {
                    $candidateId =
                        (int) $row->id;

                    $email =
                        mb_strtolower(
                            trim(
                                (string) $row->email
                            )
                        );

                    $duplicateUser = null;

                    if ($email !== '') {
                        $duplicateUser =
                            DB::table('users')
                                ->whereRaw(
                                    'LOWER(email) = ?',
                                    [$email]
                                )
                                ->first([
                                    'id',
                                    'name',
                                    'email',
                                    'email_verified_at',
                                    'status',
                                ]);
                    }

                    return [
                        'candidate' =>
                            (array) $row,

                        'review' => [
                            'education' =>
                                $this->reviewCounts(
                                    'nurselink_bulk_intake_education',
                                    $candidateId
                                ),

                            'employment' =>
                                $this->reviewCounts(
                                    'nurselink_bulk_intake_employment',
                                    $candidateId
                                ),

                            'credentials' =>
                                $this->reviewCounts(
                                    'nurselink_bulk_intake_credentials',
                                    $candidateId
                                ),

                            'competencies' =>
                                $this->reviewCounts(
                                    'nurselink_bulk_intake_competencies',
                                    $candidateId
                                ),

                            'languages' =>
                                $this->reviewCounts(
                                    'nurselink_bulk_intake_languages',
                                    $candidateId
                                ),

                            'references' =>
                                $this->reviewCounts(
                                    'nurselink_bulk_intake_references',
                                    $candidateId
                                ),
                        ],

                        'duplicate' => [
                            'email' =>
                                $email ?: null,

                            'user_exists' =>
                                $duplicateUser !== null,

                            'user' =>
                                $duplicateUser
                                    ? (array) $duplicateUser
                                    : null,
                        ],

                        'governance' => [
                            'permanent_user_created' =>
                                false,

                            'application_created' =>
                                false,

                            'member_created' =>
                                false,

                            'credential_verification_changed' =>
                                false,

                            'requires_super_administrator_import' =>
                                true,
                        ],
                    ];
                }
            )
            ->values();

        return response()->json([
            'data' => $data,

            'summary' => [
                'ready_for_admin_import' =>
                    $data->count(),

                'duplicate_email_matches' =>
                    $data
                        ->filter(
                            fn (array $item): bool =>
                                (bool) (
                                    $item[
                                        'duplicate'
                                    ][
                                        'user_exists'
                                    ]
                                    ?? false
                                )
                        )
                        ->count(),
            ],

            'governance' => [
                'read_only' => true,

                'import_creates_member' =>
                    false,

                'credential_review_is_not_verification' =>
                    true,
            ],
        ]);
    }


    public function show(
        Request $request,
        int $candidateId
    ): JsonResponse {
        $this->requireSuperAdministrator(
            $request
        );

        $candidate = DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where(
                'id',
                $candidateId
            )
            ->first();

        abort_unless(
            $candidate,
            404,
            'Bulk Intake candidate not found.'
        );

        abort_unless(
            (string) $candidate->import_status
                === 'ready_for_admin_import',
            422,
            'This candidate is not awaiting Administrator Import.'
        );

        $collections = [
            'education' =>
                'nurselink_bulk_intake_education',

            'employment' =>
                'nurselink_bulk_intake_employment',

            'credentials' =>
                'nurselink_bulk_intake_credentials',

            'competencies' =>
                'nurselink_bulk_intake_competencies',

            'languages' =>
                'nurselink_bulk_intake_languages',

            'references' =>
                'nurselink_bulk_intake_references',
        ];

        $structured = [];

        foreach (
            $collections
            as $label => $table
        ) {
            $structured[$label] =
                Schema::hasTable($table)
                    ? DB::table($table)
                        ->where(
                            'candidate_id',
                            $candidateId
                        )
                        ->orderBy('id')
                        ->get()
                        ->map(
                            fn ($row) =>
                                (array) $row
                        )
                        ->values()
                    : collect();
        }

        $email = mb_strtolower(
            trim(
                (string) (
                    $candidate->email
                    ?? ''
                )
            )
        );

        $duplicate = null;

        if ($email !== '') {
            $duplicate = DB::table(
                'users'
            )
                ->whereRaw(
                    'LOWER(email) = ?',
                    [$email]
                )
                ->first([
                    'id',
                    'name',
                    'email',
                    'email_verified_at',
                    'status',
                ]);
        }

        return response()->json([
            'data' => [
                'candidate' =>
                    (array) $candidate,

                'structured' =>
                    $structured,

                'duplicate' => [
                    'email' =>
                        $email ?: null,

                    'user_exists' =>
                        $duplicate !== null,

                    'user' =>
                        $duplicate
                            ? (array) $duplicate
                            : null,
                ],
            ],

            'governance' => [
                'read_only' =>
                    true,

                'member_not_created_until_application_approval' =>
                    true,

                'credential_verification_preserved' =>
                    true,

                'character_references_private' =>
                    true,
            ],
        ]);
    }


    public function import(
        Request $request,
        int $candidateId,
        IdentifierService $ids
    ): JsonResponse {
        $this->requireSuperAdministrator(
            $request
        );

        $actor =
            $request->user();

        $result = DB::transaction(
            function () use (
                $request,
                $candidateId,
                $ids,
                $actor
            ): array {
                $candidate = DB::table(
                    'nurselink_bulk_intake_candidates'
                )
                    ->where(
                        'id',
                        $candidateId
                    )
                    ->lockForUpdate()
                    ->first();

                abort_unless(
                    $candidate,
                    404,
                    'Bulk Intake candidate not found.'
                );

                abort_unless(
                    (string) $candidate->import_status
                        === 'ready_for_admin_import',
                    422,
                    'This candidate is not awaiting Administrator Import.'
                );

                abort_if(
                    ! empty(
                        $candidate->imported_user_id
                    ),
                    409,
                    'This candidate has already been imported.'
                );

                $email =
                    mb_strtolower(
                        trim(
                            (string) (
                                $candidate->email
                                ?? ''
                            )
                        )
                    );

                abort_if(
                    $email === '',
                    422,
                    'Candidate email is required for import.'
                );

                /*
                 * Fresh duplicate check under the same transaction.
                 */
                $existing = DB::table(
                    'users'
                )
                    ->whereRaw(
                        'LOWER(email) = ?',
                        [$email]
                    )
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    DB::table(
                        'nurselink_bulk_intake_candidates'
                    )
                        ->where(
                            'id',
                            $candidateId
                        )
                        ->update([
                            'import_status' =>
                                'blocked_duplicate',

                            'import_failure' =>
                                'A NurseLink user with this email already exists.',

                            'updated_at' =>
                                now(),
                        ]);

                    abort(
                        409,
                        'A NurseLink user with this email already exists.'
                    );
                }

                $pending = 0;

                foreach (
                    [
                        'nurselink_bulk_intake_education',
                        'nurselink_bulk_intake_employment',
                        'nurselink_bulk_intake_credentials',
                        'nurselink_bulk_intake_competencies',
                        'nurselink_bulk_intake_languages',
                        'nurselink_bulk_intake_references',
                    ]
                    as $table
                ) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }

                    $pending +=
                        DB::table($table)
                            ->where(
                                'candidate_id',
                                $candidateId
                            )
                            ->where(
                                'review_status',
                                'review_required'
                            )
                            ->count();
                }

                abort_if(
                    $pending > 0,
                    422,
                    'Structured review is incomplete.'
                );

                abort_if(
                    ! empty(
                        $candidate->photo_path
                    )
                    && ! in_array(
                        (string) (
                            $candidate->photo_status
                            ?? ''
                        ),
                        [
                            'approved',
                            'rejected',
                        ],
                        true
                    ),
                    422,
                    'Profile photo review is incomplete.'
                );

                /*
                 * Create an account that cannot be guessed or logged into
                 * with a temporary password known to an Administrator.
                 *
                 * The future password-reset flow gives the user control
                 * of their own password.
                 */
                $user = User::query()->create([
                    'name' =>
                        trim(
                            (string) (
                                $candidate->display_name
                                ?: $email
                            )
                        ),

                    'email' =>
                        $email,

                    'password' =>
                        Hash::make(
                            Str::random(80)
                        ),

                    'status' =>
                        'active',

                    'mfa_required' =>
                        false,

                    /*
                     * Deliberately untouched:
                     * email_verified_at
                     * terms_accepted_at
                     * privacy_accepted_at
                     */
                ]);

                $applicantRole =
                    Role::query()
                        ->where(
                            'code',
                            'applicant'
                        )
                        ->firstOrFail();

                $user->roles()
                    ->syncWithoutDetaching([
                        $applicantRole->id => [
                            'assigned_at' =>
                                now(),
                        ],
                    ]);

                $middleName =
                    trim(
                        (string) (
                            $candidate->middle_name
                            ?? ''
                        )
                    );

                $profile = [
                    'first_name' =>
                        $candidate->first_name
                        ?: null,

                    'middle_name' =>
                        $middleName !== ''
                            ? $middleName
                            : null,

                    'last_name' =>
                        $candidate->last_name
                        ?: null,

                    'suffix' =>
                        null,

                    'date_of_birth' =>
                        $candidate->birth_date
                        ?: null,

                    'nationality' =>
                        $candidate->nationality
                        ?: null,

                    'mobile_phone' =>
                        $candidate->phone
                        ?: null,

                    'city' =>
                        $candidate->city
                        ?: null,

                    'region' =>
                        $candidate->province
                        ?: null,

                    'country' =>
                        $candidate->country
                        ?: null,

                    'professional_title' =>
                        $candidate->professional_title
                        ?: null,

                    'current_position' =>
                        $candidate->current_position
                        ?: null,

                    'current_employer' =>
                        $candidate->current_employer
                        ?: null,

                    'years_experience' =>
                        $candidate->years_experience
                        !== null
                            ? (int) $candidate->years_experience
                            : null,

                    /*
                     * Bulk provenance stays inside the application
                     * profile instead of claiming member verification.
                     */
                    '_bulk_intake' => [
                        'candidate_id' =>
                            $candidateId,

                        'batch_id' =>
                            (int) $candidate->batch_id,

                        'imported_by_user_id' =>
                            (string) $actor->getKey(),

                        'imported_at' =>
                            now()->toIso8601String(),

                        'second_name' =>
                            $candidate->second_name
                            ?: null,

                        'middle_initial' =>
                            $candidate->middle_initial
                            ?: null,

                        'highest_nursing_education' =>
                            $candidate->highest_nursing_education
                            ?: null,

                        'graduation_year' =>
                            $candidate->graduation_year
                            ?: null,

                        'specialty' =>
                            $candidate->specialty
                            ?: null,
                    ],
                ];

                /*
                 * NurseLink Bulk Intake professional snapshot.
                 *
                 * This freezes only human-approved extraction data into the
                 * draft Application. It does NOT create Member portfolio
                 * records and does NOT verify any credential.
                 *
                 * The normal membership approval lifecycle will later
                 * materialize this snapshot into member-scoped professional
                 * records.
                 */
                $professionalSnapshot = [
                    'schema_version' =>
                        1,

                    'source' =>
                        'bulk_nurse_intake',

                    'candidate_id' =>
                        (int) $candidate->id,

                    'captured_at' =>
                        now()->toIso8601String(),

                    'education' =>
                        DB::table(
                            'nurselink_bulk_intake_education'
                        )
                            ->where(
                                'candidate_id',
                                $candidate->id
                            )
                            ->where(
                                'review_status',
                                'approved'
                            )
                            ->orderBy('id')
                            ->get([
                                'qualification',
                                'field_of_study',
                                'institution',
                                'country',
                                'started_on',
                                'completed_on',
                                'review_notes',
                            ])
                            ->map(
                                fn ($row) =>
                                    (array) $row
                            )
                            ->values()
                            ->all(),

                    'employment' =>
                        DB::table(
                            'nurselink_bulk_intake_employment'
                        )
                            ->where(
                                'candidate_id',
                                $candidate->id
                            )
                            ->where(
                                'review_status',
                                'approved'
                            )
                            ->orderBy('id')
                            ->get([
                                'position_title',
                                'employer',
                                'facility_type',
                                'country',
                                'city',
                                'started_on',
                                'ended_on',
                                'is_current',
                                'responsibilities',
                                'review_notes',
                            ])
                            ->map(
                                fn ($row) =>
                                    (array) $row
                            )
                            ->values()
                            ->all(),

                    'credentials' =>
                        DB::table(
                            'nurselink_bulk_intake_credentials'
                        )
                            ->where(
                                'candidate_id',
                                $candidate->id
                            )
                            ->where(
                                'review_status',
                                'approved'
                            )
                            ->orderBy('id')
                            ->get([
                                'category',
                                'credential_type',
                                'title',
                                'credential_number',
                                'issuing_authority',
                                'country',
                                'issued_on',
                                'expires_on',
                                'review_notes',
                            ])
                            ->map(function ($row): array {
                                $data =
                                    (array) $row;

                                /*
                                 * Review of OCR/extraction is not credential
                                 * verification. Permanent credential records
                                 * must start unverified.
                                 */
                                $data[
                                    'verification_status'
                                ] = 'unverified';

                                return $data;
                            })
                            ->values()
                            ->all(),

                    'competencies' =>
                        DB::table(
                            'nurselink_bulk_intake_competencies'
                        )
                            ->where(
                                'candidate_id',
                                $candidate->id
                            )
                            ->where(
                                'review_status',
                                'approved'
                            )
                            ->orderBy('id')
                            ->get([
                                'domain',
                                'name',
                                'proficiency',
                                'review_notes',
                            ])
                            ->map(
                                fn ($row) =>
                                    (array) $row
                            )
                            ->values()
                            ->all(),

                    'languages' =>
                        DB::table(
                            'nurselink_bulk_intake_languages'
                        )
                            ->where(
                                'candidate_id',
                                $candidate->id
                            )
                            ->where(
                                'review_status',
                                'approved'
                            )
                            ->orderBy('id')
                            ->get([
                                'language',
                                'speaking',
                                'reading',
                                'writing',
                                'review_notes',
                            ])
                            ->map(
                                fn ($row) =>
                                    (array) $row
                            )
                            ->values()
                            ->all(),

                    /*
                     * Third-party personal data remains private application
                     * evidence. It is not part of the public/member portfolio.
                     */
                    'references' =>
                        DB::table(
                            'nurselink_bulk_intake_references'
                        )
                            ->where(
                                'candidate_id',
                                $candidate->id
                            )
                            ->where(
                                'review_status',
                                'approved'
                            )
                            ->where(
                                'privacy_status',
                                'private'
                            )
                            ->orderBy('id')
                            ->get([
                                'name',
                                'position_title',
                                'organization',
                                'phone',
                                'email',
                                'reference_type',
                                'privacy_status',
                                'review_notes',
                            ])
                            ->map(
                                fn ($row) =>
                                    (array) $row
                            )
                            ->values()
                            ->all(),
                ];

                $profile[
                    '_bulk_professional_snapshot'
                ] = $professionalSnapshot;


                $application =
                    Application::query()->create([
                        'application_no' =>
                            $ids->next(
                                'application',
                                'NLA'
                            ),

                        'user_id' =>
                            $user->id,

                        'status' =>
                            'draft',

                        'progress_percent' =>
                            60,

                        'profile_data' =>
                            $profile,
                    ]);

                $photoDestination = null;

                if (
                    ! empty(
                        $candidate->photo_path
                    )
                    && (string) $candidate->photo_status
                        === 'approved'
                ) {
                    $source =
                        (string) $candidate->photo_path;

                    abort_unless(
                        Storage::disk('local')
                            ->exists($source),
                        422,
                        'Approved staged profile photo could not be found.'
                    );

                    $extension =
                        pathinfo(
                            $source,
                            PATHINFO_EXTENSION
                        );

                    $extension =
                        $extension !== ''
                            ? strtolower($extension)
                            : 'jpg';

                    $photoDestination =
                        'profile-photos/'
                        .$user->id
                        .'/'
                        .Str::uuid()
                        .'.'
                        .$extension;

                    $bytes =
                        Storage::disk('local')
                            ->get($source);

                    Storage::disk('public')
                        ->put(
                            $photoDestination,
                            $bytes
                        );

                    $user->forceFill([
                        'profile_photo_path' =>
                            $photoDestination,
                    ])->save();
                }

                DB::table(
                    'nurselink_bulk_intake_candidates'
                )
                    ->where(
                        'id',
                        $candidateId
                    )
                    ->update([
                        'import_status' =>
                            'imported_to_application',

                        'imported_at' =>
                            now(),

                        'imported_user_id' =>
                            $user->id,

                        'imported_application_id' =>
                            $application->id,

                        'imported_by_user_id' =>
                            $actor->getKey(),

                        'import_failure' =>
                            null,

                        'updated_at' =>
                            now(),
                    ]);

                /*
                 * Reuse the existing bulk audit table so the entire
                 * intake chain stays together.
                 */
                DB::table(
                    'nurselink_bulk_intake_audit'
                )
                    ->insert([
                        'batch_id' =>
                            $candidate->batch_id,

                        'candidate_id' =>
                            $candidateId,

                        'encoder_account_id' =>
                            null,

                        'admin_user_id' =>
                            $actor->getKey(),

                        'action' =>
                            'bulk.admin_imported_to_application',

                        'before_state' =>
                            json_encode(
                                [
                                    'import_status' =>
                                        'ready_for_admin_import',
                                ],
                                JSON_UNESCAPED_SLASHES
                            ),

                        'after_state' =>
                            json_encode(
                                [
                                    'import_status' =>
                                        'imported_to_application',

                                    'user_id' =>
                                        $user->id,

                                    'application_id' =>
                                        $application->id,

                                    'application_no' =>
                                        $application->application_no,

                                    'email_verified_at' =>
                                        null,

                                    'terms_accepted_at' =>
                                        null,

                                    'privacy_accepted_at' =>
                                        null,

                                    'member_created' =>
                                        false,

                                    'credential_verification_changed' =>
                                        false,

                                    'profile_photo_path' =>
                                        $photoDestination,
                                ],
                                JSON_UNESCAPED_SLASHES
                            ),

                        'ip_address' =>
                            $request->ip(),

                        'user_agent' =>
                            substr(
                                (string) $request->userAgent(),
                                0,
                                500
                            ),

                        'created_at' =>
                            now(),
                    ]);

                return [
                    'user' =>
                        $user,

                    'application' =>
                        $application,

                    'photo_path' =>
                        $photoDestination,
                ];
            },
            3
        );

        /*
         * Trigger the existing Laravel password-reset broker only
         * after the database transaction succeeds.
         *
         * Failure to send mail does not roll back a valid import;
         * the Administrator can resend later.
         */
        $passwordResetStatus =
            Password::sendResetLink([
                'email' =>
                    $result['user']->email,
            ]);

        return response()->json([
            'message' =>
                'Candidate imported as an unverified NurseLink applicant with a draft application.',

            'data' => [
                'user_id' =>
                    $result['user']->id,

                'email' =>
                    $result['user']->email,

                'email_verified' =>
                    false,

                'application_id' =>
                    $result['application']->id,

                'application_no' =>
                    $result['application']->application_no,

                'application_status' =>
                    $result['application']->status,

                'profile_photo_path' =>
                    $result['photo_path'],

                'password_setup_delivery' =>
                    $passwordResetStatus,
            ],

            'governance' => [
                'terms_accepted' =>
                    false,

                'privacy_accepted' =>
                    false,

                'member_created' =>
                    false,

                'membership_approved' =>
                    false,

                'credential_verification_changed' =>
                    false,

                'structured_professional_records_imported_to_member' =>
                    false,
            ],
        ], 201);
    }


    private function reviewCounts(
        string $table,
        int $candidateId
    ): array {
        if (! Schema::hasTable($table)) {
            return [
                'total' => 0,
                'approved' => 0,
                'rejected' => 0,
                'pending' => 0,
            ];
        }

        $base = DB::table($table)
            ->where(
                'candidate_id',
                $candidateId
            );

        return [
            'total' =>
                (clone $base)->count(),

            'approved' =>
                (clone $base)
                    ->where(
                        'review_status',
                        'approved'
                    )
                    ->count(),

            'rejected' =>
                (clone $base)
                    ->where(
                        'review_status',
                        'rejected'
                    )
                    ->count(),

            'pending' =>
                (clone $base)
                    ->where(
                        'review_status',
                        'review_required'
                    )
                    ->count(),
        ];
    }


    private function requireSuperAdministrator(
        Request $request
    ): void {
        $user = $request->user();

        abort_unless(
            $user,
            401
        );

        $userId =
            (string) $user->getKey();

        $granularSuperAdmin =
            Schema::hasTable(
                'nurselink_admin_role_assignments'
            )
            && DB::table(
                'nurselink_admin_role_assignments'
            )
                ->where(
                    'user_id',
                    $userId
                )
                ->where(
                    'active',
                    true
                )
                ->where(
                    'role_key',
                    'super_administrator'
                )
                ->exists();

        $legacySuperAdmin =
            method_exists(
                $user,
                'hasRole'
            )
            && $user->hasRole(
                'super_administrator'
            );

        abort_unless(
            $granularSuperAdmin
                || $legacySuperAdmin,
            403,
            'Super Administrator access is required for Bulk Nurse Import.'
        );
    }
}
