<?php
namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Services\SmartRegistration\EmailAttachmentIntake;
use App\Services\SmartRegistration\LocalOcrService;
use App\Services\Security\ClamAvScanner;
class ProcessEmailIntakeDocument implements ShouldQueue
{
 use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
 public int $tries=3;public int $timeout=180;
 public function __construct(public int $documentId){$this->onQueue('document-extraction');}
 public function backoff():array{return [10,30,90];}
 public function handle(ClamAvScanner $scanner,LocalOcrService $ocr,EmailAttachmentIntake $intake):void {
  $doc=DB::table('nurselink_email_intake_documents')->where('id',$this->documentId)->first();
  if(!$doc || in_array($doc->status,['extracted','needs_input','blocked'],true)) return;
  if(config('security_scanning.driver')!=='clamav') throw new \RuntimeException('Security scanner is not configured.');
  DB::table('nurselink_email_intake_documents')->where('id',$doc->id)->update(['status'=>'processing','updated_at'=>now()]);
  $scan=$scanner->scan('local',$doc->storage_path);
  if($scan['status']!=='clean') {
   $path='nurselink-quarantine/email-'.basename($doc->storage_path);
   if(!Storage::disk('local')->move($doc->storage_path,$path)) throw new \RuntimeException('Unable to quarantine inbound document.');
   DB::table('nurselink_email_intake_documents')->where('id',$doc->id)->update(['status'=>'blocked','security_status'=>'quarantined','storage_path'=>$path,'result'=>null,'updated_at'=>now()]);
  } else {
   $result=$ocr->extract(Storage::disk('local')->path($doc->storage_path),$doc->original_name);unset($result['text']);
   DB::table('nurselink_email_intake_documents')->where('id',$doc->id)->update(['status'=>$result['status'],'security_status'=>'clean','result'=>json_encode($result),'updated_at'=>now()]);
   if($result['pending']??false) self::dispatch($doc->id)->onConnection('database')->delay(now()->addSeconds(3));
  }
  $intake->finish($doc->intake_id);
 }
 public function failed(\Throwable $error):void {
  $doc=DB::table('nurselink_email_intake_documents')->where('id',$this->documentId)->first();if(!$doc)return;
  DB::table('nurselink_email_intake_documents')->where('id',$doc->id)->update(['status'=>'failed','updated_at'=>now()]);
  app(EmailAttachmentIntake::class)->finish($doc->intake_id);
 }
}
