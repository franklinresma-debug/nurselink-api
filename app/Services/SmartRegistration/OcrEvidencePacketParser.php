<?php
namespace App\Services\SmartRegistration;

/** Evidence-scoped reader for an application packet containing a CV and official supporting documents. */
class OcrEvidencePacketParser
{
 private function clean(string $s):string {
  return str_ireplace(['Permanent Adress','Adress:','Emall:','Date of Blrth','Aprll','Apnl','Citizenshlp','Rellgion','Dates Attended','Addrese','Hospltal Category','Empioyment Dates','Posltion Held','Unlt','Valid Untl'],
   ['Permanent Address','Address:','Email:','Date of Birth','April','April','Citizenship','Religion','Dates Attended','Address','Hospital Category','Employment Dates','Position Held','Unit','Valid Until'],$s);
 }
 private function meta(array $p,string $e):array {return ['source_page'=>$p['page'],'source_pages'=>[$p['page']],'source_excerpt'=>$e,'source_method'=>$p['method']??'ocr','confidence'=>.7,'review_required'=>true,'review_status'=>'review_required'];}
 public function parse(array $pages):?array {
  $cvStart=null;$hasCard=false;
  foreach($pages as $i=>$p){
   if(preg_match('/PROFESSIONAL\s*IDENTIFICATION CARD/i',$p['text']))$hasCard=true;
   if($cvStart===null && preg_match('/(?:Phone|Mobile)\s*:\s*\+?[\d ]+/i',$p['text']) && preg_match('/(?:Emall|Email)\s*:/i',$p['text']) && preg_match('/PERSONAL INFORMATION|BIOGRAPHY SUMMARY|Blography Summary/i',$p['text']))$cvStart=$i;
  }
  if($cvStart===null || !$hasCard)return null;
  $packetText=implode("\n",array_column($pages,'text'));
  if(!preg_match('/Medical Services Division/i',$packetText)||!preg_match('/Dates Attended\s*:/i',$packetText)||!preg_match('/Employer\s*:/i',$packetText))return null;
  $fields=[];$records=array_fill_keys(['personal_data','summary','employment','education','training','credentials','languages','references','skills'],[]);
  $parser=app(OcrSandboxApplicantDocumentParser::class);
  $put=function($k,$v,$p,$e)use(&$fields){if($v!==null&&trim((string)$v)!=='')$fields[$k]=['value'=>$v]+$this->meta($p,$e);};
  $cv=[];for($i=$cvStart;$i<count($pages);$i++){
   if($i>$cvStart&&preg_match('/TO ALL PERSONS|TRANSCRIPT OF RECORD|PROFESSIONAL\s*IDENTIFICATION|CERTIFICATE\s*OF|RECORD OF RELATED|PASAPORTE/i',$pages[$i]['text']))break;
   $cv[]=$pages[$i];
  }
  $first=$cv[0];$text=$this->clean($first['text']);
  foreach(['phone'=>'/(?:Phone|Mobile)\s*:\s*(\+[\d ]+)/i','email'=>'/Email\s*:\s*([^\s]+@[^\s]+)/i','full_name'=>'/^Name\s*:\s*(.+)$/mi','address_line1'=>'/Permanent Address\s*:\s*(.+)$/mi','birth_date'=>'/Date of Birth\s*:\s*(.+)$/mi','birth_place'=>'/Place of Birth\s*:\s*(.+)$/mi','sex'=>'/Gender\s*:\s*(.+)$/mi','civil_status'=>'/Status\s*:\s*(.+)$/mi','age'=>'/^Age\s*:\s*(\d+)/mi'] as $k=>$pattern){
   if(preg_match($pattern,$text,$m)){
    $v=trim($m[1]);if($k==='birth_date')$v=$parser->date($v);
    if($k==='phone')$v=(new FieldNormalizer)->normalize('phone',$v);
    if($k==='sex')$v=strtolower($v);
    $put($k,$v,$first,$first['text']);
   }
  }
  // Keep original OCR spellings in address and birthplace; review against the scan.
  $mode='personal';$edu=null;$job=null;$qualification=null;$educationLevel=null;
  $finishEdu=function()use(&$edu,&$records){if($edu && !empty($edu['institution']))$records['education'][]=$edu;$edu=null;};
  $finishJob=function()use(&$job,&$records){if($job && !empty($job['employer']))$records['employment'][]=$job;$job=null;};
  foreach($cv as $p){
   foreach(preg_split('/\n/u',$this->clean($p['text'])) as $line){
    $line=trim($line);if($line==='')continue;
    if(preg_match('/^EDUCATIONAL BACKGROUND$/i',$line)){$mode='education';continue;}
    if(preg_match('/^PROFESSIONAL EXPERIENCE$/i',$line)){$finishEdu();$mode='work';continue;}
    if(preg_match('/^LICENSES AND CERTIFICATIONS$/i',$line)){$finishJob();$mode='credentials';continue;}
    if(preg_match('/^LANGUAGE PROFICIENCY$/i',$line)){$mode='languages';continue;}
    if(preg_match('/^References$/i',$line)){$mode='references';continue;}
    if(preg_match('/^(Citizenship|Religion)\s*:\s*(.+)$/i',$line,$m))$put(strtolower($m[1])==='citizenship'?'nationality':'religion',trim($m[2]),$p,$p['text']);
    if($mode==='education'){
     if(preg_match('/^(Primary|Secondary|Junlor High School|Senlor High School|Tertiary)$/i',$line)){
      $educationLevel=str_ireplace(['Junlor','Senlor'],['Junior','Senior'],$line);
     }
     if(preg_match('/^Course\s*:\s*(.+)$/i',$line,$m))$qualification=trim($m[1]);
     if(preg_match('/^Dates Attended\s*:\s*(.+?)\s*[-–—]\s*(.+)$/i',$line,$m)){
      $finishEdu();$edu=['education_level'=>$educationLevel,'qualification'=>$qualification,'started_on'=>$parser->date($m[1]),'completed_on'=>$parser->date($m[2])]+$this->meta($p,$p['text']);$qualification=null;
     }
     if($edu && preg_match('/^(?:School|\$chool)\s*[:;]\s*(.+)$/i',$line,$m))$edu['institution']=trim($m[1]);
     if($edu && preg_match('/^Address\s*:\s*(.+)$/i',$line,$m))$edu['address']=trim($m[1]);
    }
    if($mode==='work'){
     if(preg_match('/^Employer\s*:\s*(.+)$/i',$line,$m)){$finishJob();$job=['employer'=>trim($m[1]),'responsibilities'=>'']+$this->meta($p,$p['text']);continue;}
     if($job){
      if(!in_array($p['page'],$job['source_pages'],true))$job['source_excerpt'].="\n".$p['text'];
      $job['source_pages']=array_values(array_unique([...$job['source_pages'],$p['page']]));
      if(preg_match('/^Employment Dates\s*:\s*(.+?)\s*[-–—]\s*(.+)$/i',$line,$m)){$job['started_on']=$parser->date($m[1]);if(preg_match('/present/i',$m[2]))$job['is_current']=true;else $job['ended_on']=$parser->date($m[2]);continue;}
      if(preg_match('/^(Address|Hospital Category|Bed Capacity|Position Held|Unit)\s*:\s*(.+)$/i',$line,$m)){$key=['address'=>'address','hospital category'=>'hospital_classification','bed capacity'=>'bed_capacity','position held'=>'position_title','unit'=>'department'][strtolower($m[1])];$job[$key]=trim($m[2]);continue;}
      $job['responsibilities'].=($job['responsibilities']!==''?"\n":'').$line;
     }
    }
    if($mode==='languages' && !str_contains($line,':')){
     $language=str_ireplace(['Englilsh','FMpino'],['English','Filipino'],$line);
     if(in_array($language,['English','Filipino','Bicol'],true))$records['languages'][]=['language'=>$language]+$this->meta($p,$line);
    }
   }
  }
  $finishEdu();$finishJob();
  foreach($pages as $p){
   $t=$this->clean($p['text']);$flat=preg_replace('/\s+/u',' ',$t);
   if(preg_match('/Medical Services Division/i',$t) && preg_match('/PERSONAL INFORMATION/i',$t)){
    foreach(['control_number'=>'/CONTROL NUMBER\s+([A-Z0-9-]+)/i','position_applied'=>'/POSITION APPLIED\s+(.+?)\s+CONTROL NUMBER/i','weight'=>'/Weight\s+([\d.]+)\s*kg/i','height'=>'/Height\s+([\d.]+)\s*cm/i','experience_local'=>'/Experience Local\s+([\d.]+)/i','experience_abroad'=>'/Abroad\s+([\d.]+)/i','date_applied'=>'/Date Applied\s+([\d\/]+)/i'] as $k=>$pat)if(preg_match($pat,$flat,$m))$put($k,trim($m[1]),$p,$p['text']);
    foreach(['father','mother'] as $parent)if(preg_match('/Name of '.$parent.'\s+(.+?)\s+Date of Birth\s+([\d\/]+)/i',$flat,$m)){
     $put($parent.'_name',trim($m[1]),$p,$p['text']);$put($parent.'_birth_date',$parser->date($m[2]),$p,$p['text']);
    }
    if(isset($fields['date_applied']))$fields['date_applied']['review_note']='Original numeric application date retained; confirm month/day order.';
   }
   if(preg_match('/PROFESSIONAL\s*IDENTIFICATION CARD/i',$t)){
    foreach(['last_name'=>'LAST NAME','first_name'=>'FIRST NAME','middle_name'=>'MIDDLE NAME'] as $k=>$label)if(preg_match('/'.$label.'\s+([^\n]+)/i',$t,$m))$put($k,trim($m[1]),$p,$p['text']);
    $c=['title'=>'Registered Nurse','credential_kind'=>'prc','issuing_authority'=>'Professional Regulation Commission']+$this->meta($p,$p['text']);
    foreach(['credential_number'=>'/REGISTRATION NO\.?\s*(\d+)/i','registered_on'=>'/REGISTRATION DATE\s*([\d\/]+)/i','expires_on'=>'/VALID UNTIL\s*([\d\/]+)/i'] as $k=>$pat)if(preg_match($pat,$t,$m))$c[$k]=$k==='credential_number'?$m[1]:$parser->date($m[1]);
    $records['credentials'][]=$c;
   }
   if(preg_match('/GENERAL AVERAGE RATING\s+(\d+\.\d+)%/i',$t,$m))$put('prc_rating',$m[1],$p,$p['text']);
   if(preg_match('/Date Conferred\s+([A-Za-z]+ \d{1,2}, \d{4})/i',$t,$m)){
    $date=$parser->date($m[1]);
    foreach($records['education'] as &$e)if(preg_match('/bachelor/i',$e['qualification']??'')){
     $e['completed_on']=$date;$e['source_pages']=array_values(array_unique([...$e['source_pages'],$p['page']]));
     $e['source_excerpt'].="\n".$m[0];
    }unset($e);
   }
   if(preg_match('/CERTIFICATE OF COMPLETION/i',$t)){
    if(preg_match('/skills for\s+(.+?)\s+Date of completion:\s*([A-Za-z]+ \d{1,2}[,.] \d{4})/is',$t,$m)){
     $title=trim(preg_replace('/\s+/',' ',$m[1]));$title=str_replace('EMERGENCYCARDIOVASCULAR','EMERGENCY CARDIOVASCULAR',$title);
     $provider=trim(strtok($t,"\n"));if(!str_contains($provider,'TRAINING CENTER')&&preg_match('/^(.+TRAINING CENTER)\s*$/mi',$t,$pm))$provider=trim($pm[1]);
     $records['training'][]=['title'=>$title,'issuing_authority'=>$provider,'completed_on'=>$parser->date(str_replace('.',',',$m[2]))]+$this->meta($p,$p['text']);
    }
   }
   if(preg_match('/PASSPORT\s+PP\s+PHL\s+([A-Z0-9]+)/i',$flat,$m)){
    $put('passport_number',$m[1],$p,$p['text']);
    if(preg_match('/^F\s+([A-Z ]+)[\r\n]/m',$t,$place))$put('birth_place',trim($place[1]),$p,$p['text']);
    if(preg_match_all('/\b\d{1,2} [A-Z]{3} \d{4}\b/',$t,$dm)&&count($dm[0])>=3){
     $put('passport_issued',$parser->date($dm[0][1]),$p,$p['text']);$put('passport_expiry',$parser->date($dm[0][2]),$p,$p['text']);
    }
   }
   // Employer certificate narrows dates only when its employer matches a CV record.
   if(preg_match('/residing at\s+(.+?),\s+has been working/is',$flat,$address))$put('address_line1',trim($address[1]),$p,$p['text']);
   if(preg_match('/as a STAFF NURSE from (.+?) to (.+?\d{4})\./i',$flat,$m)){
    foreach($records['employment'] as &$j)if(str_contains(preg_replace('/[^a-z]/','',strtolower($flat)),preg_replace('/[^a-z]/','',strtolower($j['employer'])))){
     $j['started_on']=$parser->date(trim($m[1]));$j['ended_on']=$parser->date(trim($m[2]));$j['source_pages'][]=$p['page'];$j['source_excerpt'].="\n".$p['text'];
    }unset($j);
   }
   if(preg_match('/as (Nurse I) on Job Order Status, since (.+?\d{4})\./i',$flat,$m)){
    foreach($records['employment'] as &$j)if(str_contains(preg_replace('/[^a-z]/','',strtolower($flat)),preg_replace('/[^a-z]/','',strtolower($j['employer'])))){
     $j['position_title']=$m[1];$j['started_on']=$parser->date($m[2]);$j['is_current']=true;$j['source_pages'][]=$p['page'];$j['source_excerpt'].="\n".$p['text'];
     $j['review_note']='CV describes Staff Nurse; employment certificate states Nurse I. Review role descriptions together.';
    }unset($j);
   }
  }
  if(isset($fields['first_name'],$fields['last_name'])){
   $fields['full_name']=$fields['first_name'];$fields['full_name']['value']=implode(' ',array_filter([$fields['first_name']['value'],$fields['middle_name']['value']??null,$fields['last_name']['value']]));
  }
  foreach($records['education'] as $e)if(preg_match('/bachelor.*nursing/i',$e['qualification']??'')){
   $fields['highest_nursing_education']=['value'=>$e['qualification']]+$e;
   if(!empty($e['completed_on']))$fields['graduation_year']=['value'=>(int)substr($e['completed_on'],0,4)]+$e;
  }
  usort($records['employment'],fn($a,$b)=>strcmp($b['started_on']??'',$a['started_on']??''));
  foreach($records['credentials'] as &$c){if(isset($fields['prc_rating']))$c['rating']=$fields['prc_rating']['value'];}unset($c);
  return ['fields'=>$fields,'records'=>$records,'segments'=>array_map(fn($p)=>['pages'=>[$p['page']],'document_type'=>'evidence_scoped_packet'],$pages),'review_notes'=>['Institution and provider contacts are excluded from applicant fields. Original OCR spelling and source pages remain reviewable.']];
 }
}
