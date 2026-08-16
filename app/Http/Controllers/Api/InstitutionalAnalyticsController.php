<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InstitutionalAnalyticsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $months = max(3, min(24, (int) $request->integer('months', 12)));

        $partners = DB::table('nurselink_partner_organizations')
            ->orderBy('name')
            ->get();

        $rows = $partners->map(function ($org): array {
            $jobs = DB::table('nurselink_job_opportunities')
                ->where('partner_organization_id', $org->id);

            $applications = DB::table('nurselink_job_applications as a')
                ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
                ->where('j.partner_organization_id', $org->id);

            $applicationCount = (clone $applications)->count();

            $interviewCount = (clone $applications)
                ->whereIn('a.status', ['interview', 'offer'])
                ->count();

            $offerCount = (clone $applications)
                ->where('a.status', 'offer')
                ->count();

            $cohortQuery = Schema::hasTable('nurselink_enterprise_cohorts')
                ? DB::table('nurselink_enterprise_cohorts')
                    ->where('partner_organization_id', $org->id)
                : null;

            $cohortIds = $cohortQuery
                ? (clone $cohortQuery)->pluck('id')
                : collect();

            $assignmentQuery = (
                Schema::hasTable('nurselink_enterprise_cohort_members')
                && $cohortIds->isNotEmpty()
            )
                ? DB::table('nurselink_enterprise_cohort_members')
                    ->whereIn('cohort_id', $cohortIds)
                : null;

            return [
                'organization_id' => (int) $org->id,
                'organization' => $org->name,
                'organization_type' => $org->organization_type,
                'country' => $org->country,
                'status' => $org->status,
                'opportunities' => (clone $jobs)->count(),
                'active_opportunities' => (clone $jobs)->where('status', 'active')->count(),
                'applications' => $applicationCount,
                'interviews' => $interviewCount,
                'offers' => $offerCount,
                'interview_rate' => $this->rate($interviewCount, $applicationCount),
                'offer_rate' => $this->rate($offerCount, $applicationCount),
                'enterprise_cohorts' =>
                    $cohortQuery
                        ? (clone $cohortQuery)->count()
                        : 0,
                'enterprise_active_cohorts' =>
                    $cohortQuery
                        ? (clone $cohortQuery)
                            ->where('status', 'active')
                            ->count()
                        : 0,
                'enterprise_assignments' =>
                    $assignmentQuery
                        ? (clone $assignmentQuery)->count()
                        : 0,
                'enterprise_active_assignments' =>
                    $assignmentQuery
                        ? (clone $assignmentQuery)
                            ->where('status', 'active')
                            ->count()
                        : 0,
            ];
        })->values();

        return response()->json([
            'data' => [
                'summary' => [
                    'partners_total' => $partners->count(),
                    'partners_verified' => $partners->where('status', 'verified')->count(),
                    'opportunities_total' => DB::table('nurselink_job_opportunities')
                        ->whereNotNull('partner_organization_id')
                        ->count(),
                    'opportunities_active' => DB::table('nurselink_job_opportunities')
                        ->whereNotNull('partner_organization_id')
                        ->where('status', 'active')
                        ->count(),
                    'applications_total' => DB::table('nurselink_job_applications as a')
                        ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
                        ->whereNotNull('j.partner_organization_id')
                        ->count(),
                    'interviews_total' => DB::table('nurselink_interviews')->count(),
                    'offers_total' => DB::table('nurselink_job_applications as a')
                        ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
                        ->whereNotNull('j.partner_organization_id')
                        ->where('a.status', 'offer')
                        ->count(),
                    'enterprise_cohorts_total' =>
                        Schema::hasTable('nurselink_enterprise_cohorts')
                            ? DB::table('nurselink_enterprise_cohorts')->count()
                            : 0,
                    'enterprise_cohorts_active' =>
                        Schema::hasTable('nurselink_enterprise_cohorts')
                            ? DB::table('nurselink_enterprise_cohorts')
                                ->where('status', 'active')
                                ->count()
                            : 0,
                    'enterprise_assignments_total' =>
                        Schema::hasTable('nurselink_enterprise_cohort_members')
                            ? DB::table('nurselink_enterprise_cohort_members')->count()
                            : 0,
                    'enterprise_assignments_active' =>
                        Schema::hasTable('nurselink_enterprise_cohort_members')
                            ? DB::table('nurselink_enterprise_cohort_members')
                                ->where('status', 'active')
                                ->count()
                            : 0,
                ],
                'partners' => $rows,
                'monthly' => $this->monthlySeries($months),
                'generated_at' => now()->toIso8601String(),
            ],
            'privacy' => [
                'aggregate_only' => true,
                'candidate_identity_included' => false,
                'candidate_contacts_included' => false,
                'candidate_documents_included' => false,
                'enterprise_member_identity_included' => false,
                'enterprise_internal_notes_included' => false,
            ],
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless($user, 401);

        $access = DB::table('nurselink_reviewer_access')
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->where('role', 'admin')
            ->first();

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

        abort_unless(
            $access
            || $modelAdmin
            || in_array($modelRole, ['admin', 'administrator', 'super_admin'], true),
            403,
            'NurseLink administrator access is required.'
        );
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0
            ? round(($numerator / $denominator) * 100, 1)
            : 0.0;
    }

    private function monthlySeries(int $months): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $applications = DB::table('nurselink_job_applications as a')
            ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
            ->whereNotNull('j.partner_organization_id')
            ->where('a.created_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(a.created_at, '%Y-%m') as month_key, COUNT(*) as count")
            ->groupBy('month_key')
            ->pluck('count', 'month_key');

        $interviews = DB::table('nurselink_interviews')
            ->where('created_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month_key, COUNT(*) as count")
            ->groupBy('month_key')
            ->pluck('count', 'month_key');

        $offers = DB::table('nurselink_job_applications as a')
            ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
            ->whereNotNull('j.partner_organization_id')
            ->where('a.status', 'offer')
            ->where('a.updated_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(a.updated_at, '%Y-%m') as month_key, COUNT(*) as count")
            ->groupBy('month_key')
            ->pluck('count', 'month_key');

        $series = [];

        for ($i = 0; $i < $months; $i++) {
            $date = $start->copy()->addMonths($i);
            $key = $date->format('Y-m');

            $series[] = [
                'month' => $date->format('M Y'),
                'month_key' => $key,
                'applications' => (int) ($applications[$key] ?? 0),
                'interviews' => (int) ($interviews[$key] ?? 0),
                'offers' => (int) ($offers[$key] ?? 0),
            ];
        }

        return $series;
    }
}
