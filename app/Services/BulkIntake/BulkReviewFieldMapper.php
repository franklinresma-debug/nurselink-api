<?php
namespace App\Services\BulkIntake;

/** Bounded, document-supported supplements for the bulk review draft only. */
class BulkReviewFieldMapper
{
    public function enrich(object $file, array $fields): array
    {
        $text = str_replace("\r", '', (string) ($file->extracted_text ?? ''));
        $type = (string) ($file->document_type ?? '');
        $add = function (string $key, mixed $value, string $excerpt, float $confidence = .82) use (&$fields, $file): void {
            if ($value === null || $value === '') return;
            $previous = $fields[$key] ?? null;
            if ($previous && ($previous['confidence'] ?? 0) > $confidence) return;
            $entry = ['value'=>$value, 'confidence'=>$confidence, 'source_file_id'=>(int)$file->id,
                'source_excerpt'=>$excerpt, 'source_method'=>'ocr', 'review_required'=>true,
                'normalization_version'=>'lbs-bulk-evidence-v2'];
            if ($previous && (string)($previous['value'] ?? '') !== (string)$value) {
                $entry['alternatives']=[$previous]; $entry['conflict']=true;
            }
            $fields[$key]=$entry;
        };
        // Institutional locations are not the applicant's residential address.
        foreach (['city','province','country'] as $key) {
            if (isset($fields[$key]) && preg_match('/bachelor|science|nursing|college|university/iu', (string)($fields[$key]['value'] ?? ''))) unset($fields[$key]);
        }
        if (in_array($type, ['cv','identity','nursing_diploma'], true)) {
            if (preg_match('/\b(?:sex|gender)[ \t]*[:=][ \t]*(female|male|F|M)\b/iu', $text, $m))
                $add('sex', in_array(strtolower($m[1]), ['f','female']) ? 'Female':'Male', $m[0]);
            elseif ($type === 'nursing_diploma' && preg_match('/\bGender\s*:/iu',$text)
                && preg_match_all('/^[ \t]*(female|male)[ \t]*$/imu',$text,$matches) === 1)
                $add('sex',ucfirst(strtolower($matches[1][0])),'Gender: (OCR column-separated) / '.$matches[0][0],.72);
        }
        if ($type === 'cv') {
            $personal = preg_split('/^\s*(?:EDUCATION|LICENSE|WORK HISTORY|REFERENCES|CHARACTER REFERENCES)\b/imu', $text)[0];
            if (preg_match('/^(?:Phone|Mobile|Contact)(?:\s+(?:No\.?|Number))?\s*:\s*((?:\+63|0)\s*9(?:[ -]*\d){9})(?!\d)/imu',$personal,$m))
                $add('phone',preg_replace('/[ -]/','',$m[1]),$m[0]);
            if (preg_match('/^Address\s*:\s*([^\n]+),\s*([^,\n]+),\s*(Philippines)\s*$/imu',$text,$m)) {
                $add('province',trim($m[2]),$m[0]); $add('country','Philippines',$m[0]);
                // Only an explicitly labeled city is promoted; do not guess a locality boundary.
            }
            if (preg_match('/^[ \t]*(Registered Nurse)[ \t]*$/imu',$text,$m)) $add('professional_title','Registered Nurse',$m[0]);
            if (preg_match('/^(?:Specialty|Speciality)\s*:\s*([^\n]{2,100})$/imu',$text,$m)) $add('specialty',trim($m[1]),$m[0]);
            if (preg_match('/^Years (?:of )?Experience\s*:\s*(\d{1,2})\s*(?:years?)?\s*$/imu',$text,$m)) $add('years_experience',(int)$m[1],$m[0]);
            if (preg_match('/^(Bachelor of Science in Nursing)\s+((?:19|20)\d{2})\s*\n+([^\n]*(?:College|University)[^\n]*)/imu',$text,$m)) {
                $add('highest_nursing_education',$m[1],$m[0]); $add('graduation_year',(int)$m[2],$m[0]);
                // A year alone must not be converted into an invented day/month.
                $education = array_values(array_filter($fields['_structured']['education'] ?? [],
                    fn($record) => !preg_match('/Bachelor of Science in Nursing/iu', $record['qualification'] ?? '')));
                $education[]=['qualification'=>$m[1],'field_of_study'=>'Nursing',
                    'institution'=>trim($m[3]),'confidence'=>.82,'review_status'=>'review_required',
                    'source_excerpt'=>$m[0],'graduation_year'=>(int)$m[2],'date_precision'=>'year'];
                $fields['_structured']['education']=$education;
            }
            if (preg_match('/(?:Professional Regulation Commission\s*\(PRC\))\s*\n+License No\.?\s*:\s*(\d{5,8})\b/iu',$text,$m)) {
                $add('primary_license_number',$m[1],$m[0],.80);
                $add('primary_license_country','Philippines',$m[0],.80);
            }
            // Role and date range share a line; only consume the immediately following employer.
            $month='(?:January|February|March|April|May|June|July|August|September|Sept|October|November|December)';
            $date=$month.'\s+(?:\d{1,2},?\s+)?(?:19|20)\d{2}';
            $pattern='/^[ \t+©•.]*([A-Za-z][A-Za-z ()\/-]{2,90}?)\s+('.$date.')\s*[—–-]\s*('.$date.'|Present|Current)\s*\n([^\n]*(?:Hospital|Medical Cent(?:er|re)|Clinic)[^\n]*)/imu';
            if (preg_match_all($pattern,$text,$jobs,PREG_SET_ORDER)) {
                $records=[];
                foreach ($jobs as $job) {
                    $current=(bool)preg_match('/^(present|current)$/i',$job[3]);
                    $employer=trim($job[4]);
                    $record=['position_title'=>trim($job[1]),'employer'=>$employer,'is_current'=>$current,
                        'date_text'=>$job[2].' — '.$job[3],'confidence'=>.82,'source_excerpt'=>$job[0],
                        'review_status'=>'review_required'];
                    foreach (['started_on'=>2,'ended_on'=>3] as $key=>$index) {
                        if (preg_match('/\d{1,2},\s*\d{4}$/',$job[$index]) && ($stamp=strtotime($job[$index]))!==false)
                            $record[$key]=date('Y-m-d',$stamp);
                    }
                    if ($current) { $add('current_position',$record['position_title'],$job[0]); $add('current_employer',$employer,$job[0]); }
                    $records[]=$record;
                }
                $fields['_structured']['employment']=$records;
            }
        }
        // Scope number and expiry to an actual PRC card; never borrow SCFHS expiry from a CV.
        if ($type === 'prc_license' && preg_match('/Professional\s+Regulation/iu',$text)
            && preg_match('/REGISTRAT(?:I)?ON\s*NO\.?\s*[^\d\n]{0,8}(\d{6,8})\b/iu',$text,$number)) {
            $add('primary_license_number',$number[1],$number[0],.90);
            $add('primary_license_country','Philippines','Professional Regulation Commission / '.$number[0],.90);
            if (preg_match('/VALID\s+UNTIL[^\d\n]{0,12}(\d{2})\/(\d{2})\/(\d{4})/iu',$text,$m)
                && checkdate((int)$m[1],(int)$m[2],(int)$m[3]))
                $add('primary_license_expiry',sprintf('%04d-%02d-%02d',$m[3],$m[1],$m[2]),$m[0],.88);
        }
        return app(BulkAdditionalEvidenceMapper::class)->enrich($file, $fields);
    }
}
