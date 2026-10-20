<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBulkNurseIntakeFile;
use App\Models\TemporaryEncoderAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TemporaryEncoderBulkIntakeController extends Controller
{
    public function index(): JsonResponse
    {
        $account = $this->encoder();

        $rows = DB::table(
            'nurselink_bulk_intake_batches'
        )
            ->where(
                'encoder_account_id',
                $account->id
            )
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function create(
        Request $request
    ): JsonResponse {
        $account = $this->encoder();

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:190',
            ],
        ]);

        $id = DB::table(
            'nurselink_bulk_intake_batches'
        )->insertGetId([
            'encoder_account_id' =>
                $account->id,
            'created_by_user_id' =>
                null,
            'name' =>
                trim($data['name']),
            'status' =>
                'draft',
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);

        $this->audit(
            $request,
            $account,
            $id,
            null,
            null,
            'bulk.batch_created',
            null,
            ['name' => trim($data['name'])]
        );

        return response()->json([
            'message' =>
                'Bulk Nurse Intake batch created.',
            'data' =>
                DB::table(
                    'nurselink_bulk_intake_batches'
                )
                    ->where('id', $id)
                    ->first(),
        ], 201);
    }

    public function show(
        int $batchId
    ): JsonResponse {
        $account = $this->encoder();

        $batch = $this->batch(
            $account,
            $batchId
        );

        $files = DB::table(
            'nurselink_bulk_intake_files'
        )
            ->where(
                'batch_id',
                $batchId
            )
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => [
                'batch' => $batch,
                'files' => $files,
            ],
        ]);
    }

    public function upload(
        Request $request,
        int $batchId
    ): JsonResponse {
        $account = $this->encoder();

        $batch = $this->batch(
            $account,
            $batchId
        );

        abort_if(
            in_array(
                $batch->status,
                [
                    'imported',
                    'cancelled',
                ],
                true
            ),
            422,
            'This batch is closed.'
        );

        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:15360',
                'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
            ],
        ]);

        $file = $data['file'];

        $sha256 = hash_file(
            'sha256',
            $file->getRealPath()
        );

        $duplicate = DB::table(
            'nurselink_bulk_intake_files'
        )
            ->where('batch_id', $batchId)
            ->where('sha256', $sha256)
            ->first();

        if ($duplicate) {
            /*
             * A roster may be deliberately re-uploaded after its column
             * mapping changes. Reuse the secured copy rather than storing
             * a duplicate, then refresh its generated review drafts.
             */
            if (in_array(strtolower((string) $duplicate->extension), ['xls', 'xlsx'], true)
                && DB::table('nurselink_bulk_intake_candidates')->where('batch_id', $batchId)->exists()) {
                ProcessBulkNurseIntakeFile::dispatch((int) $duplicate->id);

                return response()->json([
                    'message' => 'The existing spreadsheet is being reprocessed. Its generated candidate drafts will be replaced when processing completes.',
                    'data' => $duplicate,
                    'reused_existing_file' => true,
                ], 202);
            }

            return response()->json([
                'message' =>
                    'This exact document is already in the batch.',
                'data' =>
                    $duplicate,
            ], 409);
        }

        $extension = strtolower(
            $file->getClientOriginalExtension()
        );

        $safeName =
            Str::uuid()->toString()
            . '.'
            . $extension;

        $storagePath =
            'nurselink-bulk-intake/'
            . $batchId
            . '/'
            . $safeName;

        Storage::disk('local')->put(
            $storagePath,
            file_get_contents(
                $file->getRealPath()
            )
        );

        $id = DB::table(
            'nurselink_bulk_intake_files'
        )->insertGetId([
            'batch_id' =>
                $batchId,
            'original_name' =>
                $file->getClientOriginalName(),
            'storage_path' =>
                $storagePath,
            'mime_type' =>
                $file->getMimeType()
                ?: 'application/octet-stream',
            'file_size' =>
                $file->getSize(),
            'sha256' =>
                $sha256,
            'extension' =>
                $extension,
            'security_status' =>
                'pending',
            'extraction_status' =>
                'queued',
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);

        DB::table(
            'nurselink_bulk_intake_batches'
        )
            ->where('id', $batchId)
            ->update([
                'status' =>
                    'processing',
                'processing_started_at' =>
                    $batch->processing_started_at
                    ?: now(),
                'file_count' =>
                    DB::table(
                        'nurselink_bulk_intake_files'
                    )
                        ->where(
                            'batch_id',
                            $batchId
                        )
                        ->count(),
                'updated_at' =>
                    now(),
            ]);

        ProcessBulkNurseIntakeFile::dispatch(
            $id
        );

        $this->audit(
            $request,
            $account,
            $batchId,
            null,
            $id,
            'bulk.file_uploaded',
            null,
            [
                'original_name' =>
                    $file->getClientOriginalName(),
                'sha256' =>
                    $sha256,
            ]
        );

        return response()->json([
            'message' =>
                'Document uploaded and queued for secure extraction.',
            'data' =>
                DB::table(
                    'nurselink_bulk_intake_files'
                )
                    ->where('id', $id)
                    ->first(),
        ], 201);
    }

    public function group(
        Request $request,
        int $batchId,
        \App\Services\BulkIntake\BulkNurseIntakeIntelligenceService $intelligence
    ): JsonResponse {
        $account = $this->encoder();

        $batch = $this->batch(
            $account,
            $batchId
        );

        abort_if(
            (int) $batch->file_count === 0,
            422,
            'Upload at least one document before building nurse candidates.'
        );

        $unfinished = DB::table(
            'nurselink_bulk_intake_files'
        )
            ->where('batch_id', $batchId)
            ->whereIn(
                'extraction_status',
                [
                    'queued',
                    'processing',
                ]
            )
            ->count();

        abort_if(
            $unfinished > 0,
            422,
            'Document extraction is still running. Wait for all queued files to finish before grouping.'
        );

        $before = DB::table(
            'nurselink_bulk_intake_batches'
        )
            ->where('id', $batchId)
            ->first();

        $result = $intelligence->build(
            $batchId
        );

        $after = DB::table(
            'nurselink_bulk_intake_batches'
        )
            ->where('id', $batchId)
            ->first();

        $this->audit(
            $request,
            $account,
            $batchId,
            null,
            null,
            'bulk.candidates_built',
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Candidate record built from this batch for human review.',
            'data' =>
                $result,
        ]);
    }

    public function destroyFile(
        Request $request,
        int $batchId,
        int $fileId
    ): JsonResponse {
        $account = $this->encoder();
        $batch = $this->batch($account, $batchId);

        $hasCandidates = DB::table('nurselink_bulk_intake_candidates')
            ->where('batch_id', $batchId)
            ->exists();

        abort_if(
            $hasCandidates || (int) $batch->candidate_count > 0,
            422,
            'Documents cannot be removed after nurse candidates have been built. Create a new batch to change its source documents.'
        );

        $file = DB::table('nurselink_bulk_intake_files')
            ->where('id', $fileId)
            ->where('batch_id', $batchId)
            ->first();

        abort_unless($file, 404, 'Bulk Intake document not found.');

        $auditState = [
            'original_name' => $file->original_name,
            'sha256' => $file->sha256,
            'security_status' => $file->security_status,
            'extraction_status' => $file->extraction_status,
        ];

        Storage::disk('local')->delete($file->storage_path);

        DB::transaction(function () use (
            $request,
            $account,
            $batchId,
            $fileId,
            $auditState
        ): void {
            DB::table('nurselink_bulk_intake_files')
                ->where('id', $fileId)
                ->where('batch_id', $batchId)
                ->delete();

            $this->refreshBatchCounters($batchId);

            $this->audit(
                $request,
                $account,
                $batchId,
                null,
                $fileId,
                'bulk.file_removed',
                $auditState,
                null
            );
        });

        return response()->json([
            'message' => 'Document removed from this batch.',
        ]);
    }

    public function reprocessFile(
        Request $request,
        int $batchId,
        int $fileId
    ): JsonResponse {
        $account = $this->encoder();
        $batch = $this->batch($account, $batchId);

        abort_if(
            in_array($batch->status, ['imported', 'cancelled'], true),
            422,
            'This batch is closed.'
        );

        $submitted = DB::table('nurselink_bulk_intake_candidates')
            ->where('batch_id', $batchId)
            ->where('import_status', 'ready_for_admin_import')
            ->exists();

        abort_if(
            $submitted,
            422,
            'This candidate has already been submitted for Administrator Import and cannot be reprocessed.'
        );

        $file = DB::table('nurselink_bulk_intake_files')
            ->where('id', $fileId)
            ->where('batch_id', $batchId)
            ->first();

        abort_unless($file, 404, 'Bulk Intake document not found.');

        DB::table('nurselink_bulk_intake_files')
            ->where('id', $fileId)
            ->update([
                'document_type' => null,
                'security_status' => 'pending',
                'security_message' => 'Queued for reprocessing.',
                'security_scanned_at' => null,
                'extraction_status' => 'queued',
                'extraction_message' => 'Queued for reprocessing.',
                'extracted_text' => null,
                'extracted_fields' => json_encode([
                    '_document' => array_merge(
                        (json_decode((string)$file->extracted_fields, true) ?: [])['_document'] ?? [],
                        ['source_sha256' => $file->sha256]
                    ),
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                'extracted_at' => null,
                'updated_at' => now(),
            ]);

        $this->refreshBatchCounters($batchId);

        ProcessBulkNurseIntakeFile::dispatch($fileId);

        $this->audit(
            $request,
            $account,
            $batchId,
            null,
            $fileId,
            'bulk.file_reprocessing_requested',
            [
                'original_name' => $file->original_name,
                'sha256' => $file->sha256,
            ],
            null
        );

        return response()->json([
            'message' => 'Document reprocessing started. When processing finishes, build the candidate record again to refresh its detected fields.',
        ]);
    }

    public function previewFile(
        int $batchId,
        int $fileId
    ): BinaryFileResponse {
        $account = $this->encoder();
        $this->batch($account, $batchId);

        $file = DB::table('nurselink_bulk_intake_files')
            ->where('id', $fileId)
            ->where('batch_id', $batchId)
            ->first();

        abort_unless($file, 404, 'Bulk Intake document not found.');

        abort_unless(
            Storage::disk('local')->exists($file->storage_path),
            404,
            'The stored document is unavailable.'
        );

        $mime = Storage::disk('local')->mimeType($file->storage_path)
            ?: $file->mime_type
            ?: 'application/octet-stream';

        return response()->file(
            Storage::disk('local')->path($file->storage_path),
            [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="document"',
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function candidates(
        int $batchId,
        \App\Services\BulkIntake\BulkNurseIntakeIntelligenceService $intelligence
    ): JsonResponse {
        $account = $this->encoder();

        $this->batch(
            $account,
            $batchId
        );

        return response()->json([
            'data' =>
                $intelligence->summary(
                    $batchId
                ),
        ]);
    }

    public function readyForImport(
        Request $request,
        int $batchId,
        int $candidateId
    ): JsonResponse {
        $account = $this->encoder();

        $this->batch(
            $account,
            $batchId
        );

        $candidate = DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where(
                'id',
                $candidateId
            )
            ->where(
                'batch_id',
                $batchId
            )
            ->first();

        abort_unless(
            $candidate,
            404
        );

        /*
         * Candidate must have been explicitly human-reviewed first.
         */
        abort_unless(
            in_array(
                (string) $candidate->status,
                [
                    'reviewed',
                    'ready',
                ],
                true
            ),
            422,
            'Save the candidate review before submitting it for import.'
        );

        $email = mb_strtolower(
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
            'A valid email address is required before import.'
        );

        /*
         * Fresh duplicate check immediately before queueing.
         * This is deliberately separate from OCR-time matching.
         */
        $existingUser = DB::table(
            'users'
        )
            ->whereRaw(
                'LOWER(email) = ?',
                [$email]
            )
            ->first();

        if ($existingUser) {
            DB::table(
                'nurselink_bulk_intake_candidates'
            )
                ->where(
                    'id',
                    $candidateId
                )
                ->update([
                    'duplicate_status' =>
                        'conflict',
                    'matched_user_id' =>
                        (string) $existingUser->id,
                    'import_status' =>
                        'blocked_duplicate',
                    'import_failure' =>
                        'A NurseLink account with this email now exists. Administrator review is required.',
                    'updated_at' =>
                        now(),
                ]);

            return response()->json([
                'message' =>
                    'Import blocked because a matching NurseLink account now exists.',
                'duplicate' =>
                    true,
            ], 409);
        }

        /*
         * Every structured CV-derived record must receive an explicit
         * human decision before Administrator Import.
         *
         * Approved records continue to import staging.
         * Rejected records remain preserved for audit but are excluded
         * from the future permanent import mapping.
         */
        $structuredReviewTables = [
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

        $pendingStructured = [];

        foreach (
            $structuredReviewTables
            as $label => $table
        ) {
            $count = DB::table($table)
                ->where(
                    'candidate_id',
                    $candidateId
                )
                ->where(
                    'review_status',
                    'review_required'
                )
                ->count();

            if ($count > 0) {
                $pendingStructured[$label] =
                    $count;
            }
        }

        abort_if(
            $pendingStructured !== [],
            422,
            'Complete structured record review before submitting for Administrator Import. Pending: '
                . collect(
                    $pendingStructured
                )
                    ->map(
                        fn ($count, $label) =>
                            $label
                            . '='
                            . $count
                    )
                    ->implode(', ')
        );

        /*
         * Photo approval is optional, but an invalid state is not.
         */
        abort_if(
            ! empty($candidate->photo_path)
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
            'Review the extracted profile photo before submitting this candidate for import.'
        );

        $before = $candidate;

        DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where(
                'id',
                $candidateId
            )
            ->update([
                'import_status' =>
                    'ready_for_admin_import',
                'ready_for_import_at' =>
                    now(),
                'import_failure' =>
                    null,
                'updated_at' =>
                    now(),
            ]);

        $after = DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where(
                'id',
                $candidateId
            )
            ->first();

        $this->audit(
            $request,
            $account,
            $batchId,
            $candidateId,
            null,
            'bulk.candidate_ready_for_import',
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Candidate submitted to the Administrator Import Queue.',
            'data' =>
                $after,
            'governance' => [
                'permanent_account_created' =>
                    false,
                'membership_approved' =>
                    false,
                'administrator_import_required' =>
                    true,
            ],
        ]);
    }

    public function candidatePhoto(
        int $batchId,
        int $candidateId
    ): BinaryFileResponse {
        $account = $this->encoder();

        $this->batch(
            $account,
            $batchId
        );

        $candidate = DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where(
                'id',
                $candidateId
            )
            ->where(
                'batch_id',
                $batchId
            )
            ->first();

        abort_unless(
            $candidate,
            404
        );

        $path =
            $candidate->photo_path
            ?? null;

        abort_unless(
            is_string($path)
            && $path !== ''
            && Storage::disk(
                'local'
            )->exists($path),
            404
        );

        $absolute =
            Storage::disk(
                'local'
            )->path($path);

        return response()->file(
            $absolute,
            [
                'Content-Type' =>
                    Storage::disk(
                        'local'
                    )->mimeType($path)
                    ?: 'image/png',

                'Cache-Control' =>
                    'private, max-age=300',

                'X-Content-Type-Options' =>
                    'nosniff',
            ]
        );
    }

    public function updateCandidate(
        Request $request,
        int $batchId,
        int $candidateId
    ): JsonResponse {
        $account = $this->encoder();

        $this->batch(
            $account,
            $batchId
        );

        $before = DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where('id', $candidateId)
            ->where('batch_id', $batchId)
            ->first();

        abort_unless(
            $before,
            404,
            'Bulk Intake candidate not found.'
        );

        $data = $request->validate([
            'email' => [
                'nullable',
                'email',
                'max:255',
            ],
            'phone' => [
                'nullable',
                'string',
                'max:80',
            ],
            'first_name' => [
                'nullable',
                'string',
                'max:160',
            ],
            'second_name' => [
                'nullable',
                'string',
                'max:160',
            ],
            'middle_initial' => [
                'nullable',
                'string',
                'max:20',
            ],
            'middle_name' => [
                'nullable',
                'string',
                'max:160',
            ],
            'last_name' => [
                'nullable',
                'string',
                'max:160',
            ],
            'birth_date' => [
                'nullable',
                'date',
            ],
            'sex' => [
                'nullable',
                'string',
                'max:40',
            ],
            'nationality' => [
                'nullable',
                'string',
                'max:120',
            ],
            'address_line1' => [
                'nullable',
                'string',
                'max:255',
            ],
            'city' => [
                'nullable',
                'string',
                'max:160',
            ],
            'province' => [
                'nullable',
                'string',
                'max:160',
            ],
            'country' => [
                'nullable',
                'string',
                'max:120',
            ],
            'professional_title' => [
                'nullable',
                'string',
                'max:190',
            ],
            'years_experience' => [
                'nullable',
                'integer',
                'min:0',
                'max:80',
            ],
            'current_position' => [
                'nullable',
                'string',
                'max:190',
            ],
            'current_employer' => [
                'nullable',
                'string',
                'max:255',
            ],
            'specialty' => [
                'nullable',
                'string',
                'max:190',
            ],
            'primary_license_number' => [
                'nullable',
                'string',
                'max:190',
            ],
            'primary_license_country' => [
                'nullable',
                'string',
                'max:120',
            ],
            'primary_license_expiry' => [
                'nullable',
                'date',
            ],
            'highest_nursing_education' => [
                'nullable',
                'string',
                'max:255',
            ],
            'graduation_year' => [
                'nullable',
                'integer',
                'min:1900',
                'max:2100',
            ],
            'review_notes' => [
                'nullable',
                'string',
                'max:6000',
            ],
            'photo_status' => [
                'nullable',
                'in:staged,approved,rejected',
            ],
        ]);

        $firstName =
            $data['first_name']
            ?? $before->first_name
            ?? null;

        $secondName =
            $data['second_name']
            ?? $before->second_name
            ?? null;

        $middleInitial =
            $data['middle_initial']
            ?? $before->middle_initial
            ?? null;

        $lastName =
            $data['last_name']
            ?? $before->last_name
            ?? null;

        $legacyMiddleName =
            $data['middle_name']
            ?? $before->middle_name
            ?? null;

        /*
         * Keep legacy middle_name synchronized for older NurseLink
         * profile/application code, while structured components remain
         * authoritative inside Bulk Intake.
         */
        if (
            ! array_key_exists('middle_name', $data)
            && (array_key_exists('second_name', $data)
            || array_key_exists('middle_initial', $data))
        ) {
            $legacyParts = array_filter(
                [
                    $secondName,
                    $middleInitial,
                ],
                fn ($value) =>
                    $value !== null
                    && trim((string)$value) !== ''
            );

            $legacyMiddleName = implode(
                ' ',
                $legacyParts
            );

            $data['middle_name'] =
                $legacyMiddleName !== ''
                    ? $legacyMiddleName
                    : null;
        }

        $displayName = trim(
            implode(
                ' ',
                array_filter(
                    [
                        $firstName,
                        $secondName,
                        $middleInitial,
                        $lastName,
                    ],
                    fn ($value) =>
                        $value !== null
                        && trim((string)$value) !== ''
                )
            )
        );

        /*
         * Backward-compatible fallback for older candidates which have
         * only first/middle/last name.
         */
        if (
            $displayName === ''
            || (
                empty($secondName)
                && empty($middleInitial)
                && ! empty($legacyMiddleName)
            )
        ) {
            $displayName = trim(
                implode(
                    ' ',
                    array_filter(
                        [
                            $firstName,
                            $legacyMiddleName,
                            $lastName,
                        ],
                        fn ($value) =>
                            $value !== null
                            && trim((string)$value) !== ''
                    )
                )
            );
        }

        $displayName = preg_replace(
            '/\s+/u',
            ' ',
            $displayName
        ) ?: $before->display_name;

        $update = $data;

        $update['display_name'] =
            $displayName;

        /*
         * A human has now reviewed this candidate.
         * This still does NOT import or approve the nurse.
         */
        $update['status'] =
            'reviewed';

        $update['updated_at'] =
            now();

        DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where('id', $candidateId)
            ->where('batch_id', $batchId)
            ->update($update);

        $after = DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where('id', $candidateId)
            ->first();

        $this->audit(
            $request,
            $account,
            $batchId,
            $candidateId,
            null,
            'bulk.candidate_reviewed',
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Candidate corrections saved for review.',
            'data' =>
                $after,
        ]);
    }


    public function approveAllStructuredRecords(
        Request $request,
        int $batchId,
        int $candidateId
    ): JsonResponse {
        $account = $this->encoder();

        $this->batch(
            $account,
            $batchId
        );

        $this->bulkCandidate(
            $batchId,
            $candidateId
        );

        $tables = [
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

        $approved = [];

        DB::transaction(
            function () use (
                $request,
                $account,
                $batchId,
                $candidateId,
                $tables,
                &$approved
            ): void {
                foreach (
                    $tables
                    as $type => $table
                ) {
                    $rows = DB::table($table)
                        ->where(
                            'candidate_id',
                            $candidateId
                        )
                        ->where(
                            'review_status',
                            'review_required'
                        )
                        ->orderBy('id')
                        ->get();

                    $approved[$type] =
                        $rows->count();

                    foreach ($rows as $before) {
                        $update = [
                            'review_status' =>
                                'approved',

                            'updated_at' =>
                                now(),
                        ];

                        if (
                            $type === 'references'
                        ) {
                            $update['privacy_status'] =
                                'private';
                        }

                        DB::table($table)
                            ->where(
                                'id',
                                $before->id
                            )
                            ->where(
                                'candidate_id',
                                $candidateId
                            )
                            ->update(
                                $update
                            );

                        $after = DB::table($table)
                            ->where(
                                'id',
                                $before->id
                            )
                            ->first();

                        /*
                         * Important:
                         * credential verification_status is intentionally
                         * untouched. Human acceptance of OCR extraction is
                         * not credential verification.
                         */
                        $this->audit(
                            $request,
                            $account,
                            $batchId,
                            $candidateId,
                            null,
                            'bulk.structured_record_approved.'
                                .$type,
                            $before,
                            $after
                        );
                    }
                }
            }
        );

        return response()->json([
            'message' =>
                'All pending structured records approved for staging review.',

            'data' =>
                $approved,

            'governance' => [
                'credential_verification_changed' =>
                    false,

                'permanent_account_created' =>
                    false,

                'membership_approved' =>
                    false,
            ],
        ]);
    }

    public function rejectAllStructuredRecords(
        Request $request,
        int $batchId,
        int $candidateId
    ): JsonResponse {
        $account = $this->encoder();

        $this->batch($account, $batchId);
        $this->bulkCandidate($batchId, $candidateId);

        $data = $request->validate([
            'review_notes' => ['required', 'string', 'max:6000'],
        ]);

        $tables = [
            'education' => 'nurselink_bulk_intake_education',
            'employment' => 'nurselink_bulk_intake_employment',
            'credentials' => 'nurselink_bulk_intake_credentials',
            'competencies' => 'nurselink_bulk_intake_competencies',
            'languages' => 'nurselink_bulk_intake_languages',
            'references' => 'nurselink_bulk_intake_references',
        ];

        $rejected = [];

        DB::transaction(function () use ($request, $account, $batchId, $candidateId, $data, $tables, &$rejected): void {
            foreach ($tables as $type => $table) {
                $before = DB::table($table)
                    ->where('candidate_id', $candidateId)
                    ->where('review_status', 'review_required')
                    ->get();

                $rejected[$type] = $before->count();

                if ($before->isEmpty()) {
                    continue;
                }

                $update = [
                    'review_status' => 'rejected',
                    'review_notes' => trim($data['review_notes']),
                    'updated_at' => now(),
                ];

                if ($type === 'references') {
                    $update['privacy_status'] = 'private';
                }

                DB::table($table)
                    ->where('candidate_id', $candidateId)
                    ->where('review_status', 'review_required')
                    ->update($update);

                $this->audit(
                    $request,
                    $account,
                    $batchId,
                    $candidateId,
                    null,
                    'bulk.structured_records_rejected.'.$type,
                    ['count' => $before->count()],
                    ['count' => $before->count(), 'review_notes' => trim($data['review_notes'])]
                );
            }
        });

        return response()->json([
            'message' => 'All pending structured records rejected for staging review.',
            'data' => $rejected,
            'governance' => [
                'credential_verification_changed' => false,
                'permanent_account_created' => false,
                'membership_approved' => false,
            ],
        ]);
    }


    public function createStructuredRecord(
        Request $request,
        int $batchId,
        int $candidateId,
        string $type
    ): JsonResponse {
        $account = $this->encoder();

        $this->batch(
            $account,
            $batchId
        );

        $candidate = $this->bulkCandidate(
            $batchId,
            $candidateId
        );

        $config =
            $this->structuredRecordConfig(
                $type
            );

        $data = $request->validate(
            $config['rules']
        );

        $insert =
            $this->structuredRecordPayload(
                $type,
                $data
            );

        $insert['candidate_id'] =
            $candidateId;

        $insert['source_file_id'] =
            null;

        $insert['confidence'] =
            null;

        $insert['review_status'] =
            'approved';

        $insert['created_at'] =
            now();

        $insert['updated_at'] =
            now();

        /*
         * Manual credentials are human-entered but remain
         * unverified until NurseLink credential verification
         * is performed separately.
         */
        if ($type === 'credentials') {
            $insert['verification_status'] =
                'unverified';
        }

        if ($type === 'references') {
            $insert['privacy_status'] =
                'private';

            $insert['reference_type'] =
                $insert['reference_type']
                ?? 'professional_reference';
        }

        $id = DB::table(
            $config['table']
        )->insertGetId(
            $insert
        );

        $after = DB::table(
            $config['table']
        )
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            $account,
            $batchId,
            $candidateId,
            null,
            'bulk.structured_record_created.'
                .$type,
            null,
            $after
        );

        return response()->json([
            'message' =>
                'Structured record added.',
            'data' =>
                $after,
        ], 201);
    }


    public function updateStructuredRecord(
        Request $request,
        int $batchId,
        int $candidateId,
        string $type,
        int $recordId
    ): JsonResponse {
        $account = $this->encoder();

        $this->batch(
            $account,
            $batchId
        );

        $this->bulkCandidate(
            $batchId,
            $candidateId
        );

        $config =
            $this->structuredRecordConfig(
                $type
            );

        $before = DB::table(
            $config['table']
        )
            ->where(
                'id',
                $recordId
            )
            ->where(
                'candidate_id',
                $candidateId
            )
            ->first();

        abort_unless(
            $before,
            404,
            'Structured review record not found.'
        );

        $data = $request->validate(
            $config['rules']
        );

        $update =
            $this->structuredRecordPayload(
                $type,
                $data
            );

        /*
         * Editing means the human has actively reviewed
         * the corrected content.
         */
        $update['review_status'] =
            'approved';

        $update['updated_at'] =
            now();

        if ($type === 'references') {
            $update['privacy_status'] =
                'private';
        }

        /*
         * Never promote OCR/manual credentials to verified
         * through this review endpoint.
         */
        unset(
            $update[
                'verification_status'
            ]
        );

        DB::table(
            $config['table']
        )
            ->where(
                'id',
                $recordId
            )
            ->where(
                'candidate_id',
                $candidateId
            )
            ->update(
                $update
            );

        $after = DB::table(
            $config['table']
        )
            ->where(
                'id',
                $recordId
            )
            ->first();

        $this->audit(
            $request,
            $account,
            $batchId,
            $candidateId,
            null,
            'bulk.structured_record_updated.'
                .$type,
            $before,
            $after
        );

        return response()->json([
            'message' =>
                'Structured record updated and approved.',
            'data' =>
                $after,
        ]);
    }


    public function reviewStructuredRecord(
        Request $request,
        int $batchId,
        int $candidateId,
        string $type,
        int $recordId
    ): JsonResponse {
        $account = $this->encoder();

        $this->batch(
            $account,
            $batchId
        );

        $this->bulkCandidate(
            $batchId,
            $candidateId
        );

        $config =
            $this->structuredRecordConfig(
                $type
            );

        $before = DB::table(
            $config['table']
        )
            ->where(
                'id',
                $recordId
            )
            ->where(
                'candidate_id',
                $candidateId
            )
            ->first();

        abort_unless(
            $before,
            404,
            'Structured review record not found.'
        );

        $data = $request->validate([
            'status' => [
                'required',
                'in:approved,rejected',
            ],
            'review_notes' => [
                'nullable',
                'string',
                'max:6000',
            ],
        ]);

        $update = [
            'review_status' =>
                $data['status'],

            'updated_at' =>
                now(),
        ];

        if (
            array_key_exists(
                'review_notes',
                $data
            )
        ) {
            $update['review_notes'] =
                $data['review_notes'];
        }

        /*
         * Character references always remain private,
         * regardless of approval state.
         */
        if ($type === 'references') {
            $update['privacy_status'] =
                'private';
        }

        DB::table(
            $config['table']
        )
            ->where(
                'id',
                $recordId
            )
            ->where(
                'candidate_id',
                $candidateId
            )
            ->update(
                $update
            );

        $after = DB::table(
            $config['table']
        )
            ->where(
                'id',
                $recordId
            )
            ->first();

        $this->audit(
            $request,
            $account,
            $batchId,
            $candidateId,
            null,
            'bulk.structured_record_'
                .$data['status']
                .'.'
                .$type,
            $before,
            $after
        );

        return response()->json([
            'message' =>
                $data['status']
                    === 'approved'
                        ? 'Structured record approved.'
                        : 'Structured record rejected.',
            'data' =>
                $after,
        ]);
    }


    private function bulkCandidate(
        int $batchId,
        int $candidateId
    ): object {
        $candidate = DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where(
                'id',
                $candidateId
            )
            ->where(
                'batch_id',
                $batchId
            )
            ->first();

        abort_unless(
            $candidate,
            404,
            'Bulk Intake candidate not found.'
        );

        return $candidate;
    }

    private function refreshBatchCounters(int $batchId): void
    {
        $files = DB::table('nurselink_bulk_intake_files')
            ->where('batch_id', $batchId);

        $total = (clone $files)->count();

        $processed = (clone $files)
            ->whereIn('extraction_status', [
                'extracted',
                'needs_input',
                'blocked',
                'failed',
            ])
            ->count();

        $failed = (clone $files)
            ->whereIn('extraction_status', ['blocked', 'failed'])
            ->count();

        $status = $total === 0
            ? 'draft'
            : ($processed >= $total ? 'ready_for_grouping' : 'processing');

        DB::table('nurselink_bulk_intake_batches')
            ->where('id', $batchId)
            ->update([
                'file_count' => $total,
                'processed_count' => $processed,
                'failed_count' => $failed,
                'status' => $status,
                'processing_completed_at' =>
                    $status === 'ready_for_grouping' ? now() : null,
                'updated_at' => now(),
            ]);
    }


    private function structuredRecordConfig(
        string $type
    ): array {
        $configs = [
            'education' => [
                'table' =>
                    'nurselink_bulk_intake_education',

                'rules' => [
                    'qualification' => [
                        'required',
                        'string',
                        'max:180',
                    ],
                    'field_of_study' => [
                        'nullable',
                        'string',
                        'max:180',
                    ],
                    'institution' => [
                        'nullable',
                        'string',
                        'max:220',
                    ],
                    'country' => [
                        'nullable',
                        'string',
                        'max:120',
                    ],
                    'started_on' => [
                        'nullable',
                        'date',
                    ],
                    'completed_on' => [
                        'nullable',
                        'date',
                    ],
                    'review_notes' => [
                        'nullable',
                        'string',
                        'max:6000',
                    ],
                ],
            ],

            'employment' => [
                'table' =>
                    'nurselink_bulk_intake_employment',

                'rules' => [
                    'position_title' => [
                        'required',
                        'string',
                        'max:190',
                    ],
                    'employer' => [
                        'nullable',
                        'string',
                        'max:255',
                    ],
                    'facility_type' => [
                        'nullable',
                        'string',
                        'max:120',
                    ],
                    'country' => [
                        'nullable',
                        'string',
                        'max:120',
                    ],
                    'city' => [
                        'nullable',
                        'string',
                        'max:160',
                    ],
                    'started_on' => [
                        'nullable',
                        'date',
                    ],
                    'ended_on' => [
                        'nullable',
                        'date',
                    ],
                    'is_current' => [
                        'nullable',
                        'boolean',
                    ],
                    'responsibilities' => [
                        'nullable',
                        'string',
                        'max:12000',
                    ],
                    'review_notes' => [
                        'nullable',
                        'string',
                        'max:6000',
                    ],
                ],
            ],

            'credentials' => [
                'table' =>
                    'nurselink_bulk_intake_credentials',

                'rules' => [
                    'category' => [
                        'required',
                        'string',
                        'max:80',
                    ],
                    'credential_type' => [
                        'required',
                        'string',
                        'max:80',
                    ],
                    'title' => [
                        'required',
                        'string',
                        'max:220',
                    ],
                    'credential_number' => [
                        'nullable',
                        'string',
                        'max:190',
                    ],
                    'issuing_authority' => [
                        'nullable',
                        'string',
                        'max:220',
                    ],
                    'country' => [
                        'nullable',
                        'string',
                        'max:120',
                    ],
                    'issued_on' => [
                        'nullable',
                        'date',
                    ],
                    'expires_on' => [
                        'nullable',
                        'date',
                    ],
                    'review_notes' => [
                        'nullable',
                        'string',
                        'max:6000',
                    ],
                ],
            ],

            'competencies' => [
                'table' =>
                    'nurselink_bulk_intake_competencies',

                'rules' => [
                    'domain' => [
                        'nullable',
                        'string',
                        'max:120',
                    ],
                    'name' => [
                        'required',
                        'string',
                        'max:220',
                    ],
                    'proficiency' => [
                        'nullable',
                        'string',
                        'max:40',
                    ],
                    'review_notes' => [
                        'nullable',
                        'string',
                        'max:6000',
                    ],
                ],
            ],

            'languages' => [
                'table' =>
                    'nurselink_bulk_intake_languages',

                'rules' => [
                    'language' => [
                        'required',
                        'string',
                        'max:100',
                    ],
                    'speaking' => [
                        'nullable',
                        'string',
                        'max:40',
                    ],
                    'reading' => [
                        'nullable',
                        'string',
                        'max:40',
                    ],
                    'writing' => [
                        'nullable',
                        'string',
                        'max:40',
                    ],
                    'review_notes' => [
                        'nullable',
                        'string',
                        'max:6000',
                    ],
                ],
            ],

            'references' => [
                'table' =>
                    'nurselink_bulk_intake_references',

                'rules' => [
                    'name' => [
                        'required',
                        'string',
                        'max:180',
                    ],
                    'position_title' => [
                        'nullable',
                        'string',
                        'max:220',
                    ],
                    'organization' => [
                        'nullable',
                        'string',
                        'max:255',
                    ],
                    'phone' => [
                        'nullable',
                        'string',
                        'max:190',
                    ],
                    'email' => [
                        'nullable',
                        'email',
                        'max:255',
                    ],
                    'reference_type' => [
                        'nullable',
                        'string',
                        'max:80',
                    ],
                    'review_notes' => [
                        'nullable',
                        'string',
                        'max:6000',
                    ],
                ],
            ],
        ];

        abort_unless(
            isset(
                $configs[$type]
            ),
            404,
            'Unsupported structured record type.'
        );

        return $configs[$type];
    }


    private function structuredRecordPayload(
        string $type,
        array $data
    ): array {
        $allowed = [
            'education' => [
                'qualification',
                'field_of_study',
                'institution',
                'country',
                'started_on',
                'completed_on',
                'review_notes',
            ],

            'employment' => [
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
            ],

            'credentials' => [
                'category',
                'credential_type',
                'title',
                'credential_number',
                'issuing_authority',
                'country',
                'issued_on',
                'expires_on',
                'review_notes',
            ],

            'competencies' => [
                'domain',
                'name',
                'proficiency',
                'review_notes',
            ],

            'languages' => [
                'language',
                'speaking',
                'reading',
                'writing',
                'review_notes',
            ],

            'references' => [
                'name',
                'position_title',
                'organization',
                'phone',
                'email',
                'reference_type',
                'review_notes',
            ],
        ];

        $payload = [];

        foreach (
            $allowed[$type]
            as $field
        ) {
            if (
                array_key_exists(
                    $field,
                    $data
                )
            ) {
                $value =
                    $data[$field];

                if (
                    is_string($value)
                ) {
                    $value =
                        trim($value);

                    if ($value === '') {
                        $value = null;
                    }
                }

                $payload[$field] =
                    $value;
            }
        }

        return $payload;
    }


    private function batch(
        TemporaryEncoderAccount $account,
        int $batchId
    ): object {
        $batch = DB::table(
            'nurselink_bulk_intake_batches'
        )
            ->where('id', $batchId)
            ->where(
                'encoder_account_id',
                $account->id
            )
            ->first();

        abort_unless(
            $batch,
            404,
            'Bulk Nurse Intake batch not found.'
        );

        return $batch;
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

    private function audit(
        Request $request,
        TemporaryEncoderAccount $account,
        ?int $batchId,
        ?int $candidateId,
        ?int $fileId,
        string $action,
        mixed $before,
        mixed $after
    ): void {
        DB::table(
            'nurselink_bulk_intake_audit'
        )->insert([
            'batch_id' =>
                $batchId,
            'candidate_id' =>
                $candidateId,
            'file_id' =>
                $fileId,
            'encoder_account_id' =>
                $account->id,
            'admin_user_id' =>
                null,
            'action' =>
                $action,
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
                    (string) $request->userAgent(),
                    0,
                    2000
                ),
            'created_at' =>
                now(),
        ]);
    }
}
