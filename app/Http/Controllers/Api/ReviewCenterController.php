<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReviewCenterController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $access = $this->authorizeReviewer($request);

        return response()->json([
            'data' => [
                'role' => $access['role'],
                'credentials_pending' => DB::table('professional_credentials')
                    ->whereIn('verification_status', ['unverified', 'pending'])
                    ->count(),
                'job_applications_active' => DB::table('nurselink_job_applications')
                    ->whereNotIn('status', ['declined', 'withdrawn'])
                    ->count(),
                'job_applications_interview' => DB::table('nurselink_job_applications')
                    ->where('status', 'interview')
                    ->count(),
                'job_opportunities_active' => DB::table('nurselink_job_opportunities')
                    ->where('status', 'active')
                    ->count(),
            ],
        ]);
    }

    public function credentials(Request $request): JsonResponse
    {
        $this->authorizeReviewer($request);

        $query = DB::table('professional_credentials as credentials')
            ->join('members', 'members.id', '=', 'credentials.member_id')
            ->join('users', 'users.id', '=', 'members.user_id')
            ->leftJoin('member_documents as primary_document', 'primary_document.id', '=', 'credentials.primary_document_id')
            ->orderByRaw("CASE verification_status
                WHEN 'pending' THEN 1
                WHEN 'unverified' THEN 2
                WHEN 'discrepancy' THEN 3
                WHEN 'unable_to_verify' THEN 4
                WHEN 'document_supported' THEN 5
                WHEN 'verified' THEN 6
                ELSE 7 END")
            ->orderByDesc('credentials.updated_at')
            ->limit(300);

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();

            if (in_array($status, ['unverified', 'pending', 'verified', 'document_supported', 'unable_to_verify', 'discrepancy'], true)) {
                $query->where('credentials.verification_status', $status);
            }
        }

        $rows = $query->get([
            'credentials.*',
            'members.user_id',
            'users.name as member_name',
            'users.email as member_email',
            'primary_document.title as evidence_title',
            'primary_document.original_name as evidence_original_name',
            'primary_document.security_status as evidence_security_status',
        ]);

        return response()->json([
            'data' => $rows->map(function ($row): array {
                return [
                    'id' => (string) $row->id,
                    'user_id' => (string) $row->user_id,
                    'member' => $row->member_name ?: $row->member_email,
                    'credential_type' => $row->credential_type,
                    'title' => $row->title,
                    'issuing_body' => $row->issuing_authority,
                    'credential_number' => $row->credential_number_last4 ? '••••'.$row->credential_number_last4 : null,
                    'country' => $row->country,
                    'issue_date' => $row->issued_on,
                    'expiry_date' => $row->expires_on,
                    'verification_status' => $row->verification_status,
                    'review_notes' => $row->verification_note,
                    'reviewed_by' => $row->verified_by,
                    'reviewed_at' => $row->verified_at,
                    'primary_document_id' => $row->primary_document_id,
                    'evidence_title' => $row->evidence_title,
                    'evidence_security_status' => $row->evidence_security_status,
                    'evidence_download_url' => $row->primary_document_id
                        ? '/api/reviewer/credentials/'.$row->id.'/evidence/'.$row->primary_document_id
                        : null,
                    'updated_at' => $row->updated_at,
                ];
            })->values(),
        ]);
    }

    public function reviewCredential(Request $request, string $id): JsonResponse
    {
        $access = $this->authorizeReviewer($request);

        $data = $request->validate([
            'verification_status' => ['required', 'string', Rule::in([
                'unverified', 'pending', 'verified', 'document_supported',
                'unable_to_verify', 'discrepancy',
            ])],
            'review_notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $before = DB::table('professional_credentials')
            ->where('id', $id)
            ->first();

        abort_unless($before, 404);

        $accepted = in_array($data['verification_status'], ['verified', 'document_supported'], true);

        DB::table('professional_credentials')
            ->where('id', $id)
            ->update([
                'verification_status' => $data['verification_status'],
                'verification_note' => $data['review_notes'] ?? null,
                'verified_by' => $accepted ? (string) $request->user()->getKey() : null,
                'verified_at' => $accepted ? now() : null,
                'updated_at' => now(),
            ]);

        $after = DB::table('professional_credentials')
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'credential.reviewed',
            'credential',
            (string) $id,
            $before,
            $after
        );

        $member = DB::table('members')->where('id', $after->member_id)->first();
        abort_unless($member, 404);

        $membership = DB::table('nurselink_memberships')
            ->where('user_id', $member->user_id)
            ->first();

        $credentialActionUrl = $membership && $membership->status === 'approved'
            ? '/credentials'
            : '/smart-registration?nlstep=3';

        $this->notifyUser(
            (string) $member->user_id,
            'credential.' . $after->verification_status,
            $after->verification_status === 'verified' ? 'success' : (
                $after->verification_status === 'expired' ? 'warning' : 'info'
            ),
            'Credential review updated',
            'Your credential "' . $after->title . '" is now ' . str_replace('_', ' ', $after->verification_status) . '.',
            $credentialActionUrl
        );

        return response()->json([
            'message' => 'Credential review saved.',
            'data' => [
                'id' => (string) $after->id,
                'verification_status' => $after->verification_status,
                'review_notes' => $after->verification_note,
                'reviewed_at' => $after->verified_at,
                'reviewer_role' => $access['role'],
            ],
        ]);
    }

    public function downloadCredentialEvidence(Request $request, string $id, string $documentId): StreamedResponse
    {
        $this->authorizeReviewer($request);

        $credential = DB::table('professional_credentials')->where('id', $id)->first();
        abort_unless($credential, 404);

        $isLinked = $credential->primary_document_id === $documentId
            || DB::table('credential_documents')
                ->where('credential_id', $id)
                ->where('document_id', $documentId)
                ->exists();
        abort_unless($isLinked, 404);

        $document = DB::table('member_documents')
            ->where('id', $documentId)
            ->where('member_id', $credential->member_id)
            ->first();
        abort_unless($document, 404);
        abort_unless($document->security_status === 'clean', 423, 'Document security scan must complete before download.');
        abort_unless(Storage::disk($document->storage_disk)->exists($document->storage_path), 404);

        $this->audit(
            $request,
            'credential.evidence_viewed',
            'professional_credential',
            $id,
            null,
            ['document_id' => $documentId]
        );

        return Storage::disk($document->storage_disk)
            ->download($document->storage_path, $document->original_name);
    }

    public function jobApplications(Request $request): JsonResponse
    {
        $this->authorizeReviewer($request);

        // Reuse the protected reviewer endpoint; ordinary application responses stay unchanged.
        if ($request->query('demo_lineup') === '1') {
            return $this->demoApplicantLineup();
        }

        $rows = DB::table('nurselink_job_applications as a')
            ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
            ->orderByRaw("CASE a.status
                WHEN 'submitted' THEN 1
                WHEN 'under_review' THEN 2
                WHEN 'shortlisted' THEN 3
                WHEN 'interview' THEN 4
                WHEN 'offer' THEN 5
                WHEN 'declined' THEN 6
                WHEN 'withdrawn' THEN 7
                ELSE 8 END")
            ->orderByDesc('a.updated_at')
            ->limit(300)
            ->get([
                'a.id',
                'a.user_id',
                'a.job_opportunity_id',
                'a.status',
                'a.cover_note',
                'a.reviewer_notes',
                'a.reviewed_by',
                'a.reviewed_at',
                'a.submitted_at',
                'a.withdrawn_at',
                'a.updated_at',
                'j.reference_code',
                'j.title',
                'j.employer_name',
                'j.country',
                'j.city',
                'j.specialty',
            ]);

        $members = $this->memberMap($rows->pluck('user_id')->all());

        return response()->json([
            'data' => $rows->map(function ($row) use ($members): array {
                return [
                    'id' => (int) $row->id,
                    'user_id' => (string) $row->user_id,
                    'member' => $members[(string) $row->user_id] ?? (string) $row->user_id,
                    'job_opportunity_id' => (int) $row->job_opportunity_id,
                    'status' => $row->status,
                    'cover_note' => $row->cover_note,
                    'reviewer_notes' => $row->reviewer_notes,
                    'reviewed_by' => $row->reviewed_by,
                    'reviewed_at' => $row->reviewed_at,
                    'submitted_at' => $row->submitted_at,
                    'withdrawn_at' => $row->withdrawn_at,
                    'reference_code' => $row->reference_code,
                    'title' => $row->title,
                    'employer_name' => $row->employer_name,
                    'country' => $row->country,
                    'city' => $row->city,
                    'specialty' => $row->specialty,
                ];
            })->values(),
        ]);
    }

    private function demoApplicantLineup(): JsonResponse
    {
        $members = DB::table('members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->join('nurselink_demo_profile_records as d', 'd.member_number', '=', 'm.member_no')
            ->leftJoin('member_profiles as p', 'p.member_id', '=', 'm.id')
            ->where('d.record_type', 'dummy_dataset')->where('d.record_key', 'NL-DUMMY-20261003')
            ->where('d.is_active', 1)->where('u.email', 'like', 'nl-demo-%@example.invalid')
            ->where('m.member_no', 'like', 'NL-DEMO-%')->orderBy('m.member_no')
            ->get(['m.id', 'm.user_id', 'm.member_no', 'u.name', 'u.email', 'd.payload',
                'p.current_position', 'p.years_experience'])
            ->filter(function ($m) {
                $marker = json_decode($m->payload, true) ?: [];
                return preg_match('/^nl-demo-\d{3}@example\.invalid$/D', $m->email)
                    && preg_match('/^NL-DEMO-\d{3}$/D', $m->member_no)
                    && ($marker['user_id'] ?? null) === $m->user_id
                    && ($marker['member_id'] ?? null) === $m->id;
            })->unique('id')->values();

        $uids = $members->pluck('user_id');
        $credentials = DB::table('nurselink_credentials_registry')->whereIn('user_id', $uids)->get()->groupBy('user_id');
        $competencies = DB::table('portfolio_competencies')->whereIn('member_id', $members->pluck('id'))->get()->groupBy('member_id');
        $profiles = DB::table('nurselink_smart_registration_profiles')->whereIn('user_id', $uids)->get()->keyBy('user_id');
        $preferences = DB::table('nurselink_career_preferences')->whereIn('user_id', $uids)->get()->keyBy('user_id');
        $jobs = DB::table('nurselink_job_opportunities')->where('source_authority', 'demo')
            ->where('source_label', 'NL-DUMMY-20261003')->where('reference_code', 'like', 'NL-DEMO-JOB-%')->get()->keyBy('id');
        $applications = DB::table('nurselink_job_applications')->whereIn('user_id', $uids)
            ->whereIn('job_opportunity_id', $jobs->keys())->orderByDesc('submitted_at')->get();
        $interviews = DB::table('nurselink_interviews')->whereIn('user_id', $uids)
            ->whereIn('job_application_id', $applications->pluck('id'))->orderByDesc('scheduled_start')->get()->groupBy('user_id');
        $byUser = $applications->groupBy('user_id');

        return response()->json(['data' => $members->map(function ($m) use ($credentials, $competencies, $profiles, $preferences, $jobs, $byUser, $interviews) {
            $creds = $credentials->get($m->user_id, collect());
            $skills = $competencies->get($m->id, collect());
            $profile = $profiles->get($m->user_id);
            $specialty = $profile->specialty ?? preg_replace('/\s+Nurse$/i', '', $m->current_position ?? '');
            $verified = $creds->filter(fn ($c) => $c->verification_status === 'verified'
                && (!$c->expiry_date || $c->expiry_date >= now()->toDateString()))->count();
            $verifiedSkills = $skills->where('evidence_state', 'verified')->count();
            $apps = $byUser->get($m->user_id, collect());
            $interview = $interviews->get($m->user_id, collect())->first();
            $rating = null;
            if ($interview && str_contains($interview->partner_notes ?? '', 'TEST / DUMMY')
                && preg_match('/Interview Rating:\s*([1-5])\/5\b/', $interview->partner_notes ?? '', $matches)) {
                $rating = (int) $matches[1];
            }
            $action = $verified < $creds->count() || $creds->isEmpty() ? 'Review credential evidence'
                : ($verifiedSkills < $skills->count() || $skills->isEmpty() ? 'Review competency evidence'
                : ($apps->isEmpty() ? 'Review demo job fit'
                : ($interview && $interview->status === 'completed' ? 'Review interview notes' : 'Review interview schedule')));
            return [
                'member_id' => $m->id, 'member_no' => $m->member_no, 'name' => $m->name,
                'specialty' => $specialty ?: null, 'years_experience' => $m->years_experience,
                'credentials' => ['total' => $creds->count(), 'verified_current' => $verified],
                'competencies' => ['total' => $skills->count(), 'verified' => $verifiedSkills],
                'pqf_readiness' => null, 'preferred_employer' => null,
                'target_countries' => json_decode($preferences->get($m->user_id)->target_countries ?? '[]', true) ?: [],
                'applications' => $apps->map(function ($a) use ($jobs) { $j = $jobs->get($a->job_opportunity_id); return [
                    'id' => $a->id, 'status' => $a->status, 'title' => $j->title, 'employer' => $j->employer_name,
                    'reference_code' => $j->reference_code,
                ]; })->values(),
                'specialty_suggestions' => $jobs->where('specialty', $specialty)->map(fn ($j) => [
                    'title' => $j->title, 'employer' => $j->employer_name, 'reference_code' => $j->reference_code,
                ])->values(),
                'interview' => $interview ? ['status' => $interview->status, 'scheduled_start' => $interview->scheduled_start,
                    'timezone' => $interview->timezone, 'demo_note_rating' => $rating] : null,
                'recommended_action' => $action,
            ];
        })->values(), 'meta' => ['demo_only' => true, 'read_only' => true, 'total' => $members->count(),
            'notice' => 'TEST / DUMMY — not for actual recruitment or deployment. Ratings are demo notes; specialty suggestions are not eligibility decisions.']]);
    }

    public function reviewJobApplication(Request $request, int $id): JsonResponse
    {
        $this->authorizeReviewer($request);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in([
                'under_review',
                'shortlisted',
                'interview',
                'offer',
                'declined',
            ])],
            'reviewer_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $before = DB::table('nurselink_job_applications')
            ->where('id', $id)
            ->first();

        abort_unless($before, 404);

        if ($before->status === 'withdrawn') {
            return response()->json([
                'message' => 'A withdrawn member application cannot be moved forward.',
            ], 422);
        }

        DB::table('nurselink_job_applications')
            ->where('id', $id)
            ->update([
                'status' => $data['status'],
                'reviewer_notes' => $data['reviewer_notes'] ?? null,
                'reviewed_by' => (string) $request->user()->getKey(),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        $after = DB::table('nurselink_job_applications')
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'job_application.status_changed',
            'job_application',
            (string) $id,
            $before,
            $after
        );

        $this->notifyUser(
            (string) $after->user_id,
            'job_application.' . $after->status,
            $after->status === 'offer' ? 'success' : (
                $after->status === 'declined' ? 'error' : 'info'
            ),
            'Job application status updated',
            'Your LBS NurseLink application tracker is now ' . str_replace('_', ' ', $after->status) . '.',
            '/applications'
        );

        return response()->json([
            'message' => 'Application review saved.',
            'data' => [
                'id' => (int) $after->id,
                'status' => $after->status,
                'reviewer_notes' => $after->reviewer_notes,
                'reviewed_at' => $after->reviewed_at,
            ],
        ]);
    }

    public function jobOpportunities(Request $request): JsonResponse
    {
        $this->authorizeReviewer($request);

        $rows = DB::table('nurselink_job_opportunities')
            ->orderByRaw("CASE status WHEN 'active' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END")
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($row) => $this->presentJob($row))->values(),
        ]);
    }

    public function storeJobOpportunity(Request $request): JsonResponse
    {
        $this->authorizeReviewer($request, true);

        $data = $this->validatedJob($request);

        $id = DB::table('nurselink_job_opportunities')->insertGetId([
            ...$data,
            'verified_by' => (string) $request->user()->getKey(),
            'verified_at' => now(),
            'published_at' => $data['published_at'] ?? (
                $data['status'] === 'active' ? now() : null
            ),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $after = DB::table('nurselink_job_opportunities')
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'job_opportunity.created',
            'job_opportunity',
            (string) $id,
            null,
            $after
        );

        return response()->json([
            'message' => 'Verified opportunity created.',
            'data' => $this->presentJob($after),
        ], 201);
    }

    public function updateJobOpportunity(Request $request, int $id): JsonResponse
    {
        $this->authorizeReviewer($request, true);

        $before = DB::table('nurselink_job_opportunities')
            ->where('id', $id)
            ->first();

        abort_unless($before, 404);

        $data = $this->validatedJob($request, $id);

        if ($data['status'] === 'active' && empty($data['published_at']) && empty($before->published_at)) {
            $data['published_at'] = now();
        }

        DB::table('nurselink_job_opportunities')
            ->where('id', $id)
            ->update([
                ...$data,
                'verified_by' => (string) $request->user()->getKey(),
                'verified_at' => now(),
                'updated_at' => now(),
            ]);

        $after = DB::table('nurselink_job_opportunities')
            ->where('id', $id)
            ->first();

        $this->audit(
            $request,
            'job_opportunity.updated',
            'job_opportunity',
            (string) $id,
            $before,
            $after
        );

        return response()->json([
            'message' => 'Opportunity updated.',
            'data' => $this->presentJob($after),
        ]);
    }

    public function auditLog(Request $request): JsonResponse
    {
        $this->authorizeReviewer($request, true);

        $rows = DB::table('nurselink_review_audit')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(250)
            ->get();

        return response()->json(['data' => $rows]);
    }

    private function authorizeReviewer(Request $request, bool $adminOnly = false): array
    {
        $user = $request->user();
        abort_unless($user, 401);

        $role = null;

        $access = DB::table('nurselink_reviewer_access')
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->first();

        if ($access) {
            $role = strtolower((string) $access->role);
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

        if ($adminOnly && $role !== 'admin') {
            abort(403, 'Administrator access is required for opportunity management.');
        }

        return ['role' => $role];
    }

    private function validatedJob(Request $request, ?int $ignoreId = null): array
    {
        $referenceRule = Rule::unique('nurselink_job_opportunities', 'reference_code');

        if ($ignoreId !== null) {
            $referenceRule = $referenceRule->ignore($ignoreId);
        }

        return $request->validate([
            'reference_code' => ['required', 'string', 'max:120', $referenceRule],
            'title' => ['required', 'string', 'max:190'],
            'employer_name' => ['required', 'string', 'max:190'],
            'country' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'work_setting' => ['nullable', 'string', Rule::in([
                'hospital',
                'clinic',
                'community',
                'home_care',
                'long_term_care',
                'education',
                'occupational_health',
                'telehealth',
                'government',
                'other',
            ])],
            'employment_type' => ['nullable', 'string', Rule::in([
                'full_time',
                'part_time',
                'contract',
                'temporary',
                'project_based',
                'other',
            ])],
            'specialty' => ['nullable', 'string', 'max:150'],
            'required_license_type' => ['nullable', 'string', Rule::in([
                'prc_license',
                'nursing_diploma',
                'international_license',
                'specialty_certification',
                'training_certificate',
                'professional_membership',
                'language_certificate',
                'other',
            ])],
            'minimum_experience_years' => ['required', 'numeric', 'min:0', 'max:99'],
            'overseas_opportunity' => ['required', 'boolean'],
            'salary_min' => ['nullable', 'numeric', 'min:0'],
            'salary_max' => ['nullable', 'numeric', 'gte:salary_min'],
            'salary_currency' => ['nullable', 'string', 'max:8'],
            'description' => ['nullable', 'string', 'max:12000'],
            'requirements' => ['nullable', 'string', 'max:12000'],
            'apply_url' => ['nullable', 'url', 'max:512'],
            'source_label' => ['nullable', 'string', 'max:190'],
            'source_authority' => ['nullable', 'string', Rule::in(['dmw', 'nurselink', 'partner', 'other'])],
            'agency_name' => ['nullable', 'string', 'max:190'],
            'dmw_job_order_number' => ['nullable', 'string', 'max:120'],
            'dmw_job_order_url' => ['nullable', 'url', 'max:512'],
            'dmw_agency_checked_at' => ['nullable', 'date'],
            'dmw_job_order_checked_at' => ['nullable', 'date'],
            'source_verification_status' => ['nullable', 'string', Rule::in(['pending_review', 'verified', 'expired', 'unable_to_verify'])],
            'status' => ['required', 'string', Rule::in(['active', 'paused', 'closed'])],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
        ]);
    }

    private function presentJob(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'reference_code' => $row->reference_code,
            'title' => $row->title,
            'employer_name' => $row->employer_name,
            'country' => $row->country,
            'city' => $row->city,
            'work_setting' => $row->work_setting,
            'employment_type' => $row->employment_type,
            'specialty' => $row->specialty,
            'required_license_type' => $row->required_license_type,
            'minimum_experience_years' => (float) $row->minimum_experience_years,
            'overseas_opportunity' => (bool) $row->overseas_opportunity,
            'salary_min' => $row->salary_min !== null ? (float) $row->salary_min : null,
            'salary_max' => $row->salary_max !== null ? (float) $row->salary_max : null,
            'salary_currency' => $row->salary_currency,
            'description' => $row->description,
            'requirements' => $row->requirements,
            'apply_url' => $row->apply_url,
            'source_label' => $row->source_label,
            'source_authority' => $row->source_authority ?? null,
            'agency_name' => $row->agency_name ?? null,
            'dmw_job_order_number' => $row->dmw_job_order_number ?? null,
            'dmw_job_order_url' => $row->dmw_job_order_url ?? null,
            'dmw_agency_checked_at' => $row->dmw_agency_checked_at ?? null,
            'dmw_job_order_checked_at' => $row->dmw_job_order_checked_at ?? null,
            'source_verification_status' => $row->source_verification_status ?? null,
            'verified_by' => $row->verified_by ?? null,
            'verified_at' => $row->verified_at ?? null,
            'status' => $row->status,
            'published_at' => $row->published_at,
            'expires_at' => $row->expires_at,
        ];
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
            $parts = [];

            if (property_exists($row, 'name') && trim((string) $row->name) !== '') {
                $parts[] = trim((string) $row->name);
            } else {
                $first = property_exists($row, 'first_name') ? trim((string) $row->first_name) : '';
                $last = property_exists($row, 'last_name') ? trim((string) $row->last_name) : '';
                $name = trim($first . ' ' . $last);
                if ($name !== '') $parts[] = $name;
            }

            if (property_exists($row, 'email') && trim((string) $row->email) !== '') {
                $parts[] = trim((string) $row->email);
            }

            $map[(string) $row->id] = $parts !== []
                ? implode(' · ', $parts)
                : (string) $row->id;
        }

        return $map;
    }

    private function notifyUser(
        string $userId,
        string $type,
        string $severity,
        string $title,
        string $message,
        ?string $actionUrl = null
    ): void {
        DB::table('nurselink_notifications')->insert([
            'user_id' => $userId,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
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
