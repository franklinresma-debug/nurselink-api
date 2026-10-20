<?php
namespace App\Services\SmartRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkflowReviewService
{
    public function refresh(string $userId): array
    {
        return DB::transaction(function()use($userId){
            $profile=DB::table('nurselink_smart_registration_profiles')->where('user_id',$userId)->lockForUpdate()->first();
            if(!$profile) return ['issues'=>[],'open_count'=>0,'status'=>'awaiting_document'];
            $docs=DB::table('nurselink_smart_registration_documents')->where('user_id',$userId)->where('is_current',true)->orderBy('id')->lockForUpdate()->get();
            $issues=$this->detect((array)$profile,$docs->map(fn($d)=>(array)$d)->all());
            $identity=app(CandidateIdentityResolver::class)->resolve((array)$profile,$userId);
            if($identity['matches']) $issues[]=['code'=>'identity_review','field'=>null,'message'=>'Another candidate record matches an identifier. Review identity before approval.','evidence'=>$identity];
            $active=[];
            foreach($issues as $issue) {
                $fingerprint=hash('sha256',json_encode([$issue,$profile->structured_data,$profile->updated_at]));
                $active[]=$fingerprint;
                DB::table('nurselink_ocr_review_issues')->insertOrIgnore(array_replace($issue,['user_id'=>$userId,'fingerprint'=>$fingerprint,'evidence'=>json_encode($issue['evidence']),'status'=>'open','created_at'=>now(),'updated_at'=>now()]));
            }
            DB::table('nurselink_ocr_review_issues')->where('user_id',$userId)->whereNotIn('fingerprint',$active)->where('status','!=','superseded')->update(['status'=>'superseded','updated_at'=>now()]);
            $rows=DB::table('nurselink_ocr_review_issues')->where('user_id',$userId)->whereIn('fingerprint',$active)->orderBy('id')->get();
            return ['issues'=>$rows->map(fn($r)=>['id'=>$r->id,'code'=>$r->code,'field'=>$r->field,'message'=>$r->message,'status'=>$r->status])->all(),
                'open_count'=>$rows->where('status','open')->count(),'status'=>$rows->where('status','open')->count()?'review_required':($docs->isEmpty()?'awaiting_document':'ready_for_review')];
        });
    }

    public function detect(array $profile,array $docs):array
    {
        $issues=[];$values=[];
        $add=function($code,$field,$message,$evidence)use(&$issues){$issues[]=compact('code','field','message','evidence');};
        foreach($docs as $doc) {
            $evidence=['document_id'=>$doc['id'],'sha256'=>$doc['sha256']??null,'state'=>$doc['extraction_status']??null,'security'=>$doc['security_status']??null];
            if(($doc['security_status']??'')==='quarantined') $add('quarantined',null,'A document is quarantined and cannot be accepted.',$evidence);
            elseif(($doc['security_status']??'')!=='clean') $add('security_review',null,'A document has not passed security scanning.',$evidence);
            if(in_array($doc['extraction_status']??'',['queued','processing'],true)) $add('processing',null,'Document processing must finish before approval.',$evidence);
            elseif(in_array($doc['extraction_status']??'',['failed','needs_input'],true)) $add('document_review',null,'Unreadable or failed extraction needs a documented manual review.',$evidence);
            $data=is_array($doc['extracted_fields']??null)?$doc['extracted_fields']:(json_decode($doc['extracted_fields']??'[]',true)?:[]);
            foreach($data as $field=>$candidate) {
                if(str_starts_with($field,'_') || !is_array($candidate) || !is_scalar($candidate['value']??null)) continue;
                if(!empty($candidate['review_required']) || (float)($candidate['confidence']??1)<.75)
                    $add('low_confidence',$field,'An extracted field requires confirmation against its original source.',$evidence+['candidate_hash'=>hash('sha256',json_encode($candidate))]);
                foreach([$candidate,...($candidate['alternatives']??[])] as $variant) {
                    $v=preg_replace('/[^\pL\pN]/u','',mb_strtolower((string)($variant['value']??'')));
                    if($v!=='') $values[$field][$v][]= $evidence;
                }
            }
        }
        foreach($values as $field=>$variants) {
            $saved=preg_replace('/[^\pL\pN]/u','',mb_strtolower((string)($profile[$field]??'')));
            if(count($variants)>1 || ($saved!=='' && !isset($variants[$saved]))) $add('field_conflict',$field,'Document values conflict with each other or the confirmed profile.',['variants_hash'=>hash('sha256',json_encode($variants))]);
        }
        if(!empty($profile['primary_license_expiry']) && $profile['primary_license_expiry']<date('Y-m-d')) $add('expired_license','primary_license_expiry','Primary license has expired. Review renewal evidence.',['expiry'=>$profile['primary_license_expiry']]);
        $records=is_array($profile['structured_data']??null)?$profile['structured_data']:(json_decode($profile['structured_data']??'[]',true)?:[]);
        foreach($records['employment']??[] as $index=>$row) if(!empty($row['started_on']) && !empty($row['ended_on']) && $row['ended_on']<$row['started_on'])
            $add('employment_dates','employment','An employment end date precedes its start date.',['record'=>$index,'hash'=>hash('sha256',json_encode($row))]);
        foreach($records['credentials']??[] as $index=>$row) if(!empty($row['expires_on']) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$row['expires_on']) && $row['expires_on']<date('Y-m-d'))
            $add('expired_credential','credentials','A recorded credential has expired. Review renewal or applicability.',['record'=>$index,'hash'=>hash('sha256',json_encode($row))]);
        return $issues;
    }

    public function assertReady(string $userId):void
    {
        $review=$this->refresh($userId);
        if($review['status']==='awaiting_document' && DB::table('nurselink_smart_registration_profiles')->where('user_id',$userId)->exists())
            throw ValidationException::withMessages(['workflow'=>'Upload and review supporting evidence before approval.']);
        if($review['open_count']) throw ValidationException::withMessages(['workflow'=>'Resolve all document and identity review issues before approval.']);
        if(DB::table('nurselink_smart_registration_documents')->where('user_id',$userId)->where('is_current',true)->whereIn('extraction_status',['queued','processing','blocked'])->exists())
            throw ValidationException::withMessages(['workflow'=>'Processing or blocked documents prevent approval.']);
    }

    public function resolve(string $userId,int $issueId,string $actor,string $note):void
    {
        DB::transaction(function()use($userId,$issueId,$actor,$note){
            $this->refresh($userId);
            $issue=DB::table('nurselink_ocr_review_issues')->where('user_id',$userId)->where('id',$issueId)->lockForUpdate()->first();
            abort_unless($issue && $issue->status==='open',409,'This issue changed. Refresh the review.');
            abort_if(in_array($issue->code,['quarantined','processing','security_review'],true),422,'Correct the document processing or security problem before closing this issue.');
            DB::table('nurselink_ocr_review_issues')->where('id',$issueId)->update(['status'=>'resolved','resolved_by'=>$actor,'resolution_note'=>$note,'resolved_at'=>now(),'updated_at'=>now()]);
        });
    }
}
