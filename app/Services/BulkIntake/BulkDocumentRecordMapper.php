<?php
namespace App\Services\BulkIntake;

use App\Services\SmartRegistration\ApplicantDocumentParser;
use Illuminate\Support\Facades\DB;

class BulkDocumentRecordMapper
{
    private const MAP = [
        'education'=>['education','qualification field_of_study institution country started_on completed_on'],
        'employment'=>['employment','position_title employer country city started_on ended_on is_current responsibilities'],
        'credentials'=>['credentials','title credential_number issuing_authority country issued_on expires_on'],
        'skills'=>['competencies','name domain proficiency'],
        'languages'=>['languages','language speaking reading writing'],
        'references'=>['references','name position_title organization phone email'],
    ];
    public function stage(int $candidateId,int $fileId,string $text): void
    {
        $pages=[];
        foreach (explode("\f",$text) as $index=>$page) $pages[]=['page'=>$index+1,'text'=>$page,'method'=>'text'];
        $file=DB::table('nurselink_bulk_intake_files')->where('id',$fileId)->first();
        $stored=json_decode((string)($file->extracted_fields ?? ''),true) ?: [];
        $result=isset($stored['_structured']) ? ['records'=>$stored['_structured']]
            : app(ApplicantDocumentParser::class)->parse($pages);
        foreach (self::MAP as $group=>[$suffix,$columns]) {
            $table='nurselink_bulk_intake_'.$suffix;
            foreach ($result['records'][$group] ?? [] as $record) {
                $values=array_intersect_key($record,array_fill_keys(explode(' ',$columns),true));
                $required=match($group) {'education'=>'qualification','employment'=>'position_title','skills'=>'name','languages'=>'language','references'=>'name',default=>null};
                if ($required && empty($values[$required])) continue;
                foreach (['started_on','ended_on','completed_on','issued_on','expires_on'] as $date)
                    if (isset($values[$date]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$values[$date])) unset($values[$date]);
                $lengths=['qualification'=>180,'field_of_study'=>180,'institution'=>220,'country'=>120,
                    'position_title'=>$group==='references' ? 220 : 190,'employer'=>255,'city'=>160,
                    'title'=>255,'issuing_authority'=>255,'name'=>$group==='references' ? 180 : 220,
                    'domain'=>120,'proficiency'=>40,'language'=>100,'speaking'=>40,'reading'=>40,'writing'=>40,
                    'organization'=>255,'phone'=>190,'email'=>255];
                foreach ($lengths as $key=>$max) if (isset($values[$key])) $values[$key]=mb_substr((string)$values[$key],0,$max);
                if ($group==='employment') $values['is_current']=(bool)($record['is_current'] ?? false);
                if ($group==='references') $values+=['privacy_status'=>'private','reference_type'=>'professional_reference'];
                if ($group==='credentials') $values['verification_status']='unverified';
                $notes='Document-supported draft; verify before import. '.json_encode($record,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $existing=DB::table($table)->where('candidate_id',$candidateId)->where('source_file_id',$fileId);
                foreach ($values as $key=>$value) $existing->where($key,$value);
                if ($existing->exists()) continue;
                DB::table($table)->insert($values+['candidate_id'=>$candidateId,'source_file_id'=>$fileId,
                    'confidence'=>$record['confidence'],'review_status'=>'review_required','review_notes'=>$notes,
                    'created_at'=>now(),'updated_at'=>now()]);
            }
        }
    }
}
