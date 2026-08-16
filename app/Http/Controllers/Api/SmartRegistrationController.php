<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSmartRegistrationDocument;
use App\Services\MembershipLifecycleService;
use App\Services\SmartRegistration\LocalOcrService;
use App\Services\SmartRegistration\SmartRegistrationDocumentProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SmartRegistrationController extends Controller
{
    private const PROFILE_TABLE = 'nurselink_smart_registration_profiles';

    private const DOCUMENT_TABLE = 'nurselink_smart_registration_documents';

    private const MAX_EXTRACTED_TEXT = 120000;

    private const LOW_CONFIDENCE_THRESHOLD = 0.75;

    public function show(Request $request, LocalOcrService $ocr): JsonResponse
    {
        $user = $request->user();
        $profile = $this->ensureProfile($user);
        $membership = $this->ensureDraftMembership($user);
        $documents = $this->documentsFor($user->getKey());
        $suggestions = $this->mergeSuggestions($profile, $documents, $user);
        $missing = $this->missingFields($profile, $documents, $user);
        $fieldStatuses = $this->fieldStatuses($profile, $suggestions, $missing, $user);

        return response()->json([
            'data' => [
                'profile' => $this->presentProfile($profile, $user),
                'documents' => $documents,
                'suggestions' => $suggestions,
                'field_statuses' => $fieldStatuses,
                'missing' => $missing,
                'completion' => $this->completion($missing),
                'membership' => $this->presentMembership($membership),
                'extraction_capabilities' => [
                    ...$ocr->capabilities(),
                    'note' => 'Automatic extraction assists data entry only. Applicants must review and confirm extracted values; credential verification remains reviewer-controlled.',
                ],
            ],
        ]);
    }

    public function upload(Request $request, SmartRegistrationDocumentProcessor $processor): JsonResponse
    {
        $user = $request->user();
        $this->assertEditableMembership($user);
        $request->validate([
            'document' => [
                'required',
                'file',
                'max:15360',
                'mimes:pdf,jpg,jpeg,png,doc,docx',
            ],
            'replace_document_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $replacement = null;
        $replaceDocumentId = (int) ($request->input('replace_document_id') ?: 0);

        if ($replaceDocumentId > 0) {
            $replacement = DB::table(self::DOCUMENT_TABLE)
                ->where('id', $replaceDocumentId)
                ->where('user_id', $user->getKey())
                ->first();

            abort_unless($replacement, 404, 'The document to replace was not found.');

            if (Schema::hasColumn(self::DOCUMENT_TABLE, 'is_current')) {
                abort_unless((bool) $replacement->is_current, 422, 'Only the current version of a document can be replaced.');
            }
        }

        $file = $request->file('document');
        abort_unless($file && $file->isValid(), 422, 'The uploaded document is invalid.');

        $userId = (string) $user->getKey();
        $sha = hash_file('sha256', $file->getRealPath());

        $duplicate = DB::table(self::DOCUMENT_TABLE)
            ->where('user_id', $user->getKey())
            ->where('sha256', $sha)
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' => 'This document is already in your Smart Registration file.',
                'data' => $this->presentDocument($duplicate),
            ]);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $safeExtension = in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], true)
            ? $extension
            : 'bin';
        $storedName = Str::uuid()->toString().'.'.$safeExtension;
        $directory = 'nurselink-smart-registration/'.preg_replace('/[^A-Za-z0-9._-]/', '_', $userId);
        $storagePath = $file->storeAs($directory, $storedName, 'local');

        if (! $storagePath) {
            abort(500, 'Unable to store the uploaded document securely.');
        }

        $insert = [
            'user_id' => $user->getKey(),
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'storage_path' => $storagePath,
            'mime_type' => mb_substr((string) ($file->getMimeType() ?: $file->getClientMimeType()), 0, 160),
            'file_size' => (int) $file->getSize(),
            'sha256' => $sha,
            'document_type' => 'other',
            'extraction_status' => 'queued',
            'extraction_message' => 'Document queued for automatic extraction.',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn(self::DOCUMENT_TABLE, 'security_status')) {
            $insert['security_status'] = 'pending';
            $insert['security_message'] = 'Document is waiting for security scanning.';
        }

        if (Schema::hasColumn(self::DOCUMENT_TABLE, 'version')) {
            $insert['version'] = $replacement
                ? max(2, ((int) ($replacement->version ?? 1)) + 1)
                : 1;
        }
        if (Schema::hasColumn(self::DOCUMENT_TABLE, 'is_current')) {
            $insert['is_current'] = true;
        }
        if (Schema::hasColumn(self::DOCUMENT_TABLE, 'replaces_document_id')) {
            $insert['replaces_document_id'] = $replacement ? (int) $replacement->id : null;
        }

        $id = DB::table(self::DOCUMENT_TABLE)->insertGetId($insert);

        if ($replacement && Schema::hasColumn(self::DOCUMENT_TABLE, 'is_current')) {
            $replacementUpdate = [
                'is_current' => false,
                'updated_at' => now(),
            ];
            if (Schema::hasColumn(self::DOCUMENT_TABLE, 'replaced_by_document_id')) {
                $replacementUpdate['replaced_by_document_id'] = $id;
            }
            if (Schema::hasColumn(self::DOCUMENT_TABLE, 'replaced_at')) {
                $replacementUpdate['replaced_at'] = now();
            }

            DB::table(self::DOCUMENT_TABLE)
                ->where('id', $replacement->id)
                ->where('user_id', $user->getKey())
                ->update($replacementUpdate);
        }

        if ((bool) config('smart_registration.queue.enabled', true)) {
            ProcessSmartRegistrationDocument::dispatch($id);
        } else {
            $processor->process($id);
        }

        $updated = DB::table(self::DOCUMENT_TABLE)->where('id', $id)->first();

        return response()->json([
            'message' => in_array($updated->extraction_status, ['queued', 'processing'], true)
                ? 'Document uploaded and queued for automatic extraction.'
                : 'Document uploaded. Review the extracted information and complete any missing fields.',
            'data' => $this->presentDocument($updated),
        ], 201);
    }

    public function destroyDocument(Request $request, int $id): JsonResponse
    {
        $this->assertEditableMembership($request->user());

        $row = DB::table(self::DOCUMENT_TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->first();

        abort_unless($row, 404);

        if (Schema::hasColumn(self::DOCUMENT_TABLE, 'is_current')) {
            abort_unless((bool) $row->is_current, 422, 'A superseded document version cannot be removed directly.');
        }

        if ($row->storage_path) {
            Storage::disk('local')->delete($row->storage_path);
        }

        DB::table(self::DOCUMENT_TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->delete();

        if (
            Schema::hasColumn(self::DOCUMENT_TABLE, 'replaces_document_id')
            && ! empty($row->replaces_document_id)
        ) {
            $restore = ['updated_at' => now()];
            if (Schema::hasColumn(self::DOCUMENT_TABLE, 'is_current')) {
                $restore['is_current'] = true;
            }
            if (Schema::hasColumn(self::DOCUMENT_TABLE, 'replaced_by_document_id')) {
                $restore['replaced_by_document_id'] = null;
            }
            if (Schema::hasColumn(self::DOCUMENT_TABLE, 'replaced_at')) {
                $restore['replaced_at'] = null;
            }

            DB::table(self::DOCUMENT_TABLE)
                ->where('id', $row->replaces_document_id)
                ->where('user_id', $request->user()->getKey())
                ->update($restore);
        }

        return response()->json(['message' => 'Document removed.']);
    }

    public function savePersonal(Request $request): JsonResponse
    {
        $this->assertEditableMembership($request->user());

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'birth_date' => ['required', 'date', 'before:today'],
            'sex' => ['nullable', 'string', Rule::in(['female', 'male', 'non_binary', 'prefer_not_to_say', 'other'])],
            'nationality' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:80'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'province' => ['nullable', 'string', 'max:120'],
            'country' => ['required', 'string', 'max:120'],
            'sources' => ['nullable', 'array'],
        ]);

        $profile = $this->updateProfile($request, $data, 'personal');
        $this->syncCoreUser($request->user(), $data);

        return response()->json([
            'message' => 'Personal information saved.',
            'data' => $this->presentProfile($profile, $request->user()),
        ]);
    }

    public function saveProfessional(Request $request): JsonResponse
    {
        $this->assertEditableMembership($request->user());

        $data = $request->validate([
            'professional_title' => ['required', 'string', 'max:150'],
            'years_experience' => ['required', 'integer', 'min:0', 'max:80'],
            'current_position' => ['nullable', 'string', 'max:150'],
            'current_employer' => ['nullable', 'string', 'max:190'],
            'specialty' => ['nullable', 'string', 'max:150'],
            'primary_license_number' => ['nullable', 'string', 'max:160'],
            'primary_license_country' => ['nullable', 'string', 'max:120'],
            'primary_license_expiry' => ['nullable', 'date'],
            'highest_nursing_education' => ['required', 'string', 'max:190'],
            'graduation_year' => ['nullable', 'integer', 'min:1940', 'max:'.(now()->year + 1)],
            'sources' => ['nullable', 'array'],
        ]);

        $profile = $this->updateProfile($request, $data, 'professional');
        $this->syncStructuredCredential($request->user(), $data);

        return response()->json([
            'message' => 'Professional information saved.',
            'data' => $this->presentProfile($profile, $request->user()),
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->ensureProfile($user);
        $documents = $this->documentsFor($user->getKey());
        $missing = $this->missingFields($profile, $documents, $user);

        if ($missing !== []) {
            return response()->json([
                'message' => 'Please complete the missing information before submitting.',
                'missing' => $missing,
            ], 422);
        }

        $membership = $this->ensureDraftMembership($user);

        if ($membership->status === 'approved') {
            return response()->json([
                'message' => 'Your NurseLink membership is already approved.',
                'data' => $this->presentMembership($membership),
            ]);
        }

        if ($membership->status === 'declined') {
            return response()->json([
                'message' => 'This application has a completed decision. Contact NurseLink Support if you need the application reopened.',
            ], 422);
        }

        $isResubmission = $membership->status === 'needs_information';
        $allowed = ['draft', 'needs_information'];

        if (! in_array($membership->status, $allowed, true)) {
            return response()->json([
                'message' => $membership->status === 'submitted'
                    ? 'Your application has already been submitted and is waiting for review.'
                    : 'Your application is already in active review and cannot be resubmitted right now.',
                'status' => $membership->status,
            ], 422);
        }

        $update = [
            'status' => 'submitted',
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('nurselink_memberships', 'submitted_at')) {
            $update['submitted_at'] = $membership->submitted_at ?: now();
        }

        if ($isResubmission && Schema::hasColumn('nurselink_memberships', 'resubmitted_at')) {
            $update['resubmitted_at'] = now();
        }

        if ($isResubmission) {
            $update['reviewed_by'] = null;
            $update['reviewed_at'] = null;
        }

        $beforeStatus = (string) $membership->status;
        $event = $isResubmission ? 'resubmitted' : 'submitted';
        $historyReason = $isResubmission
            ? 'Applicant resubmitted requested information.'
            : 'Applicant completed Smart Registration and submitted the application.';

        $after = DB::transaction(function () use ($membership, $beforeStatus, $update, $event, $historyReason, $user): object {
            $locked = DB::table('nurselink_memberships')
                ->where('id', $membership->id)
                ->lockForUpdate()
                ->first();

            abort_unless($locked, 404);
            abort_if(
                (string) $locked->status !== $beforeStatus,
                409,
                'Your membership application changed in another session. Refresh Smart Registration and try again.'
            );

            $safeUpdate = $update;
            if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_at')) {
                $safeUpdate['last_status_changed_at'] = now();
            }
            if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_by')) {
                $safeUpdate['last_status_changed_by'] = (string) $membership->user_id;
            }

            DB::table('nurselink_memberships')
                ->where('id', $membership->id)
                ->update($safeUpdate);

            $after = DB::table('nurselink_memberships')->where('id', $membership->id)->first();
            app(MembershipLifecycleService::class)->recordTransition(
                $after,
                $beforeStatus,
                'submitted',
                (string) $user->getKey(),
                'applicant',
                $historyReason,
                ['event' => $event, 'applicant_visible_reason' => $historyReason]
            );

            return $after;
        });

        $lifecycle = app(MembershipLifecycleService::class);
        $delivery = $lifecycle->notifyEvent($after, $event);

        return response()->json([
            'message' => $isResubmission
                ? 'Application resubmitted for review.'
                : 'Application submitted for review.',
            'data' => $this->presentMembership($after),
            'notification_delivery' => $delivery,
        ]);
    }

    private function assertEditableMembership(object $user): void
    {
        $membership = $this->ensureDraftMembership($user);

        if (in_array($membership->status, ['draft', 'needs_information'], true)) {
            return;
        }

        $messages = [
            'submitted' => 'Your application has been submitted and is locked while waiting for review.',
            'under_review' => 'Your application is currently under review and cannot be changed unless a reviewer requests more information.',
            'ready_for_approval' => 'Your application is awaiting final approval and cannot be changed.',
            'approved' => 'Your membership is approved. Use your member profile to update information.',
            'declined' => 'This application has a completed decision. Contact NurseLink Support if it needs to be reopened.',
        ];

        abort(422, $messages[$membership->status] ?? 'This application cannot be changed in its current status.');
    }

    private function updateProfile(Request $request, array $data, string $section): object
    {
        $sources = $data['sources'] ?? [];
        unset($data['sources']);

        $existing = $this->ensureProfile($request->user());
        $confirmed = $this->decodeJson($existing->confirmed_sources ?? null);
        $confirmed[$section] = is_array($sources) ? $sources : [];

        $data['confirmed_sources'] = json_encode($confirmed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data['updated_at'] = now();

        DB::table(self::PROFILE_TABLE)
            ->where('user_id', $request->user()->getKey())
            ->update($data);

        return DB::table(self::PROFILE_TABLE)
            ->where('user_id', $request->user()->getKey())
            ->first();
    }

    private function ensureProfile(object $user): object
    {
        $existing = DB::table(self::PROFILE_TABLE)
            ->where('user_id', $user->getKey())
            ->first();

        if ($existing) {
            return $existing;
        }

        $defaults = $this->coreUserDefaults($user);
        $id = DB::table(self::PROFILE_TABLE)->insertGetId([
            'user_id' => $user->getKey(),
            ...$defaults,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table(self::PROFILE_TABLE)->where('id', $id)->first();
    }

    private function ensureDraftMembership(object $user): object
    {
        $existing = DB::table('nurselink_memberships')
            ->where('user_id', $user->getKey())
            ->first();

        if ($existing) {
            return $existing;
        }

        $coreMemberNumber = Schema::hasColumn('users', 'member_number')
            ? trim((string) ($user->member_number ?? ''))
            : '';

        $approved = $coreMemberNumber !== '';

        $insert = [
            'user_id' => $user->getKey(),
            'status' => $approved ? 'approved' : 'draft',
            'member_number' => $approved ? $coreMemberNumber : null,
            'verification_code' => $approved ? Str::lower(Str::random(40)) : null,
            'approved_at' => $approved ? now() : null,
            'standing' => $approved ? 'active' : null,
            'standing_changed_at' => $approved ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_at')) {
            $insert['last_status_changed_at'] = now();
        }
        if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_by')) {
            $insert['last_status_changed_by'] = (string) $user->getKey();
        }

        $id = DB::table('nurselink_memberships')->insertGetId($insert);

        $created = DB::table('nurselink_memberships')->where('id', $id)->first();
        app(MembershipLifecycleService::class)->recordTransition(
            $created,
            null,
            (string) $created->status,
            $approved ? null : (string) $user->getKey(),
            $approved ? 'system' : 'applicant',
            $approved ? 'Existing core member synchronized into the membership lifecycle.' : 'Smart Registration draft created.',
            ['applicant_visible_reason' => $approved
                ? 'Existing NurseLink membership recognized.'
                : 'Smart Registration draft created.']
        );

        return $created;
    }

    private function coreUserDefaults(object $user): array
    {
        $name = trim((string) ($user->name ?? ''));
        $first = trim((string) ($user->first_name ?? ''));
        $last = trim((string) ($user->last_name ?? ''));

        if (($first === '' || $last === '') && $name !== '') {
            $parts = preg_split('/\s+/', $name) ?: [];
            if ($first === '' && $parts !== []) {
                $first = (string) array_shift($parts);
            }
            if ($last === '' && $parts !== []) {
                $last = (string) array_pop($parts);
            }
        }

        return [
            'first_name' => $first !== '' ? $first : null,
            'last_name' => $last !== '' ? $last : null,
            'phone' => $this->coreColumnValue($user, ['phone', 'phone_number', 'mobile', 'mobile_number']),
            'city' => $this->coreColumnValue($user, ['city']),
            'province' => $this->coreColumnValue($user, ['province', 'state']),
            'country' => $this->coreColumnValue($user, ['country']) ?: 'Philippines',
            'professional_title' => $this->coreColumnValue($user, ['professional_title', 'title']),
            'current_position' => $this->coreColumnValue($user, ['current_position', 'position']),
            'current_employer' => $this->coreColumnValue($user, ['current_employer', 'employer']),
        ];
    }

    private function coreColumnValue(object $user, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn('users', $column)) {
                $value = trim((string) ($user->{$column} ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function syncCoreUser(object $user, array $data): void
    {
        $mapping = [
            'first_name' => ['first_name'],
            'last_name' => ['last_name'],
            'phone' => ['phone', 'phone_number', 'mobile', 'mobile_number'],
            'city' => ['city'],
            'province' => ['province', 'state'],
            'country' => ['country'],
        ];

        $updates = [];

        foreach ($mapping as $source => $targets) {
            foreach ($targets as $target) {
                if (array_key_exists($source, $data) && Schema::hasColumn('users', $target)) {
                    $updates[$target] = $data[$source];
                    break;
                }
            }
        }

        if (Schema::hasColumn('users', 'name')) {
            $updates['name'] = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
        }

        if ($updates !== []) {
            if (Schema::hasColumn('users', 'updated_at')) {
                $updates['updated_at'] = now();
            }
            DB::table('users')->where('id', $user->getKey())->update($updates);
        }
    }

    private function syncStructuredCredential(object $user, array $data): void
    {
        if (! Schema::hasTable('nurselink_credentials_registry')) {
            return;
        }

        $license = trim((string) ($data['primary_license_number'] ?? ''));
        $education = trim((string) ($data['highest_nursing_education'] ?? ''));

        if ($license !== '') {
            $licenseCountry = trim((string) ($data['primary_license_country'] ?? ''));
            $credentialType = mb_strtolower($licenseCountry) === 'philippines' ? 'prc_license' : 'international_license';

            $existing = DB::table('nurselink_credentials_registry')
                ->where('user_id', $user->getKey())
                ->where('credential_type', $credentialType)
                ->where('credential_number', $license)
                ->first();

            if (! $existing) {
                DB::table('nurselink_credentials_registry')->insert([
                    'user_id' => $user->getKey(),
                    'credential_type' => $credentialType,
                    'title' => 'Primary Nursing License',
                    'issuing_body' => $credentialType === 'prc_license' ? 'Professional Regulation Commission' : 'Professional nursing regulator',
                    'credential_number' => $license,
                    'country' => $licenseCountry !== '' ? $licenseCountry : null,
                    'issue_date' => null,
                    'expiry_date' => $data['primary_license_expiry'] ?? null,
                    'verification_status' => 'unverified',
                    'notes' => 'Created from applicant-confirmed Smart Registration information.',
                    'review_notes' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if ($education !== '') {
            $exists = DB::table('nurselink_credentials_registry')
                ->where('user_id', $user->getKey())
                ->where('credential_type', 'nursing_diploma')
                ->where('title', $education)
                ->exists();

            if (! $exists) {
                DB::table('nurselink_credentials_registry')->insert([
                    'user_id' => $user->getKey(),
                    'credential_type' => 'nursing_diploma',
                    'title' => $education,
                    'issuing_body' => null,
                    'credential_number' => null,
                    'country' => null,
                    'issue_date' => ! empty($data['graduation_year']) ? $data['graduation_year'].'-01-01' : null,
                    'expiry_date' => null,
                    'verification_status' => 'unverified',
                    'notes' => 'Created from applicant-confirmed Smart Registration information.',
                    'review_notes' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function documentsFor(mixed $userId): array
    {
        $query = DB::table(self::DOCUMENT_TABLE)
            ->where('user_id', $userId);

        if (Schema::hasColumn(self::DOCUMENT_TABLE, 'is_current')) {
            $query->where('is_current', true);
        }

        return $query
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => $this->presentDocument($row))
            ->values()
            ->all();
    }

    private function presentDocument(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'name' => $row->original_name,
            'mime_type' => $row->mime_type,
            'file_size' => (int) $row->file_size,
            'document_type' => $row->document_type,
            'security_status' => $row->security_status ?? 'unknown',
            'security_message' => $row->security_message ?? null,
            'security_scanned_at' => $row->security_scanned_at ?? null,
            'extraction_status' => $row->extraction_status,
            'extracted_fields' => $this->decodeJson($row->extracted_fields ?? null),
            'extraction_message' => $row->extraction_message,
            'version' => isset($row->version) ? (int) $row->version : 1,
            'is_current' => isset($row->is_current) ? (bool) $row->is_current : true,
            'replaces_document_id' => isset($row->replaces_document_id) && $row->replaces_document_id !== null ? (int) $row->replaces_document_id : null,
            'created_at' => $row->created_at,
        ];
    }

    private function presentProfile(object $row, object $user): array
    {
        return [
            'first_name' => $row->first_name,
            'middle_name' => $row->middle_name,
            'last_name' => $row->last_name,
            'birth_date' => $row->birth_date,
            'sex' => $row->sex,
            'nationality' => $row->nationality,
            'email' => (string) ($user->email ?? ''),
            'phone' => $row->phone,
            'address_line1' => $row->address_line1,
            'city' => $row->city,
            'province' => $row->province,
            'country' => $row->country,
            'professional_title' => $row->professional_title,
            'years_experience' => $row->years_experience !== null ? (int) $row->years_experience : null,
            'current_position' => $row->current_position,
            'current_employer' => $row->current_employer,
            'specialty' => $row->specialty,
            'primary_license_number' => $row->primary_license_number,
            'primary_license_country' => $row->primary_license_country,
            'primary_license_expiry' => $row->primary_license_expiry,
            'highest_nursing_education' => $row->highest_nursing_education,
            'graduation_year' => $row->graduation_year !== null ? (int) $row->graduation_year : null,
            'confirmed_sources' => $this->decodeJson($row->confirmed_sources ?? null),
            'last_extracted_at' => $row->last_extracted_at,
        ];
    }

    private function presentMembership(object $row): array
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
            'submitted_at' => $row->submitted_at ?? null,
            'resubmitted_at' => $row->resubmitted_at ?? null,
            'reviewer_notes' => $applicantReason,
            'member_number' => $row->member_number ?? null,
            'approved_at' => $row->approved_at ?? null,
            'last_status_changed_at' => $row->last_status_changed_at ?? null,
            'status_history' => app(MembershipLifecycleService::class)
                ->historyForApplicant((int) $row->id),
        ];
    }

    private function missingFields(object $profile, array $documents, object $user): array
    {
        $required = [
            ['field' => 'first_name', 'label' => 'First name', 'step' => 2],
            ['field' => 'last_name', 'label' => 'Last name', 'step' => 2],
            ['field' => 'birth_date', 'label' => 'Date of birth', 'step' => 2],
            ['field' => 'nationality', 'label' => 'Nationality', 'step' => 2],
            ['field' => 'phone', 'label' => 'Mobile number', 'step' => 2],
            ['field' => 'country', 'label' => 'Country of residence', 'step' => 2],
            ['field' => 'professional_title', 'label' => 'Professional title', 'step' => 3],
            ['field' => 'years_experience', 'label' => 'Years of nursing experience', 'step' => 3],
            ['field' => 'highest_nursing_education', 'label' => 'Highest nursing education', 'step' => 3],
        ];

        $missing = [];

        foreach ($required as $item) {
            $value = $profile->{$item['field']} ?? null;
            if ($value === null || trim((string) $value) === '') {
                $missing[] = $item;
            }
        }

        $hasEmail = trim((string) ($user->email ?? '')) !== '';
        if (! $hasEmail) {
            $missing[] = ['field' => 'email', 'label' => 'Email address', 'step' => 2];
        }

        $evidenceTypes = ['prc_license', 'international_license', 'nursing_diploma', 'identity', 'cv'];
        $hasEvidence = collect($documents)->contains(fn ($doc) => in_array($doc['document_type'] ?? '', $evidenceTypes, true));

        if (! $hasEvidence && trim((string) ($profile->primary_license_number ?? '')) === '') {
            $missing[] = [
                'field' => 'professional_evidence',
                'label' => 'Nursing credential, diploma, license, CV, or identity document',
                'step' => 1,
            ];
        }

        return $missing;
    }

    private function completion(array $missing): int
    {
        $total = 10;
        $remaining = min($total, count($missing));

        return (int) round((($total - $remaining) / $total) * 100);
    }

    private function mergeSuggestions(object $profile, array $documents, object $user): array
    {
        $candidates = [];

        foreach ($documents as $document) {
            foreach (($document['extracted_fields'] ?? []) as $field => $candidate) {
                if (! is_array($candidate) || trim((string) ($candidate['value'] ?? '')) === '') {
                    continue;
                }

                $confidence = (float) ($candidate['confidence'] ?? 0.5);
                $value = $candidate['value'];
                $key = $this->comparableValue($value);
                if ($key === '') {
                    continue;
                }

                $existing = $candidates[$field][$key] ?? null;
                if ($existing && $confidence <= (float) $existing['confidence']) {
                    continue;
                }

                $candidates[$field][$key] = [
                    'value' => $value,
                    'confidence' => round($confidence, 2),
                    'source_document_id' => $document['id'],
                    'source_name' => $document['name'],
                    'source_type' => $document['document_type'],
                ];
            }
        }

        $current = $this->presentProfile($profile, $user);
        $best = [];

        foreach ($candidates as $field => $distinct) {
            $ranked = array_values($distinct);
            usort($ranked, fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);
            $candidate = $ranked[0];
            $existing = $current[$field] ?? null;
            $candidate['already_saved'] = $existing !== null && trim((string) $existing) !== '';
            $candidate['conflict'] = count($ranked) > 1;
            $candidate['low_confidence'] = (float) $candidate['confidence'] < self::LOW_CONFIDENCE_THRESHOLD;
            $candidate['status'] = ($candidate['conflict'] || $candidate['low_confidence'])
                ? 'needs_review'
                : 'extracted';
            $candidate['alternatives'] = $ranked;
            $best[$field] = $candidate;
        }

        return $best;
    }

    private function fieldStatuses(object $profile, array $suggestions, array $missing, object $user): array
    {
        $current = $this->presentProfile($profile, $user);
        $confirmed = [];

        foreach ($this->decodeJson($profile->confirmed_sources ?? null) as $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach ($section as $field => $source) {
                $confirmed[$field] = $source;
            }
        }

        $missingNames = array_fill_keys(array_map(
            fn (array $item): string => (string) ($item['field'] ?? ''),
            $missing
        ), true);

        $fields = array_values(array_unique(array_merge(
            array_keys($current),
            array_keys($suggestions),
            array_keys($missingNames)
        )));
        $statuses = [];

        foreach ($fields as $field) {
            if ($field === 'confirmed_sources' || $field === 'last_extracted_at') {
                continue;
            }
            $value = $current[$field] ?? null;
            $hasValue = $value !== null && trim((string) $value) !== '';
            $suggestion = $suggestions[$field] ?? null;

            if (isset($confirmed[$field])) {
                $status = 'applicant_confirmed';
            } elseif ($hasValue) {
                $status = 'applicant_provided';
            } elseif (($suggestion['conflict'] ?? false) === true || ($suggestion['low_confidence'] ?? false) === true) {
                $status = 'needs_review';
            } elseif ($suggestion) {
                $status = 'extracted';
            } else {
                $status = 'missing';
            }

            $statuses[$field] = [
                'status' => $status,
                'required' => isset($missingNames[$field]),
                'confirmed_source' => $confirmed[$field] ?? null,
                'conflict' => (bool) ($suggestion['conflict'] ?? false),
                'low_confidence' => (bool) ($suggestion['low_confidence'] ?? false),
            ];
        }

        return $statuses;
    }

    private function comparableValue(mixed $value): string
    {
        $normalized = mb_strtolower(trim((string) $value));

        return preg_replace('/[^\pL\pN]+/u', '', $normalized) ?? $normalized;
    }

    private function extractDocument(object $row): array
    {
        $absolute = Storage::disk('local')->path($row->storage_path);
        $name = (string) $row->original_name;
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $text = '';
        $message = '';

        try {
            if ($extension === 'docx') {
                $text = $this->extractDocxText($absolute);
                $message = $text !== '' ? 'Text extracted from DOCX.' : 'No readable DOCX text was detected.';
            } elseif ($extension === 'doc') {
                $text = $this->extractLegacyDocText($absolute);
                $message = $text !== ''
                    ? 'Text extracted from DOC.'
                    : 'DOC saved securely. Legacy DOC extraction is unavailable on this server, so NurseLink will prompt for missing fields.';
            } elseif ($extension === 'pdf') {
                $text = $this->extractPdfText($absolute);
                $message = $text !== ''
                    ? 'Text extracted from PDF.'
                    : 'The PDF appears image-based or contains no readable text. NurseLink will prompt for missing fields.';
            } elseif (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
                $text = $this->extractImageText($absolute);
                $message = $text !== ''
                    ? 'OCR completed for the image.'
                    : 'Image saved securely. OCR is unavailable on this server, so NurseLink will prompt for missing fields.';
            }
        } catch (\Throwable $error) {
            $message = 'Document saved securely. Automatic extraction could not complete; review the missing-information prompts.';
        }

        $text = $this->normalizeExtractedText($text);
        $documentType = $this->detectDocumentType($name."\n".mb_substr($text, 0, 12000));
        $fields = $text !== '' ? $this->extractFields($text, $documentType) : [];

        return [
            'document_type' => $documentType,
            'status' => $text !== '' ? 'extracted' : 'needs_input',
            'fields' => $fields,
            'message' => $message,
        ];
    }

    private function extractDocxText(string $path): string
    {
        if (! class_exists(\ZipArchive::class)) {
            return '';
        }

        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();
        if ($xml === '') {
            return '';
        }

        $xml = preg_replace('/<w:tab[^>]*\/>/i', "\t", $xml) ?? $xml;
        $xml = preg_replace('/<w:br[^>]*\/>/i', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:p>/i', "\n", $xml) ?? $xml;

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function extractLegacyDocText(string $path): string
    {
        if ($this->commandAvailable('antiword')) {
            $output = $this->runCommand('antiword '.escapeshellarg($path).' 2>/dev/null');
            if (trim($output) !== '') {
                return $output;
            }
        }

        if ($this->commandAvailable('catdoc')) {
            $output = $this->runCommand('catdoc '.escapeshellarg($path).' 2>/dev/null');
            if (trim($output) !== '') {
                return $output;
            }
        }

        return '';
    }

    private function extractPdfText(string $path): string
    {
        if ($this->commandAvailable('pdftotext')) {
            $output = $this->runCommand('pdftotext -layout -enc UTF-8 '.escapeshellarg($path).' -');
            if (trim($output) !== '') {
                return $output;
            }
        }

        if ($this->commandAvailable('gs')) {
            $output = $this->runCommand(
                'gs -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=txtwrite -sOutputFile=- '
                .escapeshellarg($path)
                .' 2>/dev/null'
            );
            if (trim($output) !== '') {
                return $output;
            }
        }

        if ($this->commandAvailable('pdftoppm') && $this->commandAvailable('tesseract')) {
            $tmpBase = storage_path('app/nurselink-smart-registration-ocr-'.Str::random(16));
            $this->runCommand('pdftoppm -f 1 -singlefile -jpeg -r 180 '.escapeshellarg($path).' '.escapeshellarg($tmpBase));
            $image = $tmpBase.'.jpg';
            if (is_file($image)) {
                try {
                    return $this->runCommand('tesseract '.escapeshellarg($image).' stdout --psm 6 2>/dev/null');
                } finally {
                    @unlink($image);
                }
            }
        }

        return '';
    }

    private function extractImageText(string $path): string
    {
        if (! $this->commandAvailable('tesseract')) {
            return '';
        }

        return $this->runCommand('tesseract '.escapeshellarg($path).' stdout --psm 6 2>/dev/null');
    }

    private function runCommand(string $command): string
    {
        if (! function_exists('shell_exec')) {
            return '';
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('shell_exec', $disabled, true)) {
            return '';
        }

        $output = shell_exec($command);

        return is_string($output) ? $output : '';
    }

    private function commandAvailable(string $command): bool
    {
        $output = $this->runCommand('command -v '.escapeshellarg($command).' 2>/dev/null');

        return trim($output) !== '';
    }

    private function extractionCapabilities(): array
    {
        return [
            'pdf_text' => $this->commandAvailable('pdftotext') || $this->commandAvailable('gs'),
            'image_ocr' => $this->commandAvailable('tesseract'),
            'pdf_scan_ocr' => $this->commandAvailable('pdftoppm') && $this->commandAvailable('tesseract'),
            'docx_text' => class_exists(\ZipArchive::class),
            'doc_text' => $this->commandAvailable('antiword') || $this->commandAvailable('catdoc'),
            'note' => 'Automatic extraction assists data entry only. Applicants must review and confirm extracted values; credential verification remains reviewer-controlled.',
        ];
    }

    private function normalizeExtractedText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim(mb_substr($text, 0, self::MAX_EXTRACTED_TEXT));
    }

    private function detectDocumentType(string $text): string
    {
        $value = mb_strtolower($text);

        if (preg_match('/\b(prc|professional regulation commission|registration no|license no|licence no)\b/u', $value)) {
            return 'prc_license';
        }
        if (preg_match('/\b(passport|national id|identification card|driver.?s license)\b/u', $value)) {
            return 'identity';
        }
        if (preg_match('/\b(curriculum vitae|\bcv\b|resume|résumé|work experience|employment history)\b/u', $value)) {
            return 'cv';
        }
        if (preg_match('/\b(diploma|bachelor of science in nursing|bsn|bsc nursing|nursing degree|transcript)\b/u', $value)) {
            return 'nursing_diploma';
        }
        if (preg_match('/\b(employment certificate|certificate of employment|employment verification)\b/u', $value)) {
            return 'employment_certificate';
        }
        if (preg_match('/\b(training certificate|certificate of completion|bls|acls|pals|iv therapy)\b/u', $value)) {
            return 'training_certificate';
        }
        if (preg_match('/\b(nursing council|registered nurse license|registered nurse licence|board of nursing)\b/u', $value)) {
            return 'international_license';
        }

        return 'other';
    }

    private function extractFields(string $text, string $documentType): array
    {
        $fields = [];
        $lines = array_values(array_filter(array_map('trim', preg_split('/\n/u', $text) ?: [])));
        $compact = preg_replace('/\s+/u', ' ', $text) ?? $text;

        $this->addRegexField($fields, 'email', $compact, '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', 0.94);
        $this->addRegexField($fields, 'phone', $compact, '/(?<!\d)(?:\+?63|0)?9\d{9}(?!\d)/u', 0.88);

        $labelPatterns = [
            'first_name' => ['first name'],
            'middle_name' => ['middle name'],
            'last_name' => ['last name', 'surname', 'family name'],
            'birth_date' => ['date of birth', 'birth date', 'birthday'],
            'nationality' => ['nationality', 'citizenship'],
            'address_line1' => ['address', 'residential address', 'home address'],
            'current_employer' => ['current employer', 'employer', 'hospital', 'facility', 'institution', 'company'],
            'current_position' => ['current position', 'position', 'job title', 'designation', 'role'],
            'specialty' => ['specialty', 'specialisation', 'specialization'],
            'primary_license_number' => ['prc license no', 'prc license number', 'registration no', 'registration number', 'license no', 'license number', 'licence no', 'licence number', 'professional identification card no'],
            'primary_license_expiry' => ['expiry date', 'expiration date', 'valid until'],
            'highest_nursing_education' => ['degree', 'qualification', 'education', 'course', 'program', 'programme'],
        ];

        foreach ($labelPatterns as $field => $labels) {
            $candidate = $this->valueAfterLabel($lines, $labels);
            if ($candidate === null) {
                continue;
            }

            if (in_array($field, ['birth_date', 'primary_license_expiry'], true)) {
                $candidate = $this->normalizeDate($candidate);
                if ($candidate === null) {
                    continue;
                }
            }

            $confidence = in_array($field, ['primary_license_number', 'birth_date'], true) ? 0.86 : 0.78;
            $fields[$field] = ['value' => $candidate, 'confidence' => $confidence];
        }

        if (! isset($fields['professional_title'])) {
            if (preg_match('/\b(registered nurse|staff nurse|charge nurse|head nurse|nurse manager|clinical nurse|nursing officer|nurse educator|nurse practitioner)\b/iu', $compact, $match)) {
                $fields['professional_title'] = [
                    'value' => ucwords(mb_strtolower($match[1])),
                    'confidence' => $documentType === 'cv' ? 0.86 : 0.72,
                ];
            }
        }

        if (preg_match('/\b(\d{1,2})\+?\s+years?\s+(?:of\s+)?(?:nursing\s+)?experience\b/iu', $compact, $match)) {
            $fields['years_experience'] = ['value' => (int) $match[1], 'confidence' => 0.88];
        }

        if (! isset($fields['highest_nursing_education'])) {
            if (preg_match('/\b(bachelor(?:\'s)? of science in nursing|bachelor of science in nursing|bsn|bsc nursing|master(?:\'s)? of science in nursing|msn|doctor of nursing practice|dnp|diploma in nursing)\b/iu', $compact, $match)) {
                $fields['highest_nursing_education'] = [
                    'value' => $this->normalizeEducation($match[1]),
                    'confidence' => 0.9,
                ];
            }
        }

        if (preg_match('/\b(?:graduated|graduation|year graduated|class of)\D{0,12}(19\d{2}|20\d{2})\b/iu', $compact, $match)) {
            $fields['graduation_year'] = ['value' => (int) $match[1], 'confidence' => 0.78];
        }

        if ($documentType === 'prc_license') {
            $fields['primary_license_country'] = ['value' => 'Philippines', 'confidence' => 0.98];
        }

        if ($documentType === 'prc_license' && ! isset($fields['primary_license_number'])) {
            if (preg_match('/\b(?:PRC|RN)?[\s:-]*(?:NO\.?\s*)?\d{5,9}\b/iu', $compact, $match)) {
                $fields['primary_license_number'] = ['value' => trim($match[0]), 'confidence' => 0.66];
            }
        }

        $fullName = $this->valueAfterLabel($lines, ['full name', 'name of professional', 'name']);
        if ($fullName && ! isset($fields['first_name']) && ! isset($fields['last_name'])) {
            $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
            if (count($parts) >= 2 && count($parts) <= 6) {
                $fields['first_name'] = ['value' => array_shift($parts), 'confidence' => 0.68];
                $fields['last_name'] = ['value' => array_pop($parts), 'confidence' => 0.68];
                if ($parts !== []) {
                    $fields['middle_name'] = ['value' => implode(' ', $parts), 'confidence' => 0.6];
                }
            }
        }

        foreach ($fields as $field => &$candidate) {
            $candidate['value'] = is_string($candidate['value'])
                ? trim(preg_replace('/\s+/u', ' ', $candidate['value']) ?? $candidate['value'])
                : $candidate['value'];
        }
        unset($candidate);

        return array_filter($fields, fn ($candidate) => ($candidate['value'] ?? null) !== null && $candidate['value'] !== '');
    }

    private function addRegexField(array &$fields, string $field, string $text, string $pattern, float $confidence): void
    {
        if (preg_match($pattern, $text, $match)) {
            $fields[$field] = ['value' => trim((string) $match[0]), 'confidence' => $confidence];
        }
    }

    private function valueAfterLabel(array $lines, array $labels): ?string
    {
        foreach ($lines as $index => $line) {
            foreach ($labels as $label) {
                $pattern = '/^\s*'.preg_quote($label, '/').'\s*[:#-]?\s*(.*)$/iu';
                if (! preg_match($pattern, $line, $match)) {
                    continue;
                }

                $value = trim((string) ($match[1] ?? ''));
                if ($value === '' && isset($lines[$index + 1])) {
                    $value = trim((string) $lines[$index + 1]);
                }

                if ($value !== '' && mb_strlen($value) <= 255) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $time = strtotime($value);
        if ($time === false) {
            return null;
        }
        $year = (int) date('Y', $time);
        if ($year < 1900 || $year > now()->year + 30) {
            return null;
        }

        return date('Y-m-d', $time);
    }

    private function normalizeEducation(string $value): string
    {
        $key = mb_strtolower(trim($value));

        return match (true) {
            str_contains($key, 'master'), $key === 'msn' => 'Master of Science in Nursing',
            str_contains($key, 'doctor'), $key === 'dnp' => 'Doctor of Nursing Practice',
            str_contains($key, 'diploma') => 'Diploma in Nursing',
            default => 'Bachelor of Science in Nursing',
        };
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
