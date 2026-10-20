<?php
namespace App\Services;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class LbsProcessingService {
 public const STAGES=['opened','requirements_in_progress','readiness_review','ready_for_deployment','departure_confirmed','arrival_confirmed','follow_up','closed'];
 public static function canManage(string $id):bool {
  $u=User::find($id);if(!$u||$u->status!=='active')return false;
  $rows=DB::table('nurselink_admin_role_assignments')->where('user_id',$id)->get();
  if($rows->isNotEmpty())return $rows->contains(fn($r)=>(bool)$r->active&&$r->role_key==='super_administrator');
  return $u->hasRole('administrator')||$u->hasRole('super_administrator')||DB::table('nurselink_super_admin_access')->where('user_id',$id)->where('active',true)->exists();
 }
 private function locked(callable $fn){return DB::transaction(function()use($fn){DB::table('lbs_processing_lock')->where('id',1)->lockForUpdate()->first();return $fn();},3);}
 private function event(string $actor,string $action,?string $case,?string $offer,mixed $before,mixed $after,?string $reason=null):void {
  DB::table('lbs_processing_events')->insert(['actor_id'=>$actor,'action'=>$action,'case_id'=>$case,'offer_id'=>$offer,'before_data'=>json_encode($before),'after_data'=>json_encode($after),'reason'=>$reason,'created_at'=>now()]);
 }
 private function latest(int $app):?object {return DB::table('lbs_processing_offers')->where('application_id',$app)->orderByDesc('revision')->first();}
 private function eligible(object $c):void {
  $offer=$this->latest($c->application_id);
  abort_unless($offer&&$offer->id===$c->offer_id&&$offer->response==='accepted',409,'The current offer must be accepted by the applicant.');
  abort_unless(DB::table('nurselink_job_applications')->where('id',$c->application_id)->where('status','offer')->exists(),409,'The recruitment application must still have an active offer.');
 }
 private function version(object $c,int $version):void {abort_unless((int)$c->version===$version,409,'This case changed. Refresh before saving.');}
 private function updateCase(object $c,array $values):object {DB::table('lbs_processing_cases')->where('id',$c->id)->update($values+['version'=>$c->version+1,'updated_at'=>now()]);return DB::table('lbs_processing_cases')->where('id',$c->id)->first();}
 private function caseRow(string $id):object {$c=DB::table('lbs_processing_cases')->where('id',$id)->first();abort_unless($c,404);return $c;}
 private function preTravel(object $c):void {abort_unless(array_search($c->stage,self::STAGES)<4,409,'Reopen the case for a documented review before changing requirements after departure.');}
 private function invalidate(object $c,array $values=[]):object {
  return $this->updateCase($c,$values+['stage'=>$c->stage==='opened'?'opened':'requirements_in_progress','template_reviewed_at'=>null,'template_reviewed_by'=>null,'ready_at'=>null,'ready_by'=>null]);
 }
 public function offer(array $v,string $actor):object {return $this->locked(function()use($v,$actor){
  $existing=DB::table('lbs_processing_offers')->where('request_id',$v['request_id'])->first();
  if($existing){abort_unless((int)$existing->application_id===(int)$v['application_id']&&$existing->created_by===$actor,409,'Request identifier already used.');return $existing;}
  $app=DB::table('nurselink_job_applications')->where('id',$v['application_id'])->first();abort_unless($app&&$app->status==='offer',422,'Select an application with an offer.');
  $case=DB::table('lbs_processing_cases')->where('application_id',$app->id)->first();if($case)$this->preTravel($case);
  $previous=$this->latest($app->id);if($previous)abort_unless(trim($v['change_reason']??'')!=='',422,'Explain the changed offer.');
  $id=(string)Str::uuid();$data=array_intersect_key($v,array_flip(['reference','employer','position','destination','terms','evidence_reference','request_id']));
  DB::table('lbs_processing_offers')->insert($data+['id'=>$id,'application_id'=>$app->id,'user_id'=>$app->user_id,'revision'=>($previous->revision??0)+1,'created_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
  $offer=DB::table('lbs_processing_offers')->where('id',$id)->first();
  if($case){
   $oldItems=DB::table('lbs_processing_items')->where('case_id',$case->id)->get();
   DB::table('lbs_processing_items')->where('case_id',$case->id)->update(['classification'=>'unreviewed','status'=>'under_review','verified_by'=>null,'verified_at'=>null,'updated_at'=>now()]);
   $next=$this->invalidate($case,['offer_id'=>$id,'state'=>'on_hold','reason'=>'Changed offer: '.$v['change_reason']]);
   $this->event($actor,'offer_changed_review_reset',$case->id,$id,['case'=>$case,'items'=>$oldItems],$next,$v['change_reason']);
  }
  $this->event($actor,'offer_recorded',$case?->id,$id,$previous,$offer,$v['change_reason']??null);return $offer;
 });}
 public function respond(string $id,string $actor,string $response):object {return $this->locked(function()use($id,$actor,$response){
  $o=DB::table('lbs_processing_offers')->where('id',$id)->where('user_id',$actor)->first();abort_unless($o,404);
  abort_unless($this->latest($o->application_id)?->id===$id,409,'A newer offer is available.');
  abort_unless(DB::table('nurselink_job_applications')->where('id',$o->application_id)->where('status','offer')->exists(),409,'The recruitment offer is no longer active.');
  if($o->response===$response)return $o;
  abort_unless($o->response==='pending',409,'Your response is recorded. Contact the team to change it.');
  DB::table('lbs_processing_offers')->where('id',$id)->update(['response'=>$response,'responded_at'=>now(),'updated_at'=>now()]);
  $next=DB::table('lbs_processing_offers')->where('id',$id)->first();$c=DB::table('lbs_processing_cases')->where('application_id',$o->application_id)->first();
  $this->event($actor,'offer_'.$response,$c?->id,$id,$o,$next);return $next;
 });}
 public function open(string $offerId,string $actor):object {return $this->locked(function()use($offerId,$actor){
  $o=DB::table('lbs_processing_offers')->where('id',$offerId)->first();abort_unless($o,404);
  $candidate=(object)['application_id'=>$o->application_id,'offer_id'=>$o->id];$this->eligible($candidate);
  $old=DB::table('lbs_processing_cases')->where('application_id',$o->application_id)->first();if($old)return $old;
  $id=(string)Str::uuid();DB::table('lbs_processing_cases')->insert(['id'=>$id,'application_id'=>$o->application_id,'offer_id'=>$o->id,'user_id'=>$o->user_id,'owner_id'=>$actor,'template_version'=>config('lbs_processing.template_version'),'created_at'=>now(),'updated_at'=>now()]);
  foreach(config('lbs_processing.categories') as $title)DB::table('lbs_processing_items')->insert(['id'=>(string)Str::uuid(),'case_id'=>$id,'title'=>$title,'owner_id'=>$actor,'next_action'=>'The processing team will confirm which requirements apply to your placement.','created_at'=>now(),'updated_at'=>now()]);
  $c=$this->caseRow($id);$this->event($actor,'case_opened',$id,$o->id,null,$c);return $c;
 });}
 public function item(string $id,?string $itemId,array $v,string $actor,bool $member):object {return $this->locked(function()use($id,$itemId,$v,$actor,$member){
  $c=$this->caseRow($id);if($member)abort_unless($c->user_id===$actor,404);$this->version($c,$v['version']);
  abort_unless($c->state==='active',409,'This case is on hold or cancelled.');$this->preTravel($c);$this->eligible($c);
  $old=$itemId?DB::table('lbs_processing_items')->where('case_id',$id)->where('id',$itemId)->first():null;if($itemId)abort_unless($old,404);
  if($member){
   abort_unless($old&&!in_array($old->status,['verified','not_applicable'],true),409,'This item cannot be resubmitted.');
   $values=['status'=>'submitted','evidence_reference'=>$v['evidence_reference'],'reason'=>null,'verified_by'=>null,'verified_at'=>null];
  }else{
   $values=array_intersect_key($v,array_flip(['title','classification','status','owner_id','requirement_source','applicability_reason','next_action','due_on','due_basis','evidence_reference','issued_on','expires_on','reason']));
   abort_unless(self::canManage($values['owner_id']),422,'Choose an active authorized case officer.');
   if(!empty($values['due_on']))abort_unless(trim($values['due_basis']??'')!=='',422,'Record the basis for the due date.');
   if($values['classification']!=='unreviewed')abort_unless(trim($values['requirement_source']??'')!==''&&trim($values['applicability_reason']??'')!=='',422,'Record the requirement source and applicability decision.');
   if(in_array($values['status'],['correction_required','blocked','not_applicable'],true))abort_unless(trim($values['reason']??'')!=='',422,'A reason is required.');
   if($values['status']==='not_applicable')abort_unless(in_array($values['classification'],['conditional','optional'],true),422,'Mandatory or unreviewed requirements cannot be waived.');
   if($values['status']==='verified'){
    abort_unless($values['classification']!=='unreviewed'&&trim($values['evidence_reference']??'')!=='',422,'Review applicability and record evidence before verifying.');
    $threshold=max(now('Asia/Manila')->toDateString(),$c->planned_departure??'');
    abort_if(!empty($values['expires_on'])&&$values['expires_on']<$threshold,422,'Evidence expires before the required date.');
   }
   $signed=in_array($values['status'],['verified','not_applicable'],true);$values['verified_by']=$signed?$actor:null;$values['verified_at']=$signed?now():null;
  }
  $values['updated_at']=now();
  if($old)DB::table('lbs_processing_items')->where('id',$itemId)->update($values);
  else{$itemId=(string)Str::uuid();DB::table('lbs_processing_items')->insert($values+['id'=>$itemId,'case_id'=>$id,'created_at'=>now()]);}
  $next=DB::table('lbs_processing_items')->where('id',$itemId)->first();$this->invalidate($c);
  $this->event($actor,$member?'evidence_submitted':'requirement_updated',$id,$c->offer_id,$old,$next,$v['reason']??null);return $next;
 });}
 private function reviewed(object $c):void {
  $items=DB::table('lbs_processing_items')->where('case_id',$c->id)->get();abort_unless($items->isNotEmpty(),409,'At least one checklist item is required.');
  foreach($items as $i)abort_unless($i->classification!=='unreviewed'&&trim($i->requirement_source??'')!==''&&trim($i->applicability_reason??'')!==''&&self::canManage($i->owner_id),409,'Review every requirement, its source, applicability and owner.');
 }
 private function ready(object $c):void {
  $this->eligible($c);$this->reviewed($c);abort_unless($c->template_reviewed_at,409,'Approve the placement checklist first.');
  abort_unless(self::canManage($c->owner_id),409,'Assign an active case owner.');
  $threshold=max(now('Asia/Manila')->toDateString(),$c->planned_departure??'');
  foreach(DB::table('lbs_processing_items')->where('case_id',$c->id)->get() as $i){
   if($i->classification!=='optional')abort_unless(in_array($i->status,['verified','not_applicable'],true),409,'All mandatory and conditional requirements must be resolved.');
   abort_if($i->classification==='mandatory'&&$i->status!=='verified',409,'Mandatory requirements cannot be waived.');
   abort_if($i->status==='blocked'||$i->status==='correction_required',409,'Resolve blocked or correction items before readiness.');
   if($i->status==='verified')abort_if($i->expires_on&&$i->expires_on<$threshold,409,'Evidence has expired or expires before departure.');
  }
 }
 public function action(string $id,array $v,string $actor):object {return $this->locked(function()use($id,$v,$actor){
  $c=$this->caseRow($id);$this->version($c,$v['version']);$action=$v['action'];$reason=trim($v['reason']??'');
  if(in_array($action,['hold','cancel','resume','reopen','advance'],true))abort_unless($reason!=='',422,'Record the reason or confirmation evidence.');
  if($action==='hold'){abort_unless($c->state==='active'&&$c->stage!=='closed',409);abort_unless(!empty($v['next_review_on']),422,'Choose a next review date.');$next=$this->updateCase($c,['state'=>'on_hold','reason'=>$reason,'next_review_on'=>$v['next_review_on']]);}
  elseif($action==='cancel'){abort_unless($c->state!=='cancelled'&&$c->stage!=='closed',409);$next=$this->updateCase($c,['state'=>'cancelled','reason'=>$reason]);}
  elseif($action==='reopen'){abort_unless($c->state==='cancelled'||$c->stage==='closed',409);$this->eligible($c);$next=$this->invalidate($c,['state'=>'active','stage'=>'requirements_in_progress','reason'=>$reason]);}
  elseif($action==='resume'){abort_unless($c->state==='on_hold',409);$this->eligible($c);$next=$this->updateCase($c,['state'=>'active','reason'=>$reason,'next_review_on'=>null]);}
  elseif($action==='assign'){
   abort_unless($c->state!=='cancelled'&&$c->stage!=='closed',409,'Reopen this case before changing its officers.');
   abort_unless(self::canManage($v['owner_id']??'')&&(empty($v['backup_id'])||self::canManage($v['backup_id'])),422,'Select active authorized officers.');
   $next=$this->updateCase($c,['owner_id'=>$v['owner_id'],'backup_id'=>$v['backup_id']??null]);
  }
  else{
   abort_unless($c->state==='active'&&$c->stage!=='closed',409,'Only active cases can be updated.');$this->eligible($c);
   if($action==='plan'){
    $this->preTravel($c);$next=$this->invalidate($c,['planned_departure'=>$v['planned_departure']??null,'next_review_on'=>$v['next_review_on']??null]);
   }elseif($action==='review_template'){
    $this->preTravel($c);$this->reviewed($c);$next=$this->updateCase($c,['template_reviewed_by'=>$actor,'template_reviewed_at'=>now()]);
   }elseif($action==='advance'){
    $index=array_search($c->stage,self::STAGES);abort_unless($index!==false&&$index<7,409,'No later stage available.');$stage=self::STAGES[$index+1];
    if(in_array($stage,['readiness_review','ready_for_deployment','departure_confirmed'],true))$this->ready($c);
    if($stage==='departure_confirmed')abort_unless($c->planned_departure&&$c->planned_departure<=now('Asia/Manila')->toDateString(),409,'Set an actual departure date that is not in the future.');
    $data=['stage'=>$stage,'reason'=>$reason];if($stage==='ready_for_deployment')$data+=['ready_at'=>now(),'ready_by'=>$actor];
    $next=$this->updateCase($c,$data);
   }else abort(422,'Unknown case action.');
  }
  $this->event($actor,'case_'.$action,$id,$c->offer_id,$c,$next,$reason);return $next;
 });}
}
