<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;use App\Models\User;use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;
class NurseLinkConversationController extends Controller {
 private function admin(Request $r):bool{return str_contains($r->path(),'/admin/');}
 private function thread(Request $r,int $id,bool $lock=false){$q=DB::table('nurselink_conversations')->where('id',$id);if(!$this->admin($r))$q->where('member_id',$r->user()->id);if($lock)$q->lockForUpdate();return $q->first()??abort(404,'Conversation not found.');}
 public function index(Request $r){
  $admin=$this->admin($r);$q=DB::table('nurselink_conversations as c')->join('users as u','u.id','=','c.member_id')->select('c.id','c.subject','c.updated_at','u.name as member_name');
  if(!$admin)$q->where('c.member_id',$r->user()->id);
  $role=$admin?'member':'admin';$column=$admin?'admin_read_id':'member_read_id';
  $q->selectSub(function($s)use($role,$column){$s->from('nurselink_conversation_messages as m')->selectRaw('count(*)')->whereColumn('m.conversation_id','c.id')->where('m.sender_role',$role)->whereColumn('m.id','>','c.'.$column);},'unread_count');
  return response()->json(['data'=>$q->orderByDesc('c.updated_at')->orderByDesc('c.id')->paginate(30)]);
 }
 public function show(Request $r,int $id){$thread=$this->thread($r,$id);$q=DB::table('nurselink_conversation_messages')->where('conversation_id',$id);if($r->query('before'))$q->where('id','<',max(1,(int)$r->query('before')));$rows=$q->orderByDesc('id')->limit(101)->get();$more=$rows->count()>100;$rows=$rows->take(100)->reverse()->values();return response()->json(['data'=>['id'=>$thread->id,'subject'=>$thread->subject,'messages'=>$rows->map(function($m){$a=json_decode($m->attachment??'null',true);$m->attachment=$a?['name'=>$a['name'],'size'=>$a['size']]:null;return $m;}),'has_older'=>$more]]);}
 public function store(Request $r){
  $data=$r->validate(['subject'=>'required|string|max:190','body'=>'required|string|max:5000','client_id'=>'required|uuid','attachment'=>'nullable|file|mimes:pdf,jpg,jpeg,png,webp,docx|max:5120','member_email'=>'sometimes|email|max:190']);
  abort_if(trim($data['body'])===''||trim($data['subject'])==='',422,'Enter a subject and message.');
  $member=$this->admin($r)?User::where('email',$data['member_email']??'')->where('status','active')->first():$r->user();abort_unless($member,422,'No active member matches that email.');
  $id=DB::transaction(function()use($r,$data,$member){
   DB::table('nurselink_conversations')->insertOrIgnore(['member_id'=>$member->id,'subject'=>trim($data['subject']),'client_id'=>$data['client_id'],'created_at'=>now(),'updated_at'=>now()]);
   $thread=DB::table('nurselink_conversations')->where('member_id',$member->id)->where('client_id',$data['client_id'])->lockForUpdate()->first();
   $this->append($r,$thread->id,$data);return $thread->id;
  });return response()->json(['data'=>['id'=>$id]],201);
 }
 private function append(Request $r,int $id,array $data):void{
  $role=$this->admin($r)?'admin':'member';
  $existing=DB::table('nurselink_conversation_messages')->where(['conversation_id'=>$id,'sender_id'=>$r->user()->id,'sender_role'=>$role,'client_id'=>$data['client_id']])->exists();if($existing)return;
  $attachment=null;if($r->hasFile('attachment')){$f=$r->file('attachment');$path=$f->store('conversation-attachments','local');abort_unless($path,503,'The attachment could not be saved. Please try again.');$attachment=json_encode(['path'=>$path,'name'=>mb_substr(basename($f->getClientOriginalName()),0,190),'size'=>$f->getSize()]);}
  DB::table('nurselink_conversation_messages')->insert(['attachment'=>$attachment,'conversation_id'=>$id,'sender_id'=>$r->user()->id,'sender_name'=>$r->user()->name??'NurseLink','sender_role'=>$role,'client_id'=>$data['client_id'],'body'=>trim($data['body']),'created_at'=>now()]);DB::table('nurselink_conversations')->where('id',$id)->update(['updated_at'=>now()]);
 }
 public function reply(Request $r,int $id){$data=$r->validate(['body'=>'required|string|max:5000','client_id'=>'required|uuid','attachment'=>'nullable|file|mimes:pdf,jpg,jpeg,png,webp,docx|max:5120']);abort_if(trim($data['body'])==='',422,'Enter a message.');DB::transaction(function()use($r,$id,$data){$this->thread($r,$id,true);$this->append($r,$id,$data);});return response()->json(['data'=>['id'=>$id]],201);}
 public function read(Request $r,int $id){$data=$r->validate(['last_id'=>'required|integer|min:1']);DB::transaction(function()use($r,$id,$data){$t=$this->thread($r,$id,true);abort_unless(DB::table('nurselink_conversation_messages')->where('conversation_id',$id)->where('id',$data['last_id'])->exists(),422);$col=$this->admin($r)?'admin_read_id':'member_read_id';DB::table('nurselink_conversations')->where('id',$id)->update([$col=>max($t->$col,$data['last_id'])]);});return response()->json(['data'=>['read'=>true]]);}
 public function summary(Request $r){
  $admin=$this->admin($r);$q=DB::table('nurselink_conversation_messages as m')->join('nurselink_conversations as c','c.id','=','m.conversation_id')->where('m.sender_role',$admin?'member':'admin');if(!$admin)$q->where('c.member_id',$r->user()->id);
  $latest=(clone $q)->max('m.id');$unread=(clone $q)->whereColumn('m.id','>',$admin?'c.admin_read_id':'c.member_read_id')->count();return response()->json(['data'=>['latest_id'=>(int)$latest,'unread'=>$unread]]);
 }
 public function attachment(Request $r,int $id,int $message){$this->thread($r,$id);$m=DB::table('nurselink_conversation_messages')->where('conversation_id',$id)->where('id',$message)->first();$a=json_decode($m->attachment??'null',true);abort_unless($a&&\Illuminate\Support\Facades\Storage::disk('local')->exists($a['path']),404);return \Illuminate\Support\Facades\Storage::disk('local')->download($a['path'],$a['name'],['Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);}
}
