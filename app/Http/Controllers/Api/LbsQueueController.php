<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\LbsQueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class LbsQueueController extends Controller {
 public function __construct(private LbsQueueService $queue){}
 private function safe(object $t):array {return ['id'=>$t->id,'number'=>$t->number,'visit_date'=>$t->visit_date,'service_id'=>(int)$t->service_id,'counter_id'=>$t->counter_id,'source'=>$t->source,'status'=>$t->status,'version'=>(int)$t->version,'created_at'=>$t->created_at,'updated_at'=>$t->updated_at,'waiting_ahead'=>$t->status==='waiting'&&$t->visit_date===$this->queue->today()?DB::table('lbs_queue_tickets')->where('visit_date',$t->visit_date)->where('service_id',$t->service_id)->where('status','waiting')->where('sequence','<',$t->sequence)->count():null];}
 public function mine(Request $r){return response()->json(['data'=>['tickets'=>DB::table('lbs_queue_tickets')->where('user_id',$r->user()->getKey())->orderByDesc('created_at')->limit(30)->get()->map(fn($t)=>$this->safe($t)),'services'=>DB::table('lbs_queue_services')->where('active',true)->get(['id','name','online_enabled']),'counters'=>DB::table('lbs_queue_counters')->where('active',true)->get(['id','name','service_id']),'today'=>$this->queue->today()]]);}
 public function issue(Request $r){$v=$r->validate(['service_id'=>['required','integer'],'request_id'=>['required','uuid']]);return response()->json(['data'=>$this->safe($this->queue->issue($r->user()->getKey(),$v['service_id'],$v['request_id'],$r->user()->getKey(),false))],201);}
 public function qrWalkin(Request $r){$v=$r->validate(['service_id'=>['required','integer'],'request_id'=>['required','uuid'],'confirmed_arrival'=>['required','accepted']]);return response()->json(['data'=>$this->safe($this->queue->qrWalkin($r->user()->getKey(),$v['service_id'],$v['request_id']))],201);}
 public function walkin(Request $r){$v=$r->validate(['service_id'=>['required','integer'],'request_id'=>['required','uuid'],'user_id'=>['required','uuid']]);return response()->json(['data'=>$this->safe($this->queue->issue($v['user_id'],$v['service_id'],$v['request_id'],$r->user()->getKey(),true))],201);}
 public function action(Request $r,string $ticket){$v=$r->validate(['action'=>['required',Rule::in(['checkin','start','complete','missed','requeue','cancel','transfer'])],'version'=>['required','integer','min:1'],'reason'=>['nullable','string','max:500'],'service_id'=>['nullable','integer']]);$owner=!$r->is('api/admin/lbs-queue/*');return response()->json(['data'=>$this->safe($this->queue->transition($ticket,$r->user()->getKey(),$v['action'],$v['version'],$v['reason']??null,$v['service_id']??null,$owner))]);}
 public function next(Request $r,int $counter){$t=$this->queue->callNext($counter,$r->user()->getKey());return response()->json(['data'=>$t?$this->safe($t):null]);}
 public function admin(Request $r){
  $v=$r->validate(['date'=>['nullable','date_format:Y-m-d'],'page'=>['nullable','integer','min:1']]);
  $tickets=DB::table('lbs_queue_tickets as t')->join('users as u','u.id','=','t.user_id')->leftJoin('users as staff','staff.id','=','t.operator_id')->where('visit_date',$v['date']??$this->queue->today())->orderBy('sequence')->select('t.*','u.name as applicant_name','staff.name as operator_name')->paginate(100);
  return response()->json(['data'=>['tickets'=>$tickets,'selected_date'=>$v['date']??$this->queue->today(),'counts'=>DB::table('lbs_queue_tickets')->where('visit_date',$v['date']??$this->queue->today())->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total','status'),'services'=>DB::table('lbs_queue_services')->get(['id','name','active','online_enabled']),'counters'=>DB::table('lbs_queue_counters')->get(['id','name','service_id','active']),'today'=>$this->queue->today(),'actor_id'=>$r->user()->getKey(),'can_manage'=>(bool)$r->attributes->get('lbs_queue_can_manage',false)]]);
 }
 public function history(Request $r,string $ticket){$q=DB::table('lbs_queue_tickets')->where('id',$ticket);if(!$r->is('api/admin/lbs-queue/*'))$q->where('user_id',$r->user()->getKey());abort_unless($q->exists(),404);$events=DB::table('lbs_queue_events')->where('ticket_id',$ticket)->orderBy('id')->get(['action','from_status','to_status','counter_id','service_id','created_at']);return response()->json(['data'=>$events]);}
 public function config(Request $r,string $kind){abort_unless(in_array($kind,['service','counter'],true),404);$rules=['id'=>['nullable','integer'],'name'=>['required','string','max:100'],'active'=>['required','boolean']];if($kind==='service')$rules['online_enabled']=['required','boolean'];else $rules['service_id']=['required','integer','exists:lbs_queue_services,id'];return response()->json(['data'=>$this->queue->configure($kind,$r->validate($rules))]);}
 public function lookup(Request $r){$v=$r->validate(['email'=>['required','email','max:254']]);$u=DB::table('users')->where('email',$v['email'])->where('status','active')->first(['id','name']);abort_unless($u,404,'No active applicant matches that email.');return response()->json(['data'=>$u]);}
 public function display(){
  $rows=DB::table('lbs_queue_tickets as t')->join('lbs_queue_counters as c','c.id','=','t.counter_id')->where('t.visit_date',$this->queue->today())->whereIn('t.status',['called','serving'])->orderBy('c.name')->get(['t.number','t.status','c.name as counter']);
  return response()->json(['data'=>$rows,'date'=>$this->queue->today()])->header('Cache-Control','no-store');
 }
}
