<?php
namespace App\Services\SmartRegistration;

use Symfony\Component\Process\Process;

/** Request-temporary files only; deliberately has no applicant, queue or database writes. */
class OcrSandboxService
{
    private function run(array $args): string {
        $p = new Process($args); $p->setTimeout(30); $p->mustRun(); return $p->getOutput();
    }
    public function inspect(string $path, string $name): array {
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
            $size = @getimagesize($path);
            if (!$size || $size[0]*$size[1] > 30000000) throw new \RuntimeException('Invalid image or image exceeds 30 megapixels.');
            return ['total_pages'=>1];
        }
        $info=$this->run(['pdfinfo',$path]);
        if (!preg_match('/^Pages:\s+(\d+)/m',$info,$m) || (int)$m[1]>100) throw new \RuntimeException('PDF must be readable and contain at most 100 pages.');
        return ['total_pages'=>(int)$m[1]];
    }
    public function page(string $path, string $name, int $page): array {
        $start=microtime(true); $total=$this->inspect($path,$name)['total_pages'];
        if ($page<1 || $page>$total) throw new \RuntimeException('Invalid page number.');
        $pdf=strtolower(pathinfo($name,PATHINFO_EXTENSION))==='pdf';
        $temporary=null; $text=''; $quality=null; $engine='pdf_text';
        try {
            if ($pdf) $text=trim($this->run(['pdftotext','-f',(string)$page,'-l',(string)$page,'-layout',$path,'-']));
            // Force recognition for scanned/mixed pages; no production PDF checkpoint directory is used.
            $scanned=$pdf && preg_match('/^\s*'.preg_quote((string)$page,'/').'\s+\d+\s+image\s+\d+\s+\d+/m',$this->run(['pdfimages','-list',$path]));
            if (!$pdf || $text==='' || $scanned) {
                if ($pdf) {
                    $temporary=tempnam(sys_get_temp_dir(),'lbs-sandbox-');
                    $this->run(['pdftoppm','-f',(string)$page,'-l',(string)$page,'-singlefile','-scale-to','2400','-png',$path,$temporary]);
                    $path=$temporary.'.png';
                }
                $recognized=app(AccurateImageOcrService::class)->extract($path);
                if ($recognized===[] || trim($recognized['text'])==='') throw new \RuntimeException('OCR unavailable or page unreadable. No simulated OCR result was substituted.');
                $text=$recognized['text']; $quality=$recognized['quality']; $engine=$quality['engine'] ?? 'unknown';
            }
            if (mb_strlen($text)>120000) throw new \RuntimeException('Page text exceeds sandbox limit.');
            return ['page'=>$page,'text'=>$text,'method'=>$engine==='pdf_text'?'text':'ocr','status'=>'readable','engine'=>$engine,'ocr_quality'=>$quality,'seconds'=>round(microtime(true)-$start,3)];
        } finally { if ($temporary) { @unlink($temporary.'.png'); @unlink($temporary); } }
    }
    public function parse(array $pages): array {
        $start=microtime(true);
        $parsed=app(OcrSandboxDocumentPacketParser::class)->parse($pages,'cv');
        app(AccurateImageOcrService::class)->annotate($parsed['fields'],$parsed['records'],$pages);
        $this->repairSpacing($parsed);
        $this->repairGrouping($parsed);
        $this->extractDocumentDetails($parsed,$pages);
        $schema=[
            'Personal Details'=>['first_name','last_name','birth_date','address_line1','city','email','phone'],
            'Education & Trainings'=>['highest_nursing_education','graduation_year','@education','@training','primary_license_number','@credentials'],
            'Work Experiences'=>['professional_title','specialty','years_experience','@employment'],
        ];
        $categories=[]; foreach($schema as $name=>$expected) $categories[$name]=['expected'=>$expected,'items'=>[]];
        foreach($parsed['fields'] as $key=>$field) {
            $category='Personal Details'; foreach($schema as $name=>$expected) if(in_array($key,$expected,true)) {$category=$name;break;}
            if(in_array($key,['current_position','current_employer','current_position_started_on','current_position_ended_on','employment_countries','healthcare_facility_names','employment_positions'],true)) $category='Work Experiences';
            if(preg_match('/^(?:prc_|saudi_|license_|primary_license_|nclex_|bls_|acls_|pals_)/',$key)) $category='Education & Trainings';
            $categories[$category]['items'][]=['key'=>$key,...$field];
        }
        $groups=['summary'=>'Work Experiences','employment'=>'Work Experiences','education'=>'Education & Trainings','training'=>'Education & Trainings','credentials'=>'Education & Trainings','languages'=>'Personal Details','references'=>'Personal Details','skills'=>'Work Experiences','personal_data'=>'Personal Details'];
        foreach($parsed['records'] as $group=>$rows) foreach($rows as $row) {
            $category=$groups[$group] ?? 'Personal Details';
            if($group==='personal_data' && preg_match('/\b(?:prc|license|licence|nclex|bls|acls|pals|saudi council|training|certification)\b/i',$row['label'] ?? '')) $category='Education & Trainings';
            $categories[$category]['items'][]=['key'=>'@'.$group,...$row];
        }
        $diagnostics=[];
        foreach($pages as $p) foreach(preg_split('/\n/u',$p['text']) as $line) if(preg_match('/graduated|graduation/i',$line))
            $diagnostics[]=['page'=>$p['page'],'evidence'=>$line,'review_required'=>true,'note'=>'Compare this graduation label with education records and completion dates.'];
        $presentTotal=0;$expectedTotal=0;$confidence=[];
        foreach($categories as &$category) {
            $keys=array_column($category['items'],'key'); $present=[];
            foreach($category['expected'] as $key) if(in_array($key,$keys,true)) $present[]=$key;
            $category['missing']=array_values(array_diff($category['expected'],$present));
            $category['completeness_percent']=round(count($present)/count($category['expected'])*100,1);
            $scores=array_map(fn($i)=>max(0,min(1,(float)($i['confidence']??0))),$category['items']);
            $category['confidence_percent']=$scores ? round(array_sum($scores)/count($scores)*100,1) : null;
            foreach($category['items'] as &$item) $item['flagged']=($item['confidence']??0)<.85 || !empty($item['review_required']) || !empty($item['conflict']); unset($item);
            $confidence=array_merge($confidence,$scores);$presentTotal+=count($present);$expectedTotal+=count($category['expected']);
        } unset($category);
        return ['categories'=>$categories,'overall'=>['completeness_percent'=>round($presentTotal/$expectedTotal*100,1),'confidence_percent'=>$confidence?round(array_sum($confidence)/count($confidence)*100,1):null,'present_slots'=>$presentTotal,'expected_slots'=>$expectedTotal,'definition'=>'Completeness is fixed sandbox checklist coverage, not extraction accuracy. Confidence is parser/OCR evidence, not calibrated correctness.'], 'graduation_diagnostics'=>$diagnostics,'debug'=>$parsed,'seconds'=>round(microtime(true)-$start,3)];
    }
    private function extractDocumentDetails(array &$parsed,array $pages): void {
        $parser=app(OcrSandboxApplicantDocumentParser::class);
        $aliases=['passportnumber'=>'passport_number','passportno'=>'passport_number','passportdateissued'=>'passport_issued','passportissuedate'=>'passport_issued','passportexpirydate'=>'passport_expiry','passportexpirationdate'=>'passport_expiry','passportexpiry'=>'passport_expiry','passportplaceofissuance'=>'passport_place','passportplaceofissue'=>'passport_place','nbiclearancenumber'=>'nbi_number','nbiclearanceno'=>'nbi_number','nbinumber'=>'nbi_number','nbino'=>'nbi_number','nbidateissued'=>'nbi_issued','nbiissuedate'=>'nbi_issued','nbiexpirydate'=>'nbi_expiry','nbiexpirationdate'=>'nbi_expiry','nbiplaceofissuance'=>'nbi_place','nbiplaceofissue'=>'nbi_place','gsissssnumber'=>'gsis_sss','sssnumber'=>'gsis_sss','sssno'=>'gsis_sss','gsisnumber'=>'gsis_sss','gsisno'=>'gsis_sss','tin'=>'tin','tinnumber'=>'tin','taxidentificationnumber'=>'tin','philhealthnumber'=>'philhealth','philhealthno'=>'philhealth'];
        $scoped=['number'=>'number','no'=>'number','dateissued'=>'issued','issuedate'=>'issued','expirydate'=>'expiry','expirationdate'=>'expiry','placeofissuance'=>'place','placeofissue'=>'place'];
        foreach($pages as $page){$context=null;foreach(preg_split('/\n/u',$page['text']) as $line){
            $heading=preg_replace('/[^a-z]/','',strtolower(trim($line)));
            if(in_array($heading,['passport','passportdetails','passportinformation','nbiclearance','nbidetails'],true)){$context=str_starts_with($heading,'passport')?'passport':'nbi';continue;}
            if(!str_contains($line,':')){if(!preg_match('/^\s*[\d,-]+\s*$/',$line))$context=null;continue;}
            [$label,$raw]=array_map('trim',explode(':',$line,2));$label=preg_replace('/[^a-z]/','',strtolower($label));$key=$aliases[$label]??null;
            if($key===null&&$context!==null&&isset($scoped[$label]))$key=$context.'_'.$scoped[$label];
            if($key===null){$context=null;continue;}if($raw==='')continue;
            $value=$raw;if(preg_match('/_(issued|expiry)$/',$key)){$value=$parser->date($raw);if($value===null)continue;}
            if(in_array($key,['passport_number','nbi_number','gsis_sss','tin','philhealth'],true)&&!preg_match('/^(?=.*\d)[A-Za-z0-9][A-Za-z0-9 -]{3,30}$/u',$value))continue;
            $candidate=['value'=>$value,'confidence'=>($page['method']??'ocr')==='ocr'?.72:.85,'source_page'=>$page['page'],'source_pages'=>[$page['page']],'source_excerpt'=>$line,'source_method'=>$page['method']??'ocr','review_required'=>true,'normalization_version'=>'lbs-document-details-v1'];
            if(isset($parsed['fields'][$key])&&$parsed['fields'][$key]['value']!==$value){$parsed['fields'][$key]['alternatives'][]=$candidate;$parsed['fields'][$key]['conflict']=true;continue;}
            $parsed['fields'][$key]??=$candidate;
        }}
    }
    private function locationParts(string $text): ?array {
        $parts=array_values(array_filter(array_map('trim',preg_split('/[,\n]+/u',trim($text," \n\r,")))));
        $result=[];$local=[];
        foreach ($parts as $part) {
            if (preg_match('/^(Philippines|PH|(?:Kingdom of )?Saudi Arabia|United Arab Emirates|UAE|United States|USA|United Kingdom|UK|Canada|Australia|Qatar|Kuwait|Bahrain|Oman)$/iu',$part)) $result['country']=strcasecmp($part,'PH')===0?'Philippines':$part;
            elseif (preg_match('/^(?:postal\s*code\s*:?\s*|zip\s*code\s*:?\s*)?(\d{4,6})$/iu',$part,$m)) $result['postal_code']=$m[1];
            else $local[]=$part;
        }
        if (count($local)!==1 || !preg_match('/^[\pL .\'-]{2,100}$/u',$local[0])) return null;
        if (preg_match('/\b(provide|providing|assist|assisting|care|work|working|manage|managing|patients|duties|responsible|in|at)\b/iu',$local[0])) return null;
        if (!preg_match('/\b(city|province)$/iu',$local[0]) && empty($result['country']) && empty($result['postal_code'])) return null;
        $result['city']=$local[0];return $result;
    }
    private function repairGrouping(array &$parsed): void {
        foreach (['education'=>'institution','employment'=>'employer','references'=>'organization'] as $group=>$nameKey)
            foreach ($parsed['records'][$group] ?? [] as $index=>$record) {
                $name=(string)($record[$nameKey]??'');$changes=[];$parts=null;$short=$name;
                // Only split organization/location boundaries supported by a recognizable organization name and location.
                if (preg_match('/^(.+?\b(?:university|college|school|institute|hospital|clinic(?:\s+cent(?:er|re))?|medical cent(?:er|re)))\s*[,\n]+\s*(.+)$/isu',$name,$m)) {
                    $parts=$this->locationParts($m[2]);if($parts!==null)$short=trim($m[1]);
                }
                if ($parts===null && $group==='education' && preg_match('/^(.+?\b(?:university|college|school|institute)\b[^,\n]*)\s*[,\n]+\s*(.+)$/isu',$name,$m)) {
                    $parts=$this->locationParts($m[2]); if($parts!==null)$short=trim($m[1]);
                }
                if($parts!==null)$changes[$nameKey]=$short;
                foreach (['notes','responsibilities'] as $extra) if (!empty($record[$extra])) {
                    $remaining=[];foreach(preg_split('/\n/u',$record[$extra]) as $line){$location=$this->locationParts($line);if($location!==null && $name!=='' && $parts===null)$parts=$location;else $remaining[]=$line;}
                    if(implode("\n",$remaining)!==$record[$extra])$changes[$extra]=trim(implode("\n",$remaining));
                }
                if($parts!==null)foreach($parts as $key=>$value){if(!empty($record[$key])&&$record[$key]!==$value){$changes['grouping_conflict']=true;continue;}$changes[$key]=$value;}
                if(!$changes)continue;
                $target=&$parsed['records'][$group][$index];
                $target['grouping_original_values']=array_intersect_key($record,$changes);
                foreach($changes as $key=>$value)$target[$key]=$value;
                $target['grouping_repairs']=['organization_location_boundary'];$target['review_required']=true;
                foreach($changes as $key=>$value)if(in_array($key,['city','country','postal_code'],true))$target['field_provenance'][$key]=['source_pages'=>$record['source_pages']??[],'source_excerpt'=>$record['source_excerpt']??'','confidence'=>$record['confidence']??0,'review_required'=>true,'evidence_scope'=>'record_context'];
                unset($target);
            }
    }
    private function repairSpacing(array &$parsed): void {
        // Repair only supported field patterns. Keep raw OCR/provenance and confidence unchanged.
        foreach (['address_line1','address_line2'] as $key) if (isset($parsed['fields'][$key])) {
            $field=&$parsed['fields'][$key]; $original=(string)$field['value'];
            $value=preg_replace('/^(\d{1,6})([\pL]{3,})(?=\s+(?:street|st\.?|road|rd\.?|avenue|ave\.?|highway|drive|lane|boulevard)\b)/iu','$1 $2',$original);
            if ($value!==$original) {$field['value']=$value;$field['spacing_original_value']=$original;$field['spacing_repairs']=['house_number_street_boundary'];$field['review_required']=true;}
            unset($field);
        }
        foreach (['training','credentials'] as $group) foreach ($parsed['records'][$group] ?? [] as $index=>$record) {
            if (!isset($record['title'])) continue;
            $original=(string)$record['title'];
            $value=preg_replace('/\b(BLS|ACLS|PALS)(?=Workshop\b|Training\b|Course\b|Seminar\b|Certification\b|Certificate\b)/iu','$1 ',$original);
            foreach (['BasicLifeSupport'=>'Basic Life Support','AdvancedCardiacLifeSupport'=>'Advanced Cardiac Life Support','PediatricAdvancedLifeSupport'=>'Pediatric Advanced Life Support'] as $compact=>$expanded)
                $value=preg_replace('/\b'.preg_quote($compact,'/').'\b/iu',$expanded,$value);
            if ($value!==$original) {
                $parsed['records'][$group][$index]['title']=$value;
                $parsed['records'][$group][$index]['spacing_original_value']=$original;
                $parsed['records'][$group][$index]['spacing_repairs']=['known_training_phrase_boundary'];
                $parsed['records'][$group][$index]['review_required']=true;
            }
        }
    }
}
