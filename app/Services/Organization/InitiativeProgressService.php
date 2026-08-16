<?php
namespace App\Services\Organization;
use App\Models\Initiative;
class InitiativeProgressService {
 public function refresh(Initiative $initiative):Initiative{$items=$initiative->milestones()->get();if($items->isEmpty()){return $initiative;}$weighted=$items->sum(fn($m)=>(float)$m->weight)>0;$done=0.0;$total=0.0;foreach($items as $m){$w=$weighted?(float)$m->weight:1.0;$total+=$w;$factor=match($m->status){'completed'=>1.0,'in_progress'=>0.5,'blocked'=>0.25,default=>0.0};$done+=$w*$factor;}$pct=$total>0?round(($done/$total)*100,2):0;$initiative->update(['progress_percent'=>$pct]);return $initiative->refresh();}
}
