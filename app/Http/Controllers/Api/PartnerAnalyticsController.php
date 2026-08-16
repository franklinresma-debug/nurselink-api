<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerAnalyticsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $scope = $this->authorizePartner($request);

        $months = max(3, min(24, (int) $request->integer('months', 12)));
        $orgId = (int) $scope['organization']->id;

        $jobs = DB::table('nurselink_job_opportunities')
            ->where('partner_organization_id', $orgId);

        $applications = DB::table('nurselink_job_applications as a')
            ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
            ->where('j.partner_organization_id', $orgId);

        $totalApplications = (clone $applications)->count();

        $statusCounts = [];
        foreach ([
            'submitted',
            'under_review',
            'shortlisted',
            'interview',
            'offer',
            'declined',
            'withdrawn',
        ] as $status) {
            $statusCounts[$status] = (clone $applications)
                ->where('a.status', $status)
                ->count();
        }

        $reviewedCount = (clone $applications)
            ->whereNotNull('a.partner_reviewed_at')
            ->count();

        $avgReviewHours = (clone $applications)
            ->whereNotNull('a.partner_reviewed_at')
            ->whereNotNull('a.submitted_at')
            ->avg(DB::raw(
                'TIMESTAMPDIFF(MINUTE, a.submitted_at, a.partner_reviewed_at) / 60'
            ));

        $funnel = [
            'applications' => $totalApplications,
            'under_review_or_beyond' => $this->countStatuses($applications, [
                'under_review',
                'shortlisted',
                'interview',
                'offer',
                'declined',
            ]),
            'shortlisted_or_beyond' => $this->countStatuses($applications, [
                'shortlisted',
                'interview',
                'offer',
            ]),
            'interview_or_beyond' => $this->countStatuses($applications, [
                'interview',
                'offer',
            ]),
            'offers' => $statusCounts['offer'],
        ];

        return response()->json([
            'data' => [
                'organization' => [
                    'id' => $orgId,
                    'name' => $scope['organization']->name,
                    'country' => $scope['organization']->country,
                    'city' => $scope['organization']->city,
                    'organization_type' => $scope['organization']->organization_type,
                ],
                'period' => [
                    'months' => $months,
                    'generated_at' => now()->toIso8601String(),
                ],
                'headline' => [
                    'opportunities_total' => (clone $jobs)->count(),
                    'opportunities_active' => (clone $jobs)->where('status', 'active')->count(),
                    'applications_total' => $totalApplications,
                    'applications_reviewed' => $reviewedCount,
                    'shortlisted' => $statusCounts['shortlisted'],
                    'interviews' => $statusCounts['interview'],
                    'offers' => $statusCounts['offer'],
                    'declined' => $statusCounts['declined'],
                    'withdrawn' => $statusCounts['withdrawn'],
                ],
                'conversion' => [
                    'review_rate' => $this->rate($reviewedCount, $totalApplications),
                    'shortlist_rate' => $this->rate(
                        $funnel['shortlisted_or_beyond'],
                        $totalApplications
                    ),
                    'interview_rate' => $this->rate(
                        $funnel['interview_or_beyond'],
                        $totalApplications
                    ),
                    'offer_rate' => $this->rate(
                        $funnel['offers'],
                        $totalApplications
                    ),
                ],
                'timing' => [
                    'average_time_to_review_hours' => $avgReviewHours !== null
                        ? round((float) $avgReviewHours, 1)
                        : null,
                    'average_time_to_first_interview_hours' =>
                        $this->averageInterviewTurnaroundHours($orgId),
                ],
                'funnel' => $funnel,
                'status_counts' => $statusCounts,
                'monthly' => $this->monthlySeries($orgId, $months),
                'opportunities' => $this->opportunityPerformance($orgId),
            ],
            'privacy' => [
                'aggregate_only' => true,
                'candidate_identity_included' => false,
                'candidate_contacts_included' => false,
                'documents_included' => false,
                'credentials_included' => false,
            ],
        ]);
    }

    private function authorizePartner(Request $request): array
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

        abort_unless(
            $organization,
            403,
            'Verified partner organization access is required.'
        );

        $role = strtolower((string) $access->role);

        abort_unless(
            in_array($role, ['viewer', 'recruiter', 'manager'], true),
            403
        );

        return [
            'role' => $role,
            'organization' => $organization,
        ];
    }

    private function countStatuses($query, array $statuses): int
    {
        return (clone $query)
            ->whereIn('a.status', $statuses)
            ->count();
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0
            ? round(($numerator / $denominator) * 100, 1)
            : 0.0;
    }

    private function monthlySeries(int $orgId, int $months): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $applications = DB::table('nurselink_job_applications as a')
            ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
            ->where('j.partner_organization_id', $orgId)
            ->where('a.created_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(a.created_at, '%Y-%m') as month_key, COUNT(*) as count")
            ->groupBy('month_key')
            ->pluck('count', 'month_key');

        $interviews = DB::table('nurselink_interviews')
            ->where('partner_organization_id', $orgId)
            ->where('created_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month_key, COUNT(*) as count")
            ->groupBy('month_key')
            ->pluck('count', 'month_key');

        $offers = DB::table('nurselink_job_applications as a')
            ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
            ->where('j.partner_organization_id', $orgId)
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

    private function opportunityPerformance(int $orgId): array
    {
        $jobs = DB::table('nurselink_job_opportunities')
            ->where('partner_organization_id', $orgId)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get([
                'id',
                'reference_code',
                'title',
                'country',
                'city',
                'specialty',
                'status',
                'published_at',
                'expires_at',
            ]);

        return $jobs->map(function ($job): array {
            $applications = DB::table('nurselink_job_applications')
                ->where('job_opportunity_id', $job->id);

            $total = (clone $applications)->count();

            $shortlisted = (clone $applications)
                ->whereIn('status', ['shortlisted', 'interview', 'offer'])
                ->count();

            $interviews = (clone $applications)
                ->whereIn('status', ['interview', 'offer'])
                ->count();

            $offers = (clone $applications)
                ->where('status', 'offer')
                ->count();

            return [
                'id' => (int) $job->id,
                'reference_code' => $job->reference_code,
                'title' => $job->title,
                'country' => $job->country,
                'city' => $job->city,
                'specialty' => $job->specialty,
                'status' => $job->status,
                'published_at' => $job->published_at,
                'expires_at' => $job->expires_at,
                'applications' => $total,
                'shortlisted_or_beyond' => $shortlisted,
                'interview_or_beyond' => $interviews,
                'offers' => $offers,
                'interview_conversion_rate' => $this->rate($interviews, $total),
                'offer_conversion_rate' => $this->rate($offers, $total),
            ];
        })->values()->all();
    }

    private function averageInterviewTurnaroundHours(int $orgId): ?float
    {
        $firstInterviews = DB::table('nurselink_interviews')
            ->selectRaw('job_application_id, MIN(created_at) as first_interview_created')
            ->groupBy('job_application_id');

        $rows = DB::table('nurselink_job_applications as a')
            ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
            ->joinSub($firstInterviews, 'fi', function ($join): void {
                $join->on('fi.job_application_id', '=', 'a.id');
            })
            ->where('j.partner_organization_id', $orgId)
            ->whereNotNull('a.submitted_at')
            ->selectRaw(
                'TIMESTAMPDIFF(MINUTE, a.submitted_at, fi.first_interview_created) / 60 as hours'
            )
            ->pluck('hours')
            ->filter(fn ($value) => $value !== null && (float) $value >= 0);

        return $rows->isEmpty()
            ? null
            : round((float) $rows->avg(), 1);
    }
}
