<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\LbsProcessingService as P;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class LbsProcessingController extends Controller {
 public function __construct(private P $service){}
 private function admin(Request $r):bool{return $r->is('api/admin/lbs-processing*');}
 private function owned(Request $r,string $id):object {$q=DB::table('lbs_processing_cases')->where('id',$id);if(!$this->admin($r))$q->where('user_id',$r->user()->getKey());$c=$q->first();abort_unless($c,404);return $c;}
 public function index(Request $r){
  $r->validate(['page'=>'nullable|integer|min:1']);$admin=$this->admin($r);
  $q=DB::table('lbs_processing_cases as c')->join('users as u','u.id','=','c.user_id')->join('lbs_processing_offers as o','o.id','=','c.offer_id')->join('nurselink_job_applications as a','a.id','=','c.application_id');
  if(!$admin)$q->where('c.user_id',$r->user()->getKey());
  $cases=$q->select('c.*','u.name as applicant_name','o.position','o.employer','o.destination','o.response as offer_response','a.status as recruitment_status')->orderByDesc('c.updated_at')->paginate(30);
  $offers=DB::table('lbs_processing_offers as o')->join('users as u','u.id','=','o.user_id')->whereNotExists(function($q){$q->selectRaw('1')->from('lbs_processing_offers as newer')->whereColumn('newer.application_id','o.application_id')->whereColumn('newer.revision','>','o.revision');});
  if(!$admin)$offers->where('o.user_id',$r->user()->getKey());
  $offers=$offers->select('o.*','u.name as applicant_name')->orderByDesc('o.created_at')->paginate(30,['*'],'offer_page');
  $officers=[];if($admin){$officers=DB::table('users')->where('status','active')->get(['id','name'])->filter(fn($u)=>P::canManage($u->id))->values();}
  return response()->json(['data'=>['cases'=>$cases,'offers'=>$offers,'can_manage'=>(bool)$r->attributes->get('lbs_processing_manage',false),'officers'=>$officers,'stages'=>P::STAGES]]);
 }
 public function show(Request $r,string $case){
  $c=$this->owned($r,$case);$events=DB::table('lbs_processing_events')->where('case_id',$case)->orderByDesc('id')->limit(100);
  $events=$this->admin($r)?$events->get():$events->get(['action','created_at']);
  return response()->json(['data'=>['case'=>$c,'offer'=>DB::table('lbs_processing_offers')->where('id',$c->offer_id)->first(),'recruitment_status'=>DB::table('nurselink_job_applications')->where('id',$c->application_id)->value('status'),'items'=>DB::table('lbs_processing_items')->where('case_id',$case)->orderBy('created_at')->orderBy('title')->get(),'events'=>$events]]);
 }
 public function applications(Request $r){
  $v=$r->validate(['application_id'=>'required|integer|min:1']);
  $row=DB::table('nurselink_job_applications as a')->join('users as u','u.id','=','a.user_id')->join('nurselink_job_opportunities as j','j.id','=','a.job_opportunity_id')->where('a.id',$v['application_id'])->where('a.status','offer')->first(['a.id','a.user_id','u.name','j.title','j.employer_name','j.country']);
  abort_unless($row,404,'No offered application matches this reference.');return response()->json(['data'=>$row]);
 }
 public function offer(Request $r){$v=$r->validate(['application_id'=>'required|integer|min:1','request_id'=>'required|uuid','reference'=>'required|string|max:200','employer'=>'required|string|max:200','position'=>'required|string|max:200','destination'=>'required|string|max:160','terms'=>'required|string|max:10000','evidence_reference'=>'required|string|max:1000','change_reason'=>'nullable|string|max:1000']);return response()->json(['data'=>$this->service->offer($v,$r->user()->getKey())],201);}
 public function respond(Request $r,string $offer){$v=$r->validate(['response'=>['required',Rule::in(['accepted','declined'])],'acknowledged'=>'required|accepted']);return response()->json(['data'=>$this->service->respond($offer,$r->user()->getKey(),$v['response'])]);}
 public function open(Request $r){$v=$r->validate(['offer_id'=>'required|uuid']);return response()->json(['data'=>$this->service->open($v['offer_id'],$r->user()->getKey())],201);}
 public function item(Request $r,string $case,?string $item=null){
  $this->owned($r,$case);$admin=$this->admin($r);
  $rules=['version'=>'required|integer|min:1','evidence_reference'=>($admin?'nullable':'required').'|string|max:1000'];
  if($admin)$rules+=['title'=>'required|string|max:200','owner_id'=>'required|uuid','classification'=>['required',Rule::in(['unreviewed','mandatory','conditional','optional'])],'status'=>['required',Rule::in(['not_started','awaiting_applicant','submitted','under_review','correction_required','verified','not_applicable','blocked'])],'requirement_source'=>'nullable|string|max:1000','applicability_reason'=>'nullable|string|max:1000','next_action'=>'required|string|max:1000','due_on'=>'nullable|date_format:Y-m-d','due_basis'=>'nullable|string|max:300','issued_on'=>'nullable|date_format:Y-m-d','expires_on'=>'nullable|date_format:Y-m-d|after_or_equal:issued_on','reason'=>'nullable|string|max:1000'];
  return response()->json(['data'=>$this->service->item($case,$item,$r->validate($rules),$r->user()->getKey(),!$admin)]);
 }
 public function action(Request $r,string $case){$v=$r->validate(['version'=>'required|integer|min:1','action'=>['required',Rule::in(['hold','cancel','resume','reopen','assign','plan','review_template','advance'])],'reason'=>'nullable|string|max:1000','owner_id'=>'nullable|uuid','backup_id'=>'nullable|uuid','planned_departure'=>'nullable|date_format:Y-m-d','next_review_on'=>'nullable|date_format:Y-m-d']);return response()->json(['data'=>$this->service->action($case,$v,$r->user()->getKey())]);}
}
