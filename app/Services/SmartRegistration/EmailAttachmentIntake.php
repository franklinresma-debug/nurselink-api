<?php
namespace App\Services\SmartRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmailAttachmentIntake
{
    private int $mimeBytes=0;
    private int $mimeCount=0;
    public function link(int $id,string $userId,string $actor,string $note):void
    {
        $copies=[];
        try {DB::transaction(function()use($id,$userId,$actor,$note,&$copies){
            $package=DB::table('nurselink_email_intakes')->where('id',$id)->lockForUpdate()->first();
            abort_unless($package,404);abort_if($package->status==='linked',409,'This intake was already linked.');
            $profile=DB::table('nurselink_smart_registration_profiles')->where('user_id',$userId)->lockForUpdate()->first();
            abort_unless($profile,422,'The candidate must open Smart Registration before linking documents.');
            $membership=DB::table('nurselink_memberships')->where('user_id',$userId)->first();
            abort_unless($membership && (in_array($membership->status,['draft','needs_information'],true) || ($membership->status==='approved' && !in_array($membership->profile_update_status??'',['submitted','under_review','ready_for_approval'],true))),422,'The candidate record is currently locked for review.');
            $docs=DB::table('nurselink_email_intake_documents')->where('intake_id',$id)->orderBy('id')->lockForUpdate()->get();
            abort_if($docs->isEmpty() || $docs->contains(fn($d)=>$d->security_status!=='clean' || !in_array($d->status,['extracted','needs_input'],true)),422,'Complete security scanning and extraction before linking.');
            foreach($docs as $doc) {
                if(DB::table('nurselink_smart_registration_documents')->where('user_id',$userId)->where('sha256',$doc->sha256)->exists())continue;
                $path='nurselink-smart-registration/'.preg_replace('/[^A-Za-z0-9._-]/','_',$userId).'/'.Str::uuid().'.'.strtolower(pathinfo($doc->original_name,PATHINFO_EXTENSION));
                abort_unless(Storage::disk('local')->copy($doc->storage_path,$path),500,'Unable to copy inbound document.');$copies[]=$path;
                $result=json_decode($doc->result,true)?:[];
                DB::table('nurselink_smart_registration_documents')->insert(['user_id'=>$userId,'original_name'=>$doc->original_name,'storage_path'=>$path,'sha256'=>$doc->sha256,
                    'mime_type'=>Storage::disk('local')->mimeType($path),'file_size'=>Storage::disk('local')->size($path),'is_current'=>true,'version'=>1,'document_type'=>$result['document_type']??'other',
                    'security_status'=>'clean','security_message'=>'Inbound attachment passed security scanning.','security_scanned_at'=>now(),
                    'extraction_status'=>$doc->status,'extraction_message'=>'Received by email. Review extracted information before saving.',
                    'extracted_fields'=>json_encode(($result['fields']??[])+['_structured'=>$result['structured_records']??[],'_document'=>$result['extraction_details']??[]]),'extracted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            }
            $identity=json_decode($package->identity_result??'[]',true)?:[];
            $identity['link_review']=['actor'=>$actor,'note'=>$note,'at'=>now()->toIso8601String()];
            DB::table('nurselink_email_intakes')->where('id',$id)->update(['status'=>'linked','proposed_user_id'=>$userId,'identity_result'=>json_encode($identity),'updated_at'=>now()]);
        });} catch(\Throwable $e){foreach($copies as $p)Storage::disk('local')->delete($p);throw $e;}
    }

    public function ingest(string $sourceKey,string $sender,array $attachments):int
    {
        $id=DB::table('nurselink_email_intakes')->where('source_key',$sourceKey)->value('id');
        if(!$id) $id=DB::table('nurselink_email_intakes')->insertGetId(['source_key'=>$sourceKey,'sender'=>$sender,'status'=>'received','created_at'=>now(),'updated_at'=>now()]);
        foreach(array_slice($attachments,0,20) as $attachment) {
            $name=mb_substr(basename(str_replace('\\','/',$attachment['name'])),0,255);$bytes=$attachment['bytes'];
            $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
            if(!in_array($extension,['pdf','png','jpg','jpeg','doc','docx'],true) || strlen($bytes)>15*1024*1024 || strlen($bytes)===0) continue;
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $allowed=['pdf'=>['application/pdf'],'png'=>['image/png'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],
                'doc'=>['application/msword','application/x-ole-storage','application/CDFV2'],'docx'=>['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document']];
            if(!in_array($mime,$allowed[$extension],true)) continue;
            $sha=hash('sha256',$bytes);
            if(DB::table('nurselink_email_intake_documents')->where('intake_id',$id)->where('sha256',$sha)->exists()) continue;
            $path='nurselink-email-intake/'.$id.'/'.Str::uuid().'.'.$extension;
            if(!Storage::disk('local')->put($path,$bytes)) throw new \RuntimeException('Unable to store inbound attachment.');
            try {
                $doc=DB::table('nurselink_email_intake_documents')->insertGetId(['intake_id'=>$id,'original_name'=>$name,'storage_path'=>$path,'sha256'=>$sha,'status'=>'queued','security_status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
            } catch(\Throwable $e) {Storage::disk('local')->delete($path);throw $e;}
            \App\Jobs\ProcessEmailIntakeDocument::dispatch($doc)->onConnection('database');
        }
        $has=DB::table('nurselink_email_intake_documents')->where('intake_id',$id)->exists();
        if(!$has) DB::table('nurselink_email_intakes')->where('id',$id)->update(['status'=>'awaiting_document','message'=>'No supported attachment was received.','updated_at'=>now()]);
        return (int)$id;
    }

    public function finish(int $intake):void
    {
        DB::transaction(function()use($intake){
            $package=DB::table('nurselink_email_intakes')->where('id',$intake)->lockForUpdate()->first();
            if(!$package || $package->status==='linked') return;
            $docs=DB::table('nurselink_email_intake_documents')->where('intake_id',$intake)->orderBy('id')->get();
            if($docs->contains(fn($d)=>in_array($d->status,['queued','processing'],true))) $status='documents_processing';
            elseif($docs->contains(fn($d)=>$d->security_status==='quarantined')) $status='quarantined';
            elseif($docs->contains(fn($d)=>in_array($d->status,['failed','needs_input'],true))) $status='document_review';
            else $status='ready_for_review';
            $fields=['email'=>$package->sender];$conflict=false;
            foreach($docs as $doc) {
                $result=json_decode($doc->result??'[]',true)?:[];
                foreach($result['fields']??[] as $key=>$candidate) {
                    if(!is_scalar($candidate['value']??null)) continue;
                    if(isset($fields[$key]) && mb_strtolower((string)$fields[$key])!==mb_strtolower((string)$candidate['value'])) $conflict=true;
                    else $fields[$key]=$candidate['value'];
                }
                foreach($result['structured_records']['personal_data']??[] as $row) if(preg_match('/^passport (?:number|no)$/i',$row['label']??'')) $fields['passport_number']=$row['value'];
            }
            $identity=app(CandidateIdentityResolver::class)->resolve($fields);
            if($status==='ready_for_review' && ($conflict || $identity['status']==='identity_review')) $status='identity_review';
            DB::table('nurselink_email_intakes')->where('id',$intake)->update(['status'=>$status,'proposed_user_id'=>count($identity['matches'])===1?$identity['matches'][0]['user_id']:null,'identity_result'=>json_encode($identity),'updated_at'=>now()]);
        });
    }

    public function mimeParts(object $part,callable $fetch,string $number='',int $depth=0):array
    {
        if($depth===0){$this->mimeBytes=0;$this->mimeCount=0;}
        if($depth>10 || $this->mimeCount>=20 || $this->mimeBytes>=30*1024*1024) return [];$found=[];
        foreach($part->parts??[] as $i=>$child) $found=array_merge($found,$this->mimeParts($child,$fetch,$number===''?(string)($i+1):$number.'.'.($i+1),$depth+1));
        $name=null;
        foreach(array_merge($part->dparameters??[],$part->parameters??[]) as $parameter)
            if(in_array(strtolower($parameter->attribute),['filename','name'],true)) $name=imap_utf8($parameter->value);
        if($name && ($part->bytes??0)<=21*1024*1024) {
            if($this->mimeBytes+(int)($part->bytes??0)>30*1024*1024) return $found;
            $raw=$fetch($number?:'1');
            if(strlen($raw)>21*1024*1024) return $found;
            $this->mimeBytes+=strlen($raw);$this->mimeCount++;
            $bytes=match((int)($part->encoding??0)){3=>base64_decode($raw,true),4=>quoted_printable_decode($raw),default=>$raw};
            if(is_string($bytes)) $found[]=['name'=>$name,'bytes'=>$bytes];
        }
        return array_slice($found,0,20);
    }
}
