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
use Symfony\Component\HttpFoundation\StreamedResponse;

class MembershipReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeReviewer($request);

        $rows = DB::table('nurselink_memberships')
            ->where('status', '<>', 'draft')
            ->orderByRaw("CASE status
                WHEN 'submitted' THEN 1
                WHEN 'under_review' THEN 2
                WHEN 'needs_information' THEN 3
                WHEN 'ready_for_approval' THEN 4
                WHEN 'approved' THEN 5
                WHEN 'declined' THEN 6
                ELSE 7 END")
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get();

        $members = $this->memberMap($rows->pluck('user_id')->all());

        return response()->json([
            'data' => $rows->map(function ($row) use ($members): array {
                return [
                    'id' => (int) $row->id,
                    'user_id' => (string) $row->user_id,
                    'member' => $members[(string) $row->user_id] ?? (string) $row->user_id,
                    'status' => $row->status,
                    'member_number' => $row->member_number,
                    'reviewer_notes' => $row->reviewer_notes,
                    'reviewed_by' => $row->reviewed_by,
                    'reviewed_at' => $row->reviewed_at,
                    'approved_at' => $row->approved_at,
                    'declined_at' => $row->declined_at,
                    'updated_at' => $row->updated_at,
                ];
            })->values(),
        ]);
    }

    public function evidence(Request $request, int $id): JsonResponse
    {
        $this->authorizeReviewer($request);

        $membership = DB::table('nurselink_memberships')->where('id', $id)->first();
        abort_unless($membership, 404);

        $profile = DB::table('nurselink_smart_registration_profiles')
            ->where('user_id', $membership->user_id)
            ->first();

        $documents = DB::table('nurselink_smart_registration_documents')
            ->where('user_id', $membership->user_id)
            ->orderByDesc('id')
            ->get();

        $extractionSummary = $this->extractionSummary($documents, $profile);

        return response()->json(['data' => [
            'membership_id' => (int) $membership->id,
            'extraction_summary' => $extractionSummary,
            'profile' => $profile ? [
                'first_name' => $profile->first_name,
                'middle_name' => $profile->middle_name,
                'last_name' => $profile->last_name,
                'birth_date' => $profile->birth_date,
                'sex' => $profile->sex,
                'nationality' => $profile->nationality,
                'phone' => $profile->phone,
                'address_line1' => $profile->address_line1,
                'city' => $profile->city,
                'province' => $profile->province,
                'country' => $profile->country,
                'professional_title' => $profile->professional_title,
                'years_experience' => $profile->years_experience !== null ? (int) $profile->years_experience : null,
                'current_position' => $profile->current_position,
                'current_employer' => $profile->current_employer,
                'specialty' => $profile->specialty,
                'primary_license_number' => $profile->primary_license_number,
                'primary_license_country' => $profile->primary_license_country,
                'primary_license_expiry' => $profile->primary_license_expiry,
                'highest_nursing_education' => $profile->highest_nursing_education,
                'graduation_year' => $profile->graduation_year !== null ? (int) $profile->graduation_year : null,
                'confirmed_sources' => $profile->confirmed_sources ? json_decode($profile->confirmed_sources, true) : null,
                'last_extracted_at' => $profile->last_extracted_at,
            ] : null,
            'documents' => $documents->map(fn ($document): array => [
                'id' => (int) $document->id,
                'name' => $document->original_name,
                'mime_type' => $document->mime_type,
                'file_size' => (int) $document->file_size,
                'document_type' => $document->document_type,
                'extraction_status' => $document->extraction_status,
                'extracted_fields' => $document->extracted_fields ? json_decode($document->extracted_fields, true) : null,
                'extraction_message' => $document->extraction_message,
                'version' => property_exists($document, 'version') ? (int) $document->version : 1,
                'is_current' => property_exists($document, 'is_current') ? (bool) $document->is_current : true,
                'created_at' => $document->created_at,
                'download_url' => '/api/reviewer/membership-applications/'.(int) $membership->id.'/evidence/'.(int) $document->id,
            ])->values(),
        ]]);
    }

    public function downloadEvidence(Request $request, int $id, int $documentId): StreamedResponse
    {
        $this->authorizeReviewer($request);

        $membership = DB::table('nurselink_memberships')->where('id', $id)->first();
        abort_unless($membership, 404);

        $document = DB::table('nurselink_smart_registration_documents')
            ->where('id', $documentId)
            ->where('user_id', $membership->user_id)
            ->first();

        abort_unless($document && Storage::disk('local')->exists($document->storage_path), 404);

        return Storage::disk('local')->download(
            $document->storage_path,
            str_replace(["\r", "\n"], '', (string) $document->original_name),
            ['Content-Type' => $document->mime_type ?: 'application/octet-stream']
        );
    }

    private function extractionSummary($documents, ?object $profile): array
    {
        $fields = [];
        $needsInput = 0;
        $currentDocuments = 0;

        foreach ($documents as $document) {
            if (property_exists($document, 'is_current') && ! (bool) $document->is_current) continue;
            $currentDocuments++;
            if ((string) $document->extraction_status === 'needs_input') $needsInput++;

            $decoded = $document->extracted_fields ? json_decode($document->extracted_fields, true) : [];
            if (! is_array($decoded)) continue;

            foreach ($decoded as $field => $candidate) {
                if (! is_array($candidate) || trim((string) ($candidate['value'] ?? '')) === '') continue;
                $value = $candidate['value'];
                $key = preg_replace('/[^\pL\pN]+/u', '', mb_strtolower(trim((string) $value))) ?: trim((string) $value);
                $confidence = round((float) ($candidate['confidence'] ?? 0.5), 2);
                $existing = $fields[$field][$key] ?? null;
                if ($existing && $confidence <= (float) $existing['confidence']) continue;
                $fields[$field][$key] = [
                    'value' => $value,
                    'confidence' => $confidence,
                    'source_document_id' => (int) $document->id,
                    'source_name' => $document->original_name,
                ];
            }
        }

        $conflicts = [];
        $lowConfidence = [];
        foreach ($fields as $field => $values) {
            $candidates = array_values($values);
            usort($candidates, fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);
            if (count($candidates) > 1) $conflicts[] = ['field' => $field, 'candidates' => $candidates];
            if ($candidates !== [] && (float) $candidates[0]['confidence'] < 0.75) {
                $lowConfidence[] = ['field' => $field, 'candidate' => $candidates[0]];
            }
        }

        $confirmedCount = 0;
        $confirmed = $profile?->confirmed_sources ? json_decode($profile->confirmed_sources, true) : [];
        if (is_array($confirmed)) {
            foreach ($confirmed as $section) if (is_array($section)) $confirmedCount += count($section);
        }

        return [
            'current_documents' => $currentDocuments,
            'documents_needing_manual_input' => $needsInput,
            'fields_detected' => count($fields),
            'applicant_confirmed_fields' => $confirmedCount,
            'conflicts' => $conflicts,
            'low_confidence_fields' => $lowConfidence,
            'review_required' => $conflicts !== [] || $lowConfidence !== [] || $needsInput > 0,
            'verification_boundary' => 'Extraction and applicant confirmation do not constitute credential verification.',
        ];
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $access = $this->authorizeReviewer($request);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in([
                'submitted',
                'under_review',
                'needs_information',
                'ready_for_approval',
                'approved',
                'declined',
            ])],
            'reviewer_notes' => ['nullable', 'string', 'max:6000'],
        ]);

        $before = DB::table('nurselink_memberships')
            ->where('id', $id)
            ->first();

        abort_unless($before, 404);

        if ($before->status === 'approved' && $data['status'] !== 'approved') {
            return response()->json([
                'message' => 'Approved membership cannot be downgraded through this workflow.',
            ], 422);
        }

        if ((string) $before->user_id === (string) $request->user()->getKey()) {
            return response()->json([
                'message' => 'Self-actions must use the Membership Command Center with explicit Super Administrator confirmation and audit logging.',
            ], 422);
        }

        $reviewerTransitions = [
            'submitted' => ['under_review', 'needs_information'],
            'under_review' => ['needs_information', 'ready_for_approval'],
            'needs_information' => ['under_review', 'ready_for_approval'],
            'ready_for_approval' => ['under_review', 'needs_information'],
        ];

        $adminTransitions = [
            'submitted' => ['under_review', 'needs_information'],
            'under_review' => ['needs_information', 'ready_for_approval', 'declined'],
            'needs_information' => ['under_review', 'ready_for_approval', 'declined'],
            'ready_for_approval' => ['under_review', 'needs_information', 'approved', 'declined'],
        ];

        $allowed = $access['role'] === 'admin'
            ? ($adminTransitions[$before->status] ?? [])
            : ($reviewerTransitions[$before->status] ?? []);

        if ($data['status'] !== $before->status && ! in_array($data['status'], $allowed, true)) {
            return response()->json([
                'message' => 'That membership transition is not allowed for the current status or reviewer role.',
                'allowed_actions' => $allowed,
            ], 422);
        }

        if (
            in_array($data['status'], ['approved', 'declined'], true)
            && $access['role'] !== 'admin'
        ) {
            abort(403, 'Administrator access is required for final membership decisions.');
        }

        if (
            in_array($data['status'], ['needs_information', 'declined'], true)
            && trim((string) ($data['reviewer_notes'] ?? '')) === ''
        ) {
            return response()->json([
                'message' => 'Reviewer notes are required when requesting information or declining membership.',
            ], 422);
        }

        $update = [
            'status' => $data['status'],
            'reviewer_notes' => $data['reviewer_notes'] ?? null,
            'reviewed_by' => (string) $request->user()->getKey(),
            'reviewed_at' => now(),
            'updated_at' => now(),
        ];

        if ($data['status'] === 'approved') {
            $memberNumber = $before->member_number ?: $this->generateMemberNumber($before);
            $verificationCode = $before->verification_code ?: Str::lower(Str::random(40));

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
        }

        if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_at')) {
            $update['last_status_changed_at'] = now();
        }
        if (Schema::hasColumn('nurselink_memberships', 'last_status_changed_by')) {
            $update['last_status_changed_by'] = (string) $request->user()->getKey();
        }

        $actorUserId = (string) $request->user()->getKey();
        $actorType = $access['role'] === 'admin' ? 'administrator' : 'reviewer';
        $historyReason = trim((string) ($data['reviewer_notes'] ?? '')) ?: null;

        $after = DB::transaction(function () use ($id, $before, $update, $actorUserId, $actorType, $historyReason): object {
            $locked = DB::table('nurselink_memberships')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            abort_unless($locked, 404);
            abort_if(
                (string) $locked->status !== (string) $before->status
                    || (string) $locked->updated_at !== (string) $before->updated_at,
                409,
                'This membership application changed in another administrator session. Refresh before saving another review decision.'
            );

            DB::table('nurselink_memberships')
                ->where('id', $id)
                ->update($update);

            $after = DB::table('nurselink_memberships')
                ->where('id', $id)
                ->first();

            if ($after->status === 'approved' && $after->member_number) {
                // Approval, core-member activation and onboarding are one DB transaction.
                $this->syncCoreMembership((string) $after->user_id, (string) $after->member_number);
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
                    'legacy_review_endpoint' => true,
                    'applicant_visible_reason' => in_array((string) $after->status, ['needs_information', 'declined'], true)
                        ? $historyReason
                        : null,
                ]
            );

            return $after;
        });

        $this->audit(
            $request,
            'membership.status_changed',
            'membership',
            (string) $id,
            $before,
            $after
        );

        $lifecycle = app(MembershipLifecycleService::class);
        $delivery = $lifecycle->notifyEvent(
            $after,
            (string) $after->status,
            $data['status'] === 'needs_information' ? ($data['reviewer_notes'] ?? null) : null
        );

        return response()->json([
            'message' => 'Membership review saved.',
            'data' => [
                'id' => (int) $after->id,
                'status' => $after->status,
                'member_number' => $after->member_number,
                'reviewer_notes' => $after->reviewer_notes,
                'approved_at' => $after->approved_at,
                'notification_delivery' => $delivery,
            ],
        ]);
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

    private function syncCoreMembership(string $userId, string $memberNumber): void
    {
        app(\App\Services\CoreMembershipActivationService::class)->sync($userId, $memberNumber);
        $updates = [];

        if (Schema::hasColumn('users', 'member_number')) {
            $updates['member_number'] = $memberNumber;
        }

        if (Schema::hasColumn('users', 'is_member')) {
            $updates['is_member'] = true;
        }

        if (Schema::hasColumn('users', 'membership_approved_at')) {
            $updates['membership_approved_at'] = now();
        }

        if ($updates !== []) {
            DB::table('users')
                ->where('id', $userId)
                ->update($updates);
        }
    }

    private function notifyMembershipStatus(object $membership): void
    {
        $copy = [
            'submitted' => [
                'info',
                'Membership application received',
                'Your NurseLink membership application is in the review queue.',
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
                'Congratulations. Your NurseLink membership has been approved and your digital member identity is now available.',
                '/dashboard',
            ],
            'declined' => [
                'error',
                'Membership review completed',
                'Your NurseLink membership application was not approved. Review the decision notes in Application Status.',
                '/application-status',
            ],
        ];

        [$severity, $title, $message, $url] = $copy[$membership->status] ?? $copy['submitted'];

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

    private function authorizeReviewer(Request $request): array
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
            && hash_equals($sessionUserId, (string) $user->getKey())
            && $elevatedAt > 0
            && $expiresAt >= time()
            && (time() - $elevatedAt) <= 28800,
            403,
            'A separate NurseLink administrator sign-in is required for membership review.'
        );

        $role = null;

        $explicit = DB::table('nurselink_reviewer_access')
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->first();

        if ($explicit) {
            $role = strtolower((string) $explicit->role);
        }

        $modelRole = strtolower((string) (
            $user->role
            ?? $user->user_role
            ?? $user->user_type
            ?? ''
        ));

        $modelAdmin = (bool) (
            $user->is_admin
            ?? $user->is_super_admin
            ?? false
        );

        if ($modelAdmin || in_array($modelRole, ['admin', 'administrator', 'super_admin'], true)) {
            $role = 'admin';
        }

        if (! in_array($role, ['reviewer', 'admin'], true)) {
            abort(403, 'Reviewer access is required.');
        }

        return ['role' => $role];
    }

    private function memberMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn ($id) => (string) $id, $ids)
        )));

        if ($ids === []) return [];

        $columns = ['id'];

        foreach (['email', 'name', 'first_name', 'last_name'] as $column) {
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

            $email = trim((string) ($row->email ?? ''));

            $parts = array_values(array_filter([$name, $email]));

            $map[(string) $row->id] = $parts !== []
                ? implode(' · ', $parts)
                : (string) $row->id;
        }

        return $map;
    }

    private function audit(
        Request $request,
        string $action,
        string $targetType,
        string $targetId,
        mixed $before,
        mixed $after
    ): void {
        DB::table('nurselink_review_audit')->insert([
            'reviewer_user_id' => (string) $request->user()->getKey(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before_state' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'after_state' => $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(),
        ]);
    }
}
