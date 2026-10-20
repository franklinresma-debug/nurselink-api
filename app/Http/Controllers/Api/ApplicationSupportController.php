<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;use App\Services\ApplicationProgressService;use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;
class ApplicationSupportController extends Controller {
 public function saveDraft(Request $r,ApplicationProgressService $service){
  $data=$r->validate(['section'=>'required|in:personal,professional','base_revision'=>'required|integer|min:0','values'=>'required|array|max:50','values.*'=>'nullable|string|max:6000','structured_data'=>'nullable|array|max:20']);
  abort_if(strlen(json_encode($data))>250000,422,'Draft is too large.');
  $allowed=['first_name','middle_name','last_name','birth_date','nationality','email','phone','country','city','province','address','gender','marital_status','professional_title','years_experience','current_position','current_employer','employment_countries','healthcare_facility_names','employment_positions','emergency_contact_name_relationship','emergency_contact_phone','specialty','primary_license_number','primary_license_country','primary_license_expiry','highest_nursing_education','graduation_year'];
  $values=array_intersect_key($data['values'],array_flip($allowed));
  return DB::transaction(function()use($r,$service,$data,$values){$row=$service->ensure($r->user());$row=DB::table('nurselink_application_support')->where('id',$row->id)->lockForUpdate()->first();
   if((int)$row->draft_revision!==(int)$data['base_revision'])return response()->json(['message'=>'The draft changed on another session. Review both versions before syncing.','conflict'=>true,'revision'=>(int)$row->draft_revision],409);
   $draft=json_decode($row->draft_data??'{}',true)?:[];$draft[$data['section']]=['values'=>$values,'structured_data'=>$data['structured_data']??[]];$rev=(int)$row->draft_revision+1;
   DB::table('nurselink_application_support')->where('id',$row->id)->update(['draft_data'=>json_encode($draft),'draft_revision'=>$rev,'progress_percent'=>max($row->progress_percent,$service->draftPercent($draft)),'last_activity_at'=>now(),'updated_at'=>now()]);
   DB::table('applications')->where('id',$row->application_id)->update(['progress_percent'=>max($row->progress_percent,$service->draftPercent($draft)),'updated_at'=>now()]);
   return response()->json(['message'=>'Draft synced. Complete and save each section to confirm your information.','revision'=>$rev]);
  });
 }
 public function help(Request $r,ApplicationProgressService $service){$d=$r->validate(['issue'=>'required|in:connection_problem,upload_problem,sync_conflict,ocr_problem,help_requested']);$service->activity($r->user(),$d['issue']);return response()->json(['message'=>'Your assistance request is visible to administrators.']);}
 public function index(Request $r,ApplicationProgressService $service){
  $this->elevated($r);$cutoff=now()->subHours((int)config('nurselink.application_delay_alert_hours',24));
  $rows=DB::table('nurselink_application_support as s')->join('applications as a','a.id','=','s.application_id')->join('users as u','u.id','=','s.user_id')->where('u.status','active')->select('s.id','s.application_id','a.application_no','a.status as application_status','s.progress_percent','s.last_activity_at','s.issue_code','s.support_status','s.assigned_admin_user_id','s.updated_at')->orderByRaw("CASE WHEN s.support_status='requested' THEN 0 ELSE 1 END")->orderByDesc('s.last_activity_at')->limit(200)->get()->map(function($row)use($service,$cutoff){$row->delayed=!in_array($row->application_status,['approved','rejected','withdrawn'],true)&&$row->progress_percent<100&&\Illuminate\Support\Carbon::parse($row->last_activity_at)->lte($cutoff);$row->issue_label=$service->issueLabel($row->issue_code);return $row;});
  return response()->json(['data'=>$rows,'delay_hours'=>(int)config('nurselink.application_delay_alert_hours',24)]);
 }
 public function assist(Request $r,int $id){
  $this->elevated($r);$d=$r->validate(['action'=>'required|in:offer_help,resolve','message'=>'nullable|string|min:5|max:2000']);
  return DB::transaction(function()use($r,$id,$d){$row=DB::table('nurselink_application_support')->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);
   abort_if($row->assigned_admin_user_id&&$row->assigned_admin_user_id!==$r->user()->id,409,'Another administrator is assisting this applicant.');
   if($d['action']==='offer_help')DB::table('nurselink_notifications')->insert(['user_id'=>$row->user_id,'type'=>'application.assistance','severity'=>'info','title'=>'Help with your application','message'=>$d['message']??'An administrator is available to help. Open your inbox to discuss any difficulty with your application.','action_url'=>'/inbox','created_at'=>now(),'updated_at'=>now()]);
   DB::table('nurselink_application_support')->where('id',$id)->update(['assigned_admin_user_id'=>$r->user()->id,'support_status'=>$d['action']==='resolve'?'resolved':'assisting','issue_code'=>$d['action']==='resolve'?null:$row->issue_code,'updated_at'=>now()]);return response()->json(['message'=>$d['action']==='resolve'?'Assistance marked resolved.':'Applicant notified that assistance is available.']);
  });
 }
 private function elevated(Request $r):void{abort_unless((string)$r->session()->get('nurselink_admin_elevated_user_id')===(string)$r->user()->id&&(int)$r->session()->get('nurselink_admin_expires_at')>=time()&&(time()-(int)$r->session()->get('nurselink_admin_elevated_at'))<=1800,403,'Administrator sign-in is required.');}
}
