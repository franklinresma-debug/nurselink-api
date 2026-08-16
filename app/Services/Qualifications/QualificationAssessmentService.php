<?php
namespace App\Services\Qualifications;

use App\Models\QualificationAssessment;
use App\Models\QualificationRecommendation;
use Illuminate\Support\Facades\DB;

class QualificationAssessmentService
{
    public function __construct(private QualificationRequirementEvaluator $evaluator){}

    public function evaluate(QualificationAssessment $assessment):QualificationAssessment
    {
        return DB::transaction(function()use($assessment){
            $assessment->load(['member','framework','targetLevel']);
            $requirements=$assessment->framework->requirements()
                ->where('governance_status','published')
                ->when($assessment->target_level_id,fn($q)=>$q->where(fn($qq)=>$qq->whereNull('framework_level_id')->orWhere('framework_level_id',$assessment->target_level_id)))
                ->orderByDesc('mandatory')->orderBy('category')->get();

            $assessment->results()->delete(); $assessment->recommendations()->delete();
            $weighted=0.0; $weightTotal=0.0; $counts=['met'=>0,'partial'=>0,'gap'=>0]; $snapshot=[];
            foreach($requirements as $requirement){
                $evaluation=$this->evaluator->evaluate($assessment->member,$requirement);
                $assessment->results()->create(array_merge(['requirement_id'=>$requirement->id],$evaluation));
                $weight=max(0.01,(float)$requirement->weight); $weighted += ($evaluation['score']/100)*$weight; $weightTotal += $weight; $counts[$evaluation['result']]++;
                $snapshot[]=['requirement'=>$requirement->id,'result'=>$evaluation['result'],'items'=>array_column($evaluation['evidence_snapshot'],'id')];
                if(in_array($evaluation['result'],['partial','gap'],true)){
                    $assessment->recommendations()->create(['requirement_id'=>$requirement->id,'priority'=>$requirement->mandatory?1:3,'category'=>$requirement->category,'title'=>($evaluation['result']==='gap'?'Complete: ':'Strengthen: ').$requirement->title,'description'=>$evaluation['rationale'],'action_type'=>'evidence']);
                }
            }
            $score=$weightTotal>0?round(($weighted/$weightTotal)*100,2):null;
            $label=$score===null?'framework_rules_not_published':$this->readinessLabel($score);
            $assessment->update([
                'status'=>'system_ready','readiness_score'=>$score,'readiness_label'=>$label,'requirements_total'=>$requirements->count(),
                'requirements_met'=>$counts['met'],'requirements_partial'=>$counts['partial'],'requirements_gap'=>$counts['gap'],
                'evidence_snapshot_hash'=>hash('sha256',json_encode($snapshot,JSON_UNESCAPED_SLASHES)),'evaluated_at'=>now(),
            ]);
            return $assessment->fresh(['framework','targetLevel','results.requirement','recommendations']);
        });
    }

    public function submit(QualificationAssessment $assessment,?string $note=null):QualificationAssessment
    {
        abort_unless($assessment->status==='system_ready',422,'Assessment must be evaluated before submission.');
        $assessment->update(['status'=>'submitted','submitted_at'=>now(),'member_note'=>$note]);
        return $assessment->fresh();
    }

    private function readinessLabel(float $score):string
    {
        foreach(config('qualification.readiness_bands',[]) as $band) if($score >= (float)$band['min']) return (string)$band['label'];
        return 'significant_evidence_gaps';
    }
}
