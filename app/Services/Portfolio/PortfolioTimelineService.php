<?php
namespace App\Services\Portfolio;
use App\Models\Member;
class PortfolioTimelineService
{
    public function rebuild(Member $member): void
    {
        $member->timelineEvents()->delete();
        foreach($member->education()->get() as $row){
            $member->timelineEvents()->create(['event_type'=>'education','title'=>$row->qualification.' — '.$row->institution,'description'=>$row->field_of_study,'occurred_on'=>$row->completed_on ?? $row->started_on,'source_type'=>'portfolio_education','source_id'=>$row->id]);
        }
        foreach($member->employment()->get() as $row){
            $member->timelineEvents()->create(['event_type'=>'employment','title'=>$row->position_title.' — '.$row->employer,'description'=>$row->country,'occurred_on'=>$row->started_on,'source_type'=>'portfolio_employment','source_id'=>$row->id]);
        }
    }
}
