<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class LbsQueueService {
 public function today(): string {return now('Asia/Manila')->toDateString();}
 private function locked(callable $fn): mixed {
  return DB::transaction(function()use($fn){DB::table('lbs_queue_lock')->where('id',1)->lockForUpdate()->first();$this->expire();return $fn();},3);
 }
 private function event(object $t,?string $actor,string $action,?string $from,?string $reason=null):void {
  DB::table('lbs_queue_events')->insert(['ticket_id'=>$t->id,'actor_id'=>$actor,'action'=>$action,'from_status'=>$from,'to_status'=>$t->status,'counter_id'=>$t->counter_id,'service_id'=>$t->service_id,'reason'=>$reason,'created_at'=>now()]);
 }
 private function expire():void {
  $old=DB::table('lbs_queue_tickets')->where('visit_date','<',$this->today())->whereNotNull('active_key')->get();
  foreach($old as $t){$from=$t->status;DB::table('lbs_queue_tickets')->where('id',$t->id)->update(['status'=>'expired','active_key'=>null,'version'=>$t->version+1,'updated_at'=>now()]);$t->status='expired';$this->event($t,null,'expired',$from);}
 }
 public function issue(string $user,int $service,string $requestId,string $actor,bool $walkin):object {
  return $this->locked(function()use($user,$service,$requestId,$actor,$walkin){
   $replay=DB::table('lbs_queue_tickets')->where('user_id',$user)->where('request_id',$requestId)->first();
   if($replay){abort_unless((int)$replay->service_id===$service && $replay->source===($walkin?'walkin':'online'),409,'This request key was already used for a different visit.');return $replay;}
   $s=DB::table('lbs_queue_services')->where('id',$service)->where('active',true)->first();abort_unless($s && ($walkin || $s->online_enabled),422,'This service is not accepting this type of visit.');
   abort_unless(DB::table('users')->where('id',$user)->where('status','active')->exists(),422,'An existing active applicant record is required.');
   $date=$this->today();$key=$date.':'.$user;
   $active=DB::table('lbs_queue_tickets')->where('active_key',$key)->first();abort_unless(!$active,409,'This applicant already has an open visit today.');
   $seq=1+(int)DB::table('lbs_queue_tickets')->where('visit_date',$date)->max('sequence');
   $id=(string)Str::uuid();DB::table('lbs_queue_tickets')->insert(['id'=>$id,'number'=>str_replace('-','',$date).'-'.str_pad((string)$seq,4,'0',STR_PAD_LEFT),'visit_date'=>$date,'sequence'=>$seq,'user_id'=>$user,'service_id'=>$service,'counter_id'=>null,'source'=>$walkin?'walkin':'online','status'=>$walkin?'waiting':'reserved','request_id'=>$requestId,'active_key'=>$key,'version'=>1,'created_at'=>now(),'updated_at'=>now()]);
   $t=DB::table('lbs_queue_tickets')->where('id',$id)->first();$this->event($t,$actor,'issued',null);return $t;
  });
 }
 public function qrWalkin(string $user,int $service,string $requestId):object {
  return $this->locked(function()use($user,$service,$requestId){
   $replay=DB::table('lbs_queue_tickets')->where('user_id',$user)->where('request_id',$requestId)->first();
   if($replay){abort_unless($replay->source==='qr_walkin' && (int)$replay->service_id===$service,409,'This check-in request was used for another visit.');return $replay;}
   abort_unless(DB::table('users')->where('id',$user)->where('status','active')->exists(),422,'An active applicant account is required.');
   $active=DB::table('lbs_queue_tickets')->where('active_key',$this->today().':'.$user)->first();
   if($active){
    if($active->status==='reserved'){
     DB::table('lbs_queue_tickets')->where('id',$active->id)->update(['status'=>'waiting','version'=>$active->version+1,'updated_at'=>now()]);
     $active=DB::table('lbs_queue_tickets')->where('id',$active->id)->first();$this->event($active,$user,'qr_checkin','reserved');
    }
    return $active;
   }
   $ticket=$this->issue($user,$service,$requestId,$user,true);
   DB::table('lbs_queue_tickets')->where('id',$ticket->id)->update(['source'=>'qr_walkin']);$ticket->source='qr_walkin';$this->event($ticket,$user,'qr_checkin','waiting');return $ticket;
  });
 }
 public function callNext(int $counter,string $actor):?object {
  return $this->locked(function()use($counter,$actor){
   $c=DB::table('lbs_queue_counters')->where('id',$counter)->where('active',true)->first();abort_unless($c,422,'Choose an active counter.');
   abort_unless(DB::table('lbs_queue_services')->where('id',$c->service_id)->where('active',true)->exists(),422,'The counter service is closed.');
   $busy=DB::table('lbs_queue_tickets')->where('visit_date',$this->today())->where('counter_id',$counter)->whereIn('status',['called','serving'])->first();
   // A retry by the same operator returns the same call, never claims another ticket.
   if($busy){abort_unless($busy->operator_id===$actor,409,'This counter is already in use by another staff member.');return $busy;}
   $t=DB::table('lbs_queue_tickets')->where('visit_date',$this->today())->where('service_id',$c->service_id)->where('status','waiting')->orderBy('sequence')->first();if(!$t)return null;
   DB::table('lbs_queue_tickets')->where('id',$t->id)->update(['status'=>'called','counter_id'=>$counter,'operator_id'=>$actor,'version'=>$t->version+1,'updated_at'=>now()]);$t=DB::table('lbs_queue_tickets')->where('id',$t->id)->first();$this->event($t,$actor,'called','waiting');return $t;
  });
 }
 public function transition(string $id,string $actor,string $action,int $version,?string $reason=null,?int $service=null,bool $owner=false):object {
  return $this->locked(function()use($id,$actor,$action,$version,$reason,$service,$owner){
   $t=DB::table('lbs_queue_tickets')->where('id',$id)->first();abort_unless($t,404);
   if($owner)abort_unless($t->user_id===$actor,404);
   abort_unless((int)$t->version===$version,409,'This visit changed. Refresh before trying again.');
   abort_unless($t->visit_date===$this->today(),409,'This visit is no longer active today.');
   if($owner)abort_unless(in_array($action,['checkin','cancel'],true),403);
   $allowed=['checkin'=>['reserved'],'start'=>['called'],'complete'=>['serving'],'missed'=>['called'],'requeue'=>['missed'],'cancel'=>['reserved','waiting','called','missed'],'transfer'=>['called','serving']];
   abort_unless(isset($allowed[$action]) && in_array($t->status,$allowed[$action],true),409,'This action is not available for the current visit status.');
   if($owner && $action==='cancel')abort_unless(in_array($t->status,['reserved','waiting','missed'],true),409,'Ask the counter staff to cancel a called visit.');
   if(in_array($action,['start','complete','missed','transfer'],true))abort_unless($t->operator_id===$actor,409,'Only the staff member handling this visit may perform this action.');
   if(in_array($action,['transfer','cancel'],true))abort_unless(trim((string)$reason)!=='',422,'A reason is required.');
   $status=['checkin'=>'waiting','start'=>'serving','complete'=>'completed','missed'=>'missed','requeue'=>'waiting','cancel'=>'cancelled','transfer'=>'waiting'][$action];
   $update=['status'=>$status,'version'=>$t->version+1,'updated_at'=>now()];
   if(in_array($status,['completed','cancelled'],true))$update['active_key']=null;
   if(in_array($action,['transfer','requeue'],true)){$update['counter_id']=null;$update['operator_id']=null;}
   if($action==='transfer'){abort_unless(DB::table('lbs_queue_services')->where('id',$service)->where('active',true)->exists(),422,'Choose an active destination service.');abort_if($service===$t->service_id,422,'Choose a different service.');$update['service_id']=$service;}
   DB::table('lbs_queue_tickets')->where('id',$id)->update($update);$next=DB::table('lbs_queue_tickets')->where('id',$id)->first();$this->event($next,$actor,$action,$t->status,$reason);return $next;
  });
 }
 public function configure(string $kind,array $data):object {
  return $this->locked(function()use($kind,$data){
   $table=$kind==='service'?'lbs_queue_services':'lbs_queue_counters';$id=$data['id']??null;unset($data['id']);
   if($id){abort_unless(DB::table($table)->where('id',$id)->exists(),404);
    $busy=DB::table('lbs_queue_tickets')->whereNotNull('active_key');
    if($kind==='service')$busy->where('service_id',$id);else $busy->where('counter_id',$id)->whereIn('status',['called','serving']);
    abort_if($busy->exists(),409,'Finish or transfer active visits before changing this configuration.');
    DB::table($table)->where('id',$id)->update($data+['updated_at'=>now()]);
   }else{$id=DB::table($table)->insertGetId($data+['created_at'=>now(),'updated_at'=>now()]);}
   return DB::table($table)->where('id',$id)->first();
  });
 }
}
