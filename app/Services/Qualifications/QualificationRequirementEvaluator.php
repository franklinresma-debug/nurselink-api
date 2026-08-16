<?php
namespace App\Services\Qualifications;
use App\Models\Member; use App\Models\QualificationRequirement;
class QualificationRequirementEvaluator
{
    public function __construct(private QualificationEvidenceResolver $resolver){}
    public function evaluate(Member $member,QualificationRequirement $requirement):array
    {
        $rule=(array)$requirement->evidence_rule;
        $evidence=$this->resolver->resolve($member,$rule);
        $minimumCount=max(1,(int)($rule['minimum_count']??1));
        $minimumYears=(float)($rule['minimum_years']??0);
        $countOk=$evidence['count'] >= $minimumCount;
        $trustOk=$evidence['highest_trust_level'] >= (int)$requirement->minimum_trust_level;
        $yearsOk=$minimumYears<=0 || (float)($evidence['experience_years']??0) >= $minimumYears;
        $result=($countOk&&$trustOk&&$yearsOk)?'met':($evidence['count']>0?'partial':'gap');
        $score=match($result){'met'=>100,'partial'=>50,default=>0};
        $reasons=[];
        if(!$countOk)$reasons[]="Need at least {$minimumCount} matching evidence item(s).";
        if(!$trustOk)$reasons[]='Evidence trust/provenance is below the configured minimum.';
        if(!$yearsOk)$reasons[]="Need at least {$minimumYears} year(s) of matching experience.";
        if(!$reasons)$reasons[]='Configured evidence rule is satisfied.';
        return ['result'=>$result,'score'=>$score,'evidence_count'=>$evidence['count'],'highest_trust_level'=>$evidence['highest_trust_level'],'experience_years'=>$evidence['experience_years'],'evidence_snapshot'=>$evidence['items'],'rationale'=>implode(' ',$reasons)];
    }
}
