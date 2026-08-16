<?php
namespace App\Services\Qualifications;

use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class QualificationEvidenceResolver
{
    public function __construct(private EvidenceTrustService $trust) {}

    public function resolve(Member $member, array $rule): array
    {
        $source=(string)($rule['source']??'');
        $items=$this->items($member,$source);
        $filters=(array)($rule['filters']??[]);
        $items=$items->filter(fn(Model $item)=>$this->matches($item,$filters));
        if($source==='credential' && !empty($rule['acceptable_validity'])){
            $allowed=array_map('strval',(array)$rule['acceptable_validity']);
            $items=$items->filter(fn(Model $item)=>in_array((string)$item->credential_status,$allowed,true));
        }
        $snapshots=$items->map(fn(Model $item)=>$this->snapshot($source,$item))->values();
        return [
            'source'=>$source,
            'count'=>$snapshots->count(),
            'highest_trust_level'=>(int)($snapshots->max('trust_level')??0),
            'experience_years'=>$source==='employment'?$this->experienceYears($items):null,
            'items'=>$snapshots->all(),
        ];
    }

    private function items(Member $member,string $source):Collection
    {
        return match($source){
            'education'=>$member->education()->get(),
            'employment'=>$member->employment()->get(),
            'competency'=>$member->competencies()->get(),
            'credential'=>$member->credentials()->get(),
            'professional_development'=>$member->professionalDevelopment()->get(),
            'language'=>$member->languages()->get(),
            'technology'=>$member->technologySkills()->get(),
            default=>collect(),
        };
    }

    private function matches(Model $item,array $filters):bool
    {
        foreach($filters as $field=>$expected){
            if(str_ends_with($field,'_contains')){
                $actualField=substr($field,0,-9); $actual=strtolower((string)data_get($item,$actualField));
                if(!str_contains($actual,strtolower((string)$expected))) return false;
                continue;
            }
            $actual=data_get($item,$field);
            if(is_array($expected)){ if(!in_array($actual,$expected,true)) return false; }
            elseif(strtolower((string)$actual)!==strtolower((string)$expected)) return false;
        }
        return true;
    }

    private function snapshot(string $source,Model $item):array
    {
        $state=match($source){
            'credential'=>$item->verification_status,
            'competency'=>$item->evidence_state,
            default=>$item->status??'self_declared',
        };
        $title=match($source){
            'education'=>$item->qualification.' — '.$item->institution,
            'employment'=>$item->position_title.' — '.$item->employer,
            'competency'=>$item->name,
            'credential'=>$item->title,
            'professional_development'=>$item->title,
            'language'=>$item->language,
            'technology'=>$item->name,
            default=>$item->getKey(),
        };
        return ['type'=>$source,'id'=>$item->getKey(),'title'=>$title,'state'=>$state,'trust_level'=>$this->trust->score($state),'updated_at'=>optional($item->updated_at)->toIso8601String()];
    }

    private function experienceYears(Collection $items):float
    {
        $days=$items->sum(function(Model $row){
            if(!$row->started_on) return 0;
            $start=CarbonImmutable::parse($row->started_on); $end=$row->ended_on?CarbonImmutable::parse($row->ended_on):CarbonImmutable::today();
            return max(0,$start->diffInDays($end));
        });
        return round($days/365.25,2);
    }
}
