<?php
namespace App\Http\Middleware;
use Closure;use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;
class ApplicantSyncIdempotency {
 public function handle(Request $request,Closure $next){
  if(in_array($request->method(),['GET','HEAD','OPTIONS']))return $next($request);
  $op=$request->header('X-NurseLink-Operation');if(!$op)return $next($request);
  abort_unless(\Illuminate\Support\Str::isUuid($op),422,'Invalid sync operation.');
  $files=[];foreach($request->allFiles() as $key=>$file){abort_if(is_array($file),422,'One document per operation is supported.');$files[$key]=['sha256'=>hash_file('sha256',$file->getRealPath()),'name'=>$file->getClientOriginalName(),'mime'=>$file->getClientMimeType()];}
  $fingerprint=hash('sha256',json_encode([$request->method(),$request->path(),$request->except(array_keys($files)),$files]));
  return DB::transaction(function()use($request,$next,$op,$fingerprint){
   $uid=$request->user()->getKey();DB::table('users')->where('id',$uid)->lockForUpdate()->first();
   $prior=DB::table('nurselink_sync_receipts')->where('user_id',$uid)->where('operation_id',$op)->first();
   if($prior){abort_unless(hash_equals($prior->fingerprint,$fingerprint),409,'A sync operation cannot be reused for different data.');return response($prior->response_json,$prior->status_code)->header('Content-Type','application/json')->header('X-NurseLink-Replayed','true');}
   $response=$next($request);
   if($response->getStatusCode()>=200&&$response->getStatusCode()<300){DB::table('nurselink_sync_receipts')->insert(['user_id'=>$uid,'operation_id'=>$op,'fingerprint'=>$fingerprint,'status_code'=>$response->getStatusCode(),'response_json'=>$response->getContent(),'created_at'=>now(),'updated_at'=>now()]);}
   return $response;
  });
 }
}
