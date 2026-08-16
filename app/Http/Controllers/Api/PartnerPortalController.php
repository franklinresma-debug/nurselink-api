<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PartnerPortalController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $access = $this->authorizePartner($request);

        return response()->json([
            'data' => [
                'role' => $access['role'],
                'organization' => $this->presentOrganization($access['organization']),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $access = $this->authorizePartner($request);
        $orgId = (int) $access['organization']->id;

        $jobIds = DB::table('nurselink_job_opportunities')
            ->where('partner_organization_id', $orgId)
            ->pluck('id');

        $applicationQuery = DB::table('nurselink_job_applications');

        if ($jobIds->isEmpty()) {
            $applicationQuery->whereRaw('1 = 0');
        } else {
            $applicationQuery->whereIn('job_opportunity_id', $jobIds);
        }

        return response()->json([
            'data' => [
                'role' => $access['role'],
                'organization' => $this->presentOrganization($access['organization']),
                'opportunities_total' => DB::table('nurselink_job_opportunities')
                    ->where('partner_organization_id', $orgId)
                    ->count(),
                'opportunities_active' => DB::table('nurselink_job_opportunities')
                    ->where('partner_organization_id', $orgId)
                    ->where('status', 'active')
                    ->count(),
                'opportunities_pending_review' => DB::table('nurselink_job_opportunities')
                    ->where('partner_organization_id', $orgId)
                    ->whereNull('verified_at')
                    ->where('status', 'paused')
                    ->count(),
                'applications_total' => (clone $applicationQuery)->count(),
                'applications_shortlisted' => (clone $applicationQuery)
                    ->where('status', 'shortlisted')
                    ->count(),
                'applications_interview' => (clone $applicationQuery)
                    ->where('status', 'interview')
                    ->count(),
                'applications_offer' => (clone $applicationQuery)
                    ->where('status', 'offer')
                    ->count(),
            ],
        ]);
    }

    public function opportunities(Request $request): JsonResponse
    {
        $access = $this->authorizePartner($request);

        $rows = DB::table('nurselink_job_opportunities')
            ->where('partner_organization_id', $access['organization']->id)
            ->orderByRaw("CASE status WHEN 'active' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($row) => $this->presentJob($row))->values(),
        ]);
    }

    public function storeOpportunity(Request $request): JsonResponse
    {
        $access = $this->authorizePartner($request, true);
        $data = $this->validatedJob($request);

        $referenceCode = 'NLP-'
            . (int) $access['organization']->id
            . '-'
            . now()->format('Ymd')
            . '-'
            . strtoupper(Str::random(6));

        $id = DB::table('nurselink_job_opportunities')->insertGetId([
            'partner_organization_id' => (int) $access['organization']->id,
            'reference_code' => $referenceCode,
            'title' => $data['title'],
            'employer_name' => $access['organization']->name,
            'country' => $data['country'],
            'city' => $data['city'] ?? null,
            'work_setting' => $data['work_setting'] ?? null,
            'employment_type' => $data['employment_type'] ?? null,
            'specialty' => $data['specialty'] ?? null,
            'required_license_type' => $data['required_license_type'] ?? null,
            'minimum_experience_years' => $data['minimum_experience_years'],
            'overseas_opportunity' => $data['overseas_opportunity'],
            'salary_min' => $data['salary_min'] ?? null,
            'salary_max' => $data['salary_max'] ?? null,
            'salary_currency' => $data['salary_currency'] ?? null,
            'description' => $data['description'] ?? null,
            'requirements' => $data['requirements'] ?? null,
            'apply_url' => $data['apply_url'] ?? null,
            'source_label' => 'NurseLink Partner Portal',
            'status' => 'paused',
            'published_at' => null,
            'expires_at' => $data['expires_at'] ?? null,
            'verified_by' => null,
            'verified_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $after = DB::table('nurselink_job_opportunities')->where('id', $id)->first();

        $this->audit($request, $access, 'opportunity.created', 'job_opportunity', (string) $id, null, $after);

        return response()->json([
            'message' => 'Opportunity submitted for NurseLink verification.',
            'data' => $this->presentJob($after),
        ], 201);
    }

    public function updateOpportunity(Request $request, int $id): JsonResponse
    {
        $access = $this->authorizePartner($request, true);

        $before = DB::table('nurselink_job_opportunities')
            ->where('id', $id)
            ->where('partner_organization_id', $access['organization']->id)
            ->first();

        abort_unless($before, 404);

        $data = $this->validatedJob($request);
        $requestedStatus = $request->string('partner_status')->toString();

        $newStatus = $requestedStatus === 'closed' ? 'closed' : 'paused';

        DB::table('nurselink_job_opportunities')
            ->where('id', $id)
            ->where('partner_organization_id', $access['organization']->id)
            ->update([
                'title' => $data['title'],
                'employer_name' => $access['organization']->name,
                'country' => $data['country'],
                'city' => $data['city'] ?? null,
                'work_setting' => $data['work_setting'] ?? null,
                'employment_type' => $data['employment_type'] ?? null,
                'specialty' => $data['specialty'] ?? null,
                'required_license_type' => $data['required_license_type'] ?? null,
                'minimum_experience_years' => $data['minimum_experience_years'],
                'overseas_opportunity' => $data['overseas_opportunity'],
                'salary_min' => $data['salary_min'] ?? null,
                'salary_max' => $data['salary_max'] ?? null,
                'salary_currency' => $data['salary_currency'] ?? null,
                'description' => $data['description'] ?? null,
                'requirements' => $data['requirements'] ?? null,
                'apply_url' => $data['apply_url'] ?? null,
                'status' => $newStatus,
                'published_at' => $newStatus === 'closed' ? $before->published_at : null,
                'expires_at' => $data['expires_at'] ?? null,
                'verified_by' => $newStatus === 'closed' ? $before->verified_by : null,
                'verified_at' => $newStatus === 'closed' ? $before->verified_at : null,
                'updated_at' => now(),
            ]);

        $after = DB::table('nurselink_job_opportunities')->where('id', $id)->first();

        $this->audit($request, $access, 'opportunity.updated', 'job_opportunity', (string) $id, $before, $after);

        return response()->json([
            'message' => $newStatus === 'closed'
                ? 'Opportunity closed.'
                : 'Changes saved and returned to NurseLink for verification.',
            'data' => $this->presentJob($after),
        ]);
    }

    public function applications(Request $request): JsonResponse
    {
        $access = $this->authorizePartner($request);
        $orgId = (int) $access['organization']->id;

        $rows = DB::table('nurselink_job_applications as a')
            ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
            ->where('j.partner_organization_id', $orgId)
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
            ->limit(500)
            ->get([
                'a.id',
                'a.user_id',
                'a.status',
                'a.cover_note',
                'a.partner_notes',
                'a.partner_reviewed_at',
                'a.submitted_at',
                'a.withdrawn_at',
                'a.updated_at',
                'j.id as job_id',
                'j.reference_code',
                'j.title',
                'j.country',
                'j.city',
                'j.specialty',
            ]);

        $candidateMap = $this->candidateMap($rows->pluck('user_id')->all());

        return response()->json([
            'data' => $rows->map(function ($row) use ($candidateMap): array {
                $candidate = $candidateMap[(string) $row->user_id] ?? [
                    'name' => 'NurseLink Member',
                    'member_number' => null,
                    'public_profile_url' => null,
                ];

                return [
                    'id' => (int) $row->id,
                    'status' => $row->status,
                    'cover_note' => $row->cover_note,
                    'partner_notes' => $row->partner_notes,
                    'partner_reviewed_at' => $row->partner_reviewed_at,
                    'submitted_at' => $row->submitted_at,
                    'withdrawn_at' => $row->withdrawn_at,
                    'updated_at' => $row->updated_at,
                    'job' => [
                        'id' => (int) $row->job_id,
                        'reference_code' => $row->reference_code,
                        'title' => $row->title,
                        'country' => $row->country,
                        'city' => $row->city,
                        'specialty' => $row->specialty,
                    ],
                    'candidate' => $candidate,
                ];
            })->values(),
            'privacy' => [
                'scope' => 'Applicants to this partner organization only',
                'excluded' => [
                    'home address',
                    'mobile number',
                    'email address',
                    'credential numbers',
                    'uploaded documents',
                    'private portfolio items',
                ],
            ],
        ]);
    }

    public function updateApplication(Request $request, int $id): JsonResponse
    {
        $access = $this->authorizePartner($request, true);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in([
                'under_review',
                'shortlisted',
                'interview',
                'offer',
                'declined',
            ])],
            'partner_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $before = DB::table('nurselink_job_applications as a')
            ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
            ->where('a.id', $id)
            ->where('j.partner_organization_id', $access['organization']->id)
            ->first([
                'a.id',
                'a.user_id',
                'a.status',
                'a.partner_notes',
                'a.job_opportunity_id',
            ]);

        abort_unless($before, 404);

        if ($before->status === 'withdrawn') {
            return response()->json([
                'message' => 'A withdrawn application cannot be moved forward.',
            ], 422);
        }

        DB::table('nurselink_job_applications')
            ->where('id', $id)
            ->update([
                'status' => $data['status'],
                'partner_notes' => $data['partner_notes'] ?? null,
                'partner_reviewed_by' => (string) $request->user()->getKey(),
                'partner_reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        $after = DB::table('nurselink_job_applications')->where('id', $id)->first();

        $this->audit($request, $access, 'application.status_changed', 'job_application', (string) $id, $before, $after);

        DB::table('nurselink_notifications')->insert([
            'user_id' => $before->user_id,
            'type' => 'partner_job_application.' . $data['status'],
            'severity' => $data['status'] === 'offer'
                ? 'success'
                : ($data['status'] === 'declined' ? 'error' : 'info'),
            'title' => 'Employer application update',
            'message' => 'A NurseLink partner updated your job application to '
                . str_replace('_', ' ', $data['status']) . '.',
            'action_url' => '/applications',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Application status updated.',
            'data' => [
                'id' => (int) $after->id,
                'status' => $after->status,
                'partner_notes' => $after->partner_notes,
                'partner_reviewed_at' => $after->partner_reviewed_at,
            ],
        ]);
    }

    public function auditLog(Request $request): JsonResponse
    {
        $access = $this->authorizePartner($request, true);

        $rows = DB::table('nurselink_partner_audit')
            ->where('partner_organization_id', $access['organization']->id)
            ->orderByDesc('created_at')
            ->limit(250)
            ->get();

        return response()->json(['data' => $rows]);
    }

    private function authorizePartner(Request $request, bool $write = false): array
    {
        $user = $request->user();
        abort_unless($user, 401);

        $access = DB::table('nurselink_partner_access')
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->first();

        abort_unless($access, 403, 'NurseLink partner access is required.');

        $organization = DB::table('nurselink_partner_organizations')
            ->where('id', $access->partner_organization_id)
            ->where('status', 'verified')
            ->first();

        abort_unless($organization, 403, 'Verified partner organization access is required.');

        $role = strtolower((string) $access->role);

        abort_unless(in_array($role, ['viewer', 'recruiter', 'manager'], true), 403);

        if ($write) {
            abort_unless(in_array($role, ['recruiter', 'manager'], true), 403, 'Recruiter or manager access is required.');
        }

        return [
            'role' => $role,
            'organization' => $organization,
        ];
    }

    private function validatedJob(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:190'],
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
            'required_license_type' => ['nullable', 'string', 'max:80'],
            'minimum_experience_years' => ['required', 'numeric', 'min:0', 'max:99'],
            'overseas_opportunity' => ['required', 'boolean'],
            'salary_min' => ['nullable', 'numeric', 'min:0'],
            'salary_max' => ['nullable', 'numeric', 'gte:salary_min'],
            'salary_currency' => ['nullable', 'string', 'max:8'],
            'description' => ['nullable', 'string', 'max:12000'],
            'requirements' => ['nullable', 'string', 'max:12000'],
            'apply_url' => ['nullable', 'url', 'max:512'],
            'expires_at' => ['nullable', 'date'],
            'partner_status' => ['nullable', 'string', Rule::in(['submit_for_review', 'closed'])],
        ]);
    }

    private function presentOrganization(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'organization_type' => $row->organization_type,
            'country' => $row->country,
            'city' => $row->city,
            'website' => $row->website,
            'status' => $row->status,
            'verified_at' => $row->verified_at,
        ];
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
            'status' => $row->status,
            'verified' => ! empty($row->verified_at),
            'verified_at' => $row->verified_at,
            'published_at' => $row->published_at,
            'expires_at' => $row->expires_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function candidateMap(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn ($id) => (string) $id, $userIds)
        )));

        if ($ids === []) return [];

        $columns = ['id'];

        foreach (['name', 'first_name', 'last_name'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $columns[] = $column;
            }
        }

        $users = DB::table('users')
            ->whereIn('id', $ids)
            ->get($columns)
            ->keyBy(fn ($row) => (string) $row->id);

        $memberships = DB::table('nurselink_memberships')
            ->whereIn('user_id', $ids)
            ->where('status', 'approved')
            ->get(['user_id', 'member_number'])
            ->keyBy(fn ($row) => (string) $row->user_id);

        $profiles = DB::table('nurselink_public_profiles')
            ->whereIn('user_id', $ids)
            ->where('enabled', true)
            ->get(['user_id', 'slug'])
            ->keyBy(fn ($row) => (string) $row->user_id);

        $map = [];

        foreach ($ids as $id) {
            $user = $users->get($id);

            if (! $user) continue;

            $name = trim((string) ($user->name ?? ''));

            if ($name === '') {
                $name = trim(
                    (string) ($user->first_name ?? '')
                    . ' '
                    . (string) ($user->last_name ?? '')
                );
            }

            $membership = $memberships->get($id);
            $profile = $profiles->get($id);

            $map[$id] = [
                'name' => $name !== '' ? $name : 'NurseLink Member',
                'member_number' => $membership->member_number ?? null,
                'public_profile_url' => $profile
                    ? 'https://app.amsertech.com/nurselink-public-profile.html?slug='
                        . rawurlencode($profile->slug)
                    : null,
            ];
        }

        return $map;
    }

    private function audit(
        Request $request,
        array $access,
        string $action,
        string $targetType,
        ?string $targetId,
        mixed $before,
        mixed $after
    ): void {
        DB::table('nurselink_partner_audit')->insert([
            'user_id' => (string) $request->user()->getKey(),
            'partner_organization_id' => (int) $access['organization']->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before_state' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'after_state' => $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(),
        ]);
    }
}
