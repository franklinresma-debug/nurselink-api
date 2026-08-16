<?php
namespace App\Services\Organization;
use App\Models\CommunicationTriggerEvent; use App\Models\Initiative; use App\Models\InitiativeUpdate; use App\Models\PolicyRecord; use App\Models\PolicyStageEvent; use App\Services\Communications\AudienceResolver;
class OrganizationCommunicationService {
 public function __construct(private AudienceResolver $audience){}
 public function initiativeUpdate(Initiative $i,InitiativeUpdate $u,array $filters):int{return $this->emit($filters,'initiative_update','initiative_update',$u->id,['initiative_no'=>$i->initiative_no,'initiative_title'=>$i->title,'update_title'=>$u->title,'update_body'=>$u->body]);}
 public function policyStageChanged(PolicyRecord $p,PolicyStageEvent $e,array $filters):int{return $this->emit($filters,'policy_stage_changed','policy_stage_event',$e->id,['policy_no'=>$p->policy_no,'policy_title'=>$p->title,'policy_stage'=>$e->to_status,'note'=>$e->note]);}
 private function emit(array $filters,string $eventType,string $sourceType,string $sourceId,array $payload):int{$users=$this->audience->resolve($filters);$count=0;foreach($users as $u){$row=CommunicationTriggerEvent::query()->firstOrCreate(['user_id'=>$u->id,'event_type'=>$eventType,'source_type'=>$sourceType,'source_id'=>$sourceId],['payload'=>$payload,'status'=>'pending','occurred_at'=>now()]);if($row->wasRecentlyCreated)$count++;}return $count;}
}
