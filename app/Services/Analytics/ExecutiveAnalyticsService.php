<?php
namespace App\Services\Analytics;

use App\Models\Application;
use App\Models\DeliveryAttempt;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Initiative;
use App\Models\InitiativeBeneficiary;
use App\Models\InitiativeBudgetLine;
use App\Models\Member;
use App\Models\PolicyRecord;
use App\Models\PortfolioSummary;
use App\Models\ProfessionalCredential;
use App\Models\QualificationAssessment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ExecutiveAnalyticsService
{
    public function snapshot(bool $fresh = false): array
    {
        $key = 'nurselink:analytics:executive:v'.config('analytics.metric_version','1.0');
        if ($fresh) Cache::forget($key);
        return Cache::remember($key, config('analytics.cache_seconds', 300), fn () => $this->compute());
    }

    public function compute(): array
    {
        $members = Member::query();
        $applications = Application::query();
        $credentials = ProfessionalCredential::query();
        $assessments = QualificationAssessment::query();
        $events = Event::query();
        $registrations = EventRegistration::query();
        $initiatives = Initiative::query();
        $policies = PolicyRecord::query();

        $approvalHours = (float) (Application::query()
            ->whereNotNull('submitted_at')->whereNotNull('approved_at')
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (approved_at - submitted_at)) / 3600) AS avg_hours")
            ->value('avg_hours') ?? 0);

        $deliveries = DeliveryAttempt::query();
        $attempted = (clone $deliveries)->count();
        $delivered = (clone $deliveries)->where('status','delivered')->count();

        $budgetPlanned = (float) InitiativeBudgetLine::query()->sum('planned_amount');
        $budgetSpent = (float) InitiativeBudgetLine::query()->sum('spent_amount');

        return [
            'membership' => [
                'members_total' => (clone $members)->count(),
                'members_active' => (clone $members)->where('status','active')->count(),
                'applications_open' => (clone $applications)->whereNotIn('status',['approved','rejected'])->count(),
                'applications_submitted' => (clone $applications)->whereIn('status',['submitted','under_review','resubmitted'])->count(),
                'approval_turnaround_hours' => round($approvalHours, 1),
                'avg_profile_completion' => round((float)(PortfolioSummary::query()->avg('completion_percent') ?? 0),1),
            ],
            'credentials' => [
                'total' => (clone $credentials)->count(),
                'verified' => (clone $credentials)->where('verification_status','verified')->count(),
                'renewal_due' => (clone $credentials)->whereIn('credential_status',['renewal_due','expiring_soon','expiring_critical'])->count(),
                'expired' => (clone $credentials)->where('credential_status','expired')->count(),
            ],
            'qualifications' => [
                'assessments_total' => (clone $assessments)->count(),
                'avg_readiness_score' => round((float)((clone $assessments)->whereNotNull('readiness_score')->avg('readiness_score') ?? 0),1),
                'awaiting_assessor' => (clone $assessments)->whereIn('status',['submitted','under_review'])->count(),
            ],
            'engagement' => [
                'published_events' => (clone $events)->where('status','published')->count(),
                'registrations' => (clone $registrations)->whereIn('status',['registered','attended'])->count(),
                'attendance' => (clone $registrations)->where('status','attended')->count(),
                'delivery_rate' => $attempted > 0 ? round(($delivered/$attempted)*100,1) : 0,
            ],
            'organization' => [
                'active_initiatives' => (clone $initiatives)->where('status','active')->count(),
                'avg_initiative_progress' => round((float)((clone $initiatives)->whereIn('status',['planning','active','on_hold'])->avg('progress_percent') ?? 0),1),
                'beneficiaries_reached' => (int) InitiativeBeneficiary::query()->sum('actual_count'),
                'budget_planned' => round($budgetPlanned,2),
                'budget_spent' => round($budgetSpent,2),
                'budget_utilization' => $budgetPlanned > 0 ? round(($budgetSpent/$budgetPlanned)*100,1) : 0,
                'policies_in_pipeline' => (clone $policies)->whereNotIn('status',['adopted','rejected'])->count(),
                'policies_adopted' => (clone $policies)->where('status','adopted')->count(),
            ],
            'geography' => $this->safeCountryDistribution(),
            'generated_at' => now()->toIso8601String(),
            'metric_version' => config('analytics.metric_version','1.0'),
        ];
    }

    private function safeCountryDistribution(): array
    {
        $min = max(1, (int) config('analytics.minimum_group_size',5));
        return DB::table('member_profiles')
            ->selectRaw("COALESCE(NULLIF(country,''),'Unspecified') as country, COUNT(*) as members")
            ->groupBy('country')->orderByDesc('members')->get()
            ->map(fn($r)=>['country'=>$r->country,'members'=>(int)$r->members])
            ->filter(fn($r)=>$r['members'] >= $min)->values()->all();
    }
}
