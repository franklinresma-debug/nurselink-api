<?php
namespace App\Services;
use App\Models\Application;use App\Models\User;use Illuminate\Support\Facades\DB;use Illuminate\Support\Facades\Schema;
class ApplicationProgressService {
 public function ensure(User $user,bool $notify=true):object {
  return DB::transaction(function()use($user,$notify){
   DB::table('users')->where('id',$user->id)->lockForUpdate()->first();
   $application=Application::where('user_id',$user->id)->first();
   if(!$application)$application=Application::create(['user_id'=>$user->id,'application_no'=>app(IdentifierService::class)->next('application','NLA'),'status'=>'in_progress','progress_percent'=>0,'profile_data'=>[]]);
   $row=DB::table('nurselink_application_support')->where('user_id',$user->id)->first();
   if(!$row){$id=DB::table('nurselink_application_support')->insertGetId(['user_id'=>$user->id,'application_id'=>$application->id,'last_activity_at'=>$application->updated_at ?? now(),'progress_percent'=>$application->progress_percent,'created_at'=>now(),'updated_at'=>now()]);$row=DB::table('nurselink_application_support')->find($id);}
   if($notify&&!$row->started_notified_at){$this->notifyAdmins('application.started','Applicant registration in progress','Application '.$application->application_no.' is in progress and available for assistance.');DB::table('nurselink_application_support')->where('id',$row->id)->update(['started_notified_at'=>now()]);}
   return DB::table('nurselink_application_support')->find($row->id);
  });
 }
 public function activity(User $user,?string $issue=null):void {
  $row=$this->ensure($user);DB::table('nurselink_application_support')->where('id',$row->id)->update(['last_activity_at'=>now(),'issue_code'=>$issue,'support_status'=>$issue?'requested':'monitoring','updated_at'=>now()]);
  if($issue&&$row->issue_code!==$issue){$ref=DB::table('applications')->where('id',$row->application_id)->value('application_no');$this->notifyAdmins('application.help_requested','Applicant needs assistance','Application '.$ref.' reported '.$this->issueLabel($issue).'. Open Registration Support to assist.');}
 }
 public function progress(User $user,int $percent):object {
  $row=$this->ensure($user);$percent=max($percent,$this->draftPercent(json_decode($row->draft_data??'{}',true)?:[]));DB::table('nurselink_application_support')->where('id',$row->id)->update(['progress_percent'=>$percent]);DB::table('applications')->where('id',$row->application_id)->update(['progress_percent'=>$percent]);return DB::table('nurselink_application_support')->find($row->id);
 }
 public function draftPercent(array $draft):int {$values=array_merge($draft['personal']['values']??[],$draft['professional']['values']??[]);$required=['first_name','last_name','birth_date','nationality','email','phone','country','professional_title','years_experience','highest_nursing_education'];$filled=0;foreach($required as $field)if(isset($values[$field])&&trim((string)$values[$field])!=='')$filled++;return min(90,$filled*9);}
 public function issueLabel(?string $issue):string {return ['connection_problem'=>'a connection problem','upload_problem'=>'an upload problem','sync_conflict'=>'a draft sync conflict','ocr_problem'=>'an OCR problem','help_requested'=>'a request for help'][$issue]??'no reported issue';}
 public function alertDelayed():int {
  $cutoff=now()->subHours((int)config('nurselink.application_delay_alert_hours',24));$count=0;
  $ids=DB::table('nurselink_application_support as s')->join('applications as a','a.id','=','s.application_id')->whereNotIn('a.status',['approved','rejected','withdrawn'])->where('s.progress_percent','<',100)->where('s.last_activity_at','<=',$cutoff)->pluck('s.id');
  foreach($ids as $id)DB::transaction(function()use($id,$cutoff,&$count){$row=DB::table('nurselink_application_support')->where('id',$id)->lockForUpdate()->first();if(!$row||$row->progress_percent>=100||\Illuminate\Support\Carbon::parse($row->last_activity_at)->gt($cutoff)||($row->last_alert_activity_at&&$row->last_alert_activity_at>=$row->last_activity_at))return;
   $user=User::find($row->user_id);if(!$user||$user->status!=='active')return;$ref=DB::table('applications')->where('id',$row->application_id)->value('application_no');$this->notifyAdmins('application.delayed','Application may need assistance','Application '.$ref.' has not progressed for '.config('nurselink.application_delay_alert_hours',24).' hours. This may reflect connectivity or other challenges; contact the applicant to offer help.');DB::table('nurselink_application_support')->where('id',$id)->update(['last_alert_activity_at'=>$row->last_activity_at,'support_status'=>'requested','updated_at'=>now()]);$count++;});return $count;
 }
 public function notifyAdmins(string $type,string $title,string $message):void {
  $ids=DB::table('role_user')->join('roles','roles.id','=','role_user.role_id')->join('users','users.id','=','role_user.user_id')->where('users.status','active')->whereIn('roles.code',['super_administrator','administrator','membership_officer'])->pluck('users.id');
  if(Schema::hasTable('nurselink_admin_role_assignments'))$ids=$ids->merge(DB::table('nurselink_admin_role_assignments')->where('active',true)->whereIn('role_key',['super_administrator','membership_administrator','support_officer'])->pluck('user_id'));
  if(Schema::hasTable('nurselink_super_admin_access'))$ids=$ids->merge(DB::table('nurselink_super_admin_access')->where('active',true)->pluck('user_id'));
  if(Schema::hasTable('nurselink_reviewer_access'))$ids=$ids->merge(DB::table('nurselink_reviewer_access')->where('active',true)->whereIn('role',['admin','super_admin'])->pluck('user_id'));
  foreach($ids->unique() as $id)if(DB::table('users')->where('id',$id)->where('status','active')->exists())DB::table('nurselink_notifications')->insert(['user_id'=>$id,'type'=>$type,'severity'=>$type==='application.started'?'info':'warning','title'=>$title,'message'=>$message,'action_url'=>'/admin/#registration-support','created_at'=>now(),'updated_at'=>now()]);
 }
}
