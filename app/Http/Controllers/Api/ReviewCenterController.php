<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ReviewCenterController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $access = $this->authorizeReviewer($request);

        return response()->json([
            'data' => [
                'role' => $access['role'],
                'credentials_pending' => DB::table('nurselink_credentials_registry')
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

        $query = DB::table('nurselink_credentials_registry')
            ->orderByRaw("CASE verification_status
                WHEN 'pending' THEN 1
                WHEN 'unverified' THEN 2
                WHEN 'verified' THEN 3
                WHEN 'expired' THEN 4
                ELSE 5 END")
            ->orderByDesc('updated_at')
            ->limit(300);

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();

            if (in_array($status, ['unverified', 'pending', 'verified', 'expired'], true)) {
                $query->where('verification_status', $status);
            }
        }

        $rows = $query->get();
        $members = $this->memberMap($rows->pluck('user_id')->all());

        return response()->json([
            'data' => $rows->map(function ($row) use ($members): array {
                return [
                    'id' => (int) $row->id,
                    'user_id' => (string) $row->user_id,
                    'member' => $members[(string) $row->user_id] ?? (string) $row->user_id,
                    'credential_type' => $row->credential_type,
                    'title' => $row->title,
                    'issuing_body' => $row->issuing_body,
                    'credential_number' => $row->credential_number,
                    'country' => $row->country,
                    'issue_date' => $row->issue_date,
                    'expiry_date' => $row->expiry_date,
                    'verification_status' => $row->verification_status,
                    'notes' => $row->notes,
                    'review_notes' => $row->review_notes ?? null,
                    'reviewed_by' => $row->reviewed_by ?? null,
                    'reviewed_at' => $row->reviewed_at ?? null,
                    'updated_at' => $row->updated_at,
                ];
            })->values(),
        ]);
    }

    public function reviewCredential(Request $request, int $id): JsonResponse
    {
        $access = $this->authorizeReviewer($request);

        $data = $request->validate([
            'verification_status' => ['required', 'string', Rule::in([
                'unverified',
                'pending',
                'verified',
                'expired',
            ])],
            'review_notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $before = DB::table('nurselink_credentials_registry')
            ->where('id', $id)
            ->first();

        abort_unless($before, 404);

        DB::table('nurselink_credentials_registry')
            ->where('id', $id)
            ->update([
                'verification_status' => $data['verification_status'],
                'review_notes' => $data['review_notes'] ?? null,
                'reviewed_by' => (string) $request->user()->getKey(),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        $after = DB::table('nurselink_credentials_registry')
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

        $membership = DB::table('nurselink_memberships')
            ->where('user_id', $after->user_id)
            ->first();

        $credentialActionUrl = $membership && $membership->status === 'approved'
            ? '/credentials'
            : '/smart-registration?nlstep=3';

        $this->notifyUser(
            (string) $after->user_id,
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
                'id' => (int) $after->id,
                'verification_status' => $after->verification_status,
                'review_notes' => $after->review_notes,
                'reviewed_at' => $after->reviewed_at,
                'reviewer_role' => $access['role'],
            ],
        ]);
    }

    public function jobApplications(Request $request): JsonResponse
    {
        $this->authorizeReviewer($request);

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
            'Your NurseLink application tracker is now ' . str_replace('_', ' ', $after->status) . '.',
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
