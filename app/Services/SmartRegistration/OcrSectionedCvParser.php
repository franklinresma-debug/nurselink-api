<?php
namespace App\Services\SmartRegistration;

/** Explicit CV sections with inclusive dates. Does not assign shared duties to a specific employer. */
class OcrSectionedCvParser
{
 private function date(string $s):?string {
  $s=trim(preg_replace('/\b(Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[.,]\s*/i','$1 ',$s));
  $s=preg_replace('/(?<=\d),\s*(?=\d{4})/',', ',$s);
  return app(OcrSandboxApplicantDocumentParser::class)->date($s);
 }
 private function meta(array $rows):array {
  $pages=array_values(array_unique(array_column($rows,'page')));
  return ['source_page'=>$pages[0],'source_pages'=>$pages,'source_excerpt'=>implode("\n",array_column($rows,'text')),'confidence'=>.72,'review_required'=>true,'review_status'=>'review_required'];
 }
 public function refine(array $pages,array $parsed):array {
  $all=implode("\n",array_column($pages,'text'));
  if(!preg_match('/^Experiences:\s*$/mi',$all)||!preg_match('/^Educational Attainment:\s*$/mi',$all)||!preg_match('/Inclusive Dates:/i',$all))return $parsed;
  $jobs=[];$schools=[];$training=[];$credentials=[];$references=[];$skills=[];
  $section='header';$record=[];$rows=[];$kind='';$personal=[];$shared=[];
  $flush=function()use(&$record,&$rows,&$kind,&$jobs,&$schools,&$training,&$credentials,&$references,&$skills) {
   if(!$record){$rows=[];return;}
   $r=$record+$this->meta($rows);
   switch($kind){case 'work':$jobs[]=$r;break;case 'education':$schools[]=$r;break;case 'training':$training[]=$r;break;case 'credentials':$credentials[]=$r;break;case 'references':$references[]=$r;break;case 'skills':$skills[]=$r;}
   $record=[];$rows=[];
  };
  $heads=['experiences'=>'work','expertise'=>'skills','job description'=>'shared','examinations taken'=>'credentials','educational attainment'=>'education','courses and workshops'=>'training','trainings and seminars'=>'training','personal background'=>'personal','character references'=>'references'];
  foreach($pages as $page)foreach(preg_split('/\n/u',$page['text']) as $raw){
   $line=trim($raw);if($line===''||$line==="\f")continue;$row=['text'=>$line,'page'=>$page['page']];
   $heading=strtolower(rtrim($line,': '));
   if(isset($heads[$heading])){$flush();$section=$heads[$heading];$kind=$section;continue;}
   if(preg_match('/^I hereby certify/i',$line)){$flush();$section='declaration';continue;}
   if($section==='declaration'){$personal['declaration'][]=$row;continue;}
   if($section==='header')continue;
   if($section==='shared'){$shared[]=$row;continue;}
   if($section==='personal'){$personal['rows'][]=$row;continue;}
   $bullet=preg_match('/^[•]\s*/u',$line);$value=preg_replace('/^[•]\s*/u','',$line);
   if($section==='work'){
    if($bullet){$flush();$record=['employer'=>$value];$rows=[$row];continue;}
    if(preg_match('/^Position:\s*(.+)$/i',$line,$m))$record['position_title']=$m[1];
    elseif(preg_match('/^Inclusive Dates:\s*(.+?)\s+to\s+(.+)$/i',$line,$m)){
     $record['started_on']=$this->date($m[1]);$record['ended_on']=$this->date($m[2]);
     if(preg_match('/present/i',$m[2])){$record['is_current']=true;unset($record['ended_on']);}
    }elseif(preg_match('/^Areas:\s*(.+)$/i',$line,$m))$record['department']=$m[1];
    elseif(preg_match('/^Location:\s*(.+)$/i',$line,$m))$record['address']=$m[1];
    elseif($record && empty($record['position_title']))$record['employer'].=' '.$line;
    elseif(isset($record['department'])&&!isset($record['address']))$record['department'].=' '.$line;
    $rows[]=$row;continue;
   }
   if($section==='education'){
    if(preg_match('/^(Primary|Secondary|Tertiary):\s*(.+?)\s+(\d{4})-(\d{4})$/i',$line,$m)){
     $flush();$record=['education_level'=>$m[1],'institution'=>trim($m[2]),'started_on'=>$m[3],'completed_on'=>$m[4]];$rows=[$row];continue;
    }
    if($record){if(preg_match('/bachelor|master|diploma/i',$line))$record['qualification']=$line;else $record['address']=trim(($record['address']??'').' '.$line);$rows[]=$row;}continue;
   }
   if($section==='training'){
    if($bullet){$flush();$record=['title'=>$value];$rows=[$row];
     if(preg_match('/^(.+?)\s+((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)[. ]*\d{1,2},?\s*\d{4})$/i',$value,$m)){
      $record['title']=trim($m[1]);$record['completed_on']=$this->date($m[2]);
     }continue;
    }
    if(preg_match('/^Venue:\s*(.+)$/i',$line,$m))$record['venue']=$m[1];
    elseif(preg_match('/^Inclusive Date:\s*(.+)$/i',$line,$m))$record['completed_on']=$this->date($m[1]);
    elseif($record)$record['title'].=' '.$line;
    $rows[]=$row;continue;
   }
   if($section==='credentials'){
    if(preg_match('/Examination/i',$line)){$flush();$record=['title'=>$line,'credential_kind'=>preg_match('/Saudi/i',$line)?'saudi':'prc'];$rows=[$row];continue;}
    if(preg_match('/License no\.?:\s*(.+)$/i',$line,$m))$record['credential_number']=trim($m[1]);
    elseif(strcasecmp($line,'Passed')===0)$record['exam_result']='Passed';
    elseif($this->date($line)!==null)$record['exam_date']=$this->date($line);
    $rows[]=$row;continue;
   }
   if($section==='skills'){
    if($bullet){$flush();$record=['name'=>$value];$rows=[$row];}elseif($record){$record['name'].=' '.$line;$rows[]=$row;}continue;
   }
   if($section==='references'){
    if(!$record){$record=['name'=>$line];$rows=[$row];continue;}
    if(preg_match('/^Contact (?:Email|No):\s*(.+)$/i',$line,$m)){$record[str_contains(strtolower($line),'email')?'email':'phone']=$m[1];$rows[]=$row;$flush();continue;}
    if(!isset($record['position_title']))$record['position_title']=$line;else $record['organization']=trim(($record['organization']??'').' '.$line);
    $rows[]=$row;continue;
   }
  }
  $flush();
  // Populate a city only when the school address explicitly names it.
  foreach($schools as &$school){
   foreach(explode(',', $school['address']??'') as $part){
    $part=trim($part);
    if(preg_match('/^[\p{L} .\x{2019}\x{0027}-]+ City$/u',$part))$school['city']=$part;
   }
  }
  unset($school);
  $f=&$parsed['fields'];
  foreach(['current_position','current_employer','employment_countries','employment_positions','healthcare_facility_names','graduation_year','highest_nursing_education','primary_license_number'] as $k)unset($f[$k]);
  foreach($schools as $e)if(!empty($e['qualification'])&&preg_match('/nursing/i',$e['qualification'])){
   $f['highest_nursing_education']=['value'=>$e['qualification']]+$e;$f['graduation_year']=['value'=>(int)$e['completed_on']]+$e;
  }
  $prows=$personal['rows']??[];
  foreach($prows as $i=>$row){
   if(!preg_match('/^([^:]+):\s*(.+)$/',$row['text'],$m))continue;
   $label=strtolower(trim($m[1]));$value=trim($m[2]);$evidence=[$row];
   if($label==='permanent address' && isset($prows[$i+1])&&!str_contains($prows[$i+1]['text'],':')){$value.=' '.$prows[$i+1]['text'];$evidence[]=$prows[$i+1];}
   $key=['permanent address'=>'permanent_address','birth date'=>'birth_date','age'=>'age','birth place'=>'birth_place','height'=>'height','weight'=>'weight','civil status'=>'civil_status','citizenship'=>'nationality'][$label]??null;
   if(!$key)continue;
   if($key==='birth_date')$value=$this->date($value);
   if($key==='age')$value=preg_replace('/\s+years old/i','',$value);
   if($key==='weight')$value=trim(preg_replace('/\s*kg/i','',$value));
   $note=null;
   if($key==='height'&&preg_match('/^(\d+)[\'’](\d+)[”"′]?$/u',$value,$hm)){$note='Converted from source height '.$value.' to centimetres; verify.';$value=(string)round(((int)$hm[1]*12+(int)$hm[2])*2.54,2);}
   $f[$key]=['value'=>$value]+$this->meta($evidence);if($note)$f[$key]['review_note']=$note;
  }
  if(isset($f['address_line1'],$f['permanent_address'])){
   $f['address_line1']['alternatives']=[$f['permanent_address']];
   $f['address_line1']['review_note']='CV header contact address. The separately labeled permanent address is retained in its own field; confirm both.';
   $f['address_line1']['conflict']=true;
  }
  $parsed['records']['employment']=$jobs;$parsed['records']['education']=$schools;$parsed['records']['training']=$training;$parsed['records']['credentials']=$credentials;$parsed['records']['references']=$references;$parsed['records']['skills']=$skills;
  // General duties follow the whole work history; do not attach them to the last employer.
  $parsed['records']['summary']=$shared?[['text'=>implode("\n",array_column($shared,'text')),'review_note'=>'General duties; employer not specified.']+$this->meta($shared)]:[];
  $parsed['records']['personal_data']=[];
  if(!empty($personal['declaration']))$parsed['records']['personal_data'][]=['label'=>'Declaration / signature name — compare with header','value'=>implode("\n",array_column($personal['declaration'],'text'))]+$this->meta($personal['declaration']);
  if(preg_match('/Desired Position:\s*([^\r\n]+)/i',$all,$m))$f['position_applied']=['value'=>$m[1]]+$this->meta([['page'=>$pages[0]['page'],'text'=>$m[0]]]);
  $parsed['records']['languages']=[];
  if(preg_match('/Language spoken:\s*([^\r\n]+)/i',$all,$m))foreach(explode(',',$m[1])as $language)$parsed['records']['languages'][]=['language'=>trim($language)]+$this->meta([['page'=>end($pages)['page'],'text'=>$m[0]]]);
  return $parsed;
 }
}

