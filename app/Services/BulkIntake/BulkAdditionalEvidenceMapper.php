<?php
namespace App\Services\BulkIntake;

class BulkAdditionalEvidenceMapper
{
    public function enrich(object $file, array $fields): array
    {
        $text=(string)($file->extracted_text ?? '');
        $details=$fields['__roster_details']['value'] ?? [];
        $source=(string)$file->original_name;
        $detail=function(string $label,string $value) use (&$details,$source): void {
            if(trim($value)!=='')$details[$label.' — '.$source]=trim($value).' [document claim; review required]';
        };
        if($file->document_type==='cv') {
            $personal=preg_split('/^\s*(?:EDUCATION|LICENSE|WORK HISTORY|REFERENCES)\b/imu',$text)[0];
            if(preg_match('/^Civil Status\s*:\s*([^\n]+)/imu',$personal,$m))$detail('Civil status',$m[1]);
            if(preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu',$personal,$emails)) {
                foreach(array_unique($emails[0]) as $email)if(strtolower($email)!==strtolower($fields['email']['value'] ?? ''))$detail('Additional email',$email);
            }
            if(preg_match('/SCFHS LICENSE\s*[—–-]\s*([^\n]+)\n([^\n]+)\n+SCFHS Profile No\.?\s*:\s*(\d+)\nValid until\s*:\s*([^\n]+)/iu',$text,$m)) {
                $detail('Saudi registration classification',trim($m[1]));
                $detail('SCFHS profile reference (not a license number)',$m[3]);
                $expiry=\DateTimeImmutable::createFromFormat('!F j, Y',trim($m[4]));
                if($expiry && $expiry->format('F j, Y')===trim($m[4])) {
                    $detail('SCFHS expiry',$expiry->format('Y-m-d'));
                    $records=$fields['_structured']['credentials'] ?? [];
                    $records=array_values(array_filter($records,fn($r)=>!str_contains($r['title'] ?? '', 'SCFHS')));
                    $records[]=['title'=>'SCFHS — '.trim($m[1]),'issuing_authority'=>trim($m[2]),
                        'expires_on'=>$expiry->format('Y-m-d'),'confidence'=>.82,'review_status'=>'review_required',
                        'profile_reference'=>$m[3],'source_excerpt'=>$m[0]];
                    $fields['_structured']['credentials']=$records;
                }
            }
            if(preg_match('/Registration date\s*:\s*([^\n]+)/iu',$text,$m))$detail('PRC registration date',$m[1]);
        }
        // Visual transcriptions are stored separately from raw OCR and never mark a record approved.
        $visual=$fields['_document']['visual_transcription'] ?? [];
        foreach($visual['details'] ?? [] as $label=>$evidence) {
            $detail($label,(string)$evidence['value']);
        }
        foreach($visual['profile_fields'] ?? [] as $key=>$evidence) {
            if(!in_array($key,['full_name','first_name','middle_name','last_name','address_line1','city','province','country','specialty','primary_license_country','sex','primary_license_issued_on'],true))continue;
            $fields[$key]=['value'=>$evidence['value'],'confidence'=>.86,'source_file_id'=>(int)$file->id,
                'source_excerpt'=>$evidence['excerpt'],'source_method'=>'visual_transcription',
                'review_required'=>true,'normalization_version'=>'lbs-bulk-evidence-v3'];
        }
        foreach($visual['records'] ?? [] as $group=>$records) {
            if(!in_array($group,['education','employment','skills','credentials','languages','references'],true))continue;
            $fields['_structured'][$group]=array_map(function($record) use($file){
                return $record+['confidence'=>.86,'review_status'=>'review_required','source_file_id'=>(int)$file->id,
                    'source_method'=>'visual_transcription'];
            },$records);
        }
        // A reviewed transcription can explicitly reject unsupported current-job inference.
        foreach ($visual['clear_profile_fields'] ?? [] as $key) {
            if (in_array($key, ['current_position', 'current_employer'], true)) unset($fields[$key]);
        }
        if($details!==[])$fields['__roster_details']=['value'=>$details];
        return $fields;
    }
}
