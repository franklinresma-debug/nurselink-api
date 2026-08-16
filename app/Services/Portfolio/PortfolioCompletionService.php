<?php
namespace App\Services\Portfolio;
use App\Models\Member;
class PortfolioCompletionService
{
    public function calculate(Member $member): int
    {
        $member->loadMissing(['portfolioSummary','education','employment','specialties','competencies','technologySkills','languages','credentials','professionalDevelopment']);
        $checks = [
            filled($member->portfolioSummary?->professional_headline),
            filled($member->portfolioSummary?->professional_summary),
            $member->education->isNotEmpty(),
            $member->employment->isNotEmpty(),
            $member->specialties->isNotEmpty(),
            $member->competencies->count() >= 3,
            $member->technologySkills->isNotEmpty(),
            $member->languages->isNotEmpty(),
            $member->credentials->isNotEmpty(),
            $member->professionalDevelopment->isNotEmpty(),
        ];
        return (int) round((collect($checks)->filter()->count() / count($checks)) * 100);
    }

    public function refresh(Member $member): int
    {
        $percent = $this->calculate($member);
        $member->portfolioSummary()->updateOrCreate(['member_id'=>$member->id], ['completion_percent'=>$percent]);
        return $percent;
    }
}
