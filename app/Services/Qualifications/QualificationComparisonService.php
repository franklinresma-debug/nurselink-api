<?php
namespace App\Services\Qualifications;
use App\Models\FrameworkLevelCrosswalk; use App\Models\QualificationFramework;
class QualificationComparisonService
{
    public function compare(QualificationFramework $source,QualificationFramework $target):array
    {
        $crosswalks=FrameworkLevelCrosswalk::query()->where('governance_status','published')
            ->whereHas('sourceLevel',fn($q)=>$q->where('framework_id',$source->id))
            ->whereHas('targetLevel',fn($q)=>$q->where('framework_id',$target->id))
            ->with(['sourceLevel','targetLevel'])->get();
        return ['source'=>$source->only(['id','code','name']),'target'=>$target->only(['id','code','name']),'approved_crosswalks'=>$crosswalks,
            'automatic_equivalence'=>false,'disclaimer'=>config('qualification.disclaimer')];
    }
}
