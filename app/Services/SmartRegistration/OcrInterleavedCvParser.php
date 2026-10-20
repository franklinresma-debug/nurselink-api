<?php
namespace App\Services\SmartRegistration;

/** Scoped recovery of explicit education/work/license blocks in interleaved CV columns. */
class OcrInterleavedCvParser
{
 public function refine(array $pages,array $parsed):array {
  $all=implode("\n",array_column($pages,'text'));
  if(!preg_match('/WORK HISTORY\s*&\s*EXPERIENCE/iu',$all)||!preg_match('/PERSONAL INFORMATION/iu',$all)||!preg_match('/EDUCATION/iu',$all))return $parsed;
  $cells=[];
  foreach($pages as $page)foreach(preg_split('/\n/u',$page['text']) as $line)foreach(preg_split('/\t+| {3,}/u',trim($line))as $cell)if(trim($cell)!=='')$cells[]=['text'=>trim($cell),'page'=>$page['page']];
  $meta=function(array $rows):array{$ps=array_values(array_unique(array_column($rows,'page')));return ['source_page'=>$ps[0],'source_pages'=>$ps,'source_excerpt'=>implode("\n",array_column($rows,'text')),'confidence'=>.6,'review_required'=>true,'source_method'=>'ocr'];};
  $date=fn($v)=>app(OcrSandboxApplicantDocumentParser::class)->date(trim($v));
  $schools=[];$jobs=[];$licenses=[];
  foreach($cells as $i=>$cell){$t=$cell['text'];
   if(preg_match('/^(Bachelor[^\d]+|Associate[^\d]+|Secondary Education|Primary Education)\s+(19\d{2}|20\d{2})$/iu',$t,$m)){
    for($j=$i+1;$j<min(count($cells),$i+5);$j++)if(preg_match('/college|university|school/iu',$cells[$j]['text'])){
     $school=$cells[$j]['text'];$parts=array_map('trim',explode(',',$school));
     $r=['qualification'=>trim($m[1]),'completed_on'=>$m[2],'institution'=>$parts[0],'education_level'=>str_starts_with($m[1],'Primary')?'Primary':(str_starts_with($m[1],'Secondary')?'Secondary':'Tertiary')]+$meta([$cell,$cells[$j]]);
     if(count($parts)>1)$r['address']=implode(', ',array_slice($parts,1));
     if(preg_match('/Philippines$/iu',$school))$r['country']='Philippines';
     if(preg_match('/(?:,| of )\s*([^,]+City)(?:,|$)/iu',$school,$loc))$r['city']=trim($loc[1]);
     $schools[]=$r;break;
    }
   }
   if(preg_match('/^(General Nurse|Operating Room Nurse|General Ward Nurse|Out Patient Receptionist)\s*(.*)$/iu',$t,$m)){
    $dates=trim($m[2]);$evidence=[$cell];
    if($dates===''&&isset($cells[$i+1])){$dates=$cells[$i+1]['text'];$evidence[]=$cells[$i+1];}
    if(!preg_match('/^(.+?)\s*[-–]\s*(.+)$/u',$dates,$dm))continue;
    $start=$date($dm[1]);$end=$date($dm[2]);if(!$start||!$end)continue;
    for($j=$i+1;$j<min(count($cells),$i+6);$j++)if(preg_match('/medical cent(?:er|re)|hospital|clinic/iu',$cells[$j]['text'])&&!preg_match('/^\(former/iu',$cells[$j]['text'])){
     $r=['position_title'=>$m[1],'started_on'=>$start,'ended_on'=>$end,'employer'=>$cells[$j]['text']]+$meta([...$evidence,$cells[$j]]);
     $r['_start_cell']=$i;$r['_employer_cell']=$j;$jobs[]=$r;break;
    }
   }
   if(preg_match('/^(License No\.?|SCFHS Profile No\.?)\s*:\s*([A-Z0-9]+)$/iu',$t,$m)){
    $saudi=str_starts_with(strtoupper($m[1]),'SCFHS');
    $r=['title'=>$saudi?'Nurse Specialist':'Registered Nurse','credential_kind'=>$saudi?'saudi':'prc','issuing_authority'=>$saudi?'Saudi Commission for Health Specialties':'Professional Regulation Commission'];
    if($saudi){$r['profile_number']=$m[2];$r['notes']='SCFHS profile number as stated; not assumed to be a license number.';}else $r['credential_number']=$m[2];
    $ev=[$cell];
    for($j=$i+1;$j<min(count($cells),$i+4);$j++)if(preg_match('/^(Registration date|Valid until):\s*(.+)$/iu',$cells[$j]['text'],$dm)){$r[strtolower($dm[1])==='valid until'?'expires_on':'issued_on']=$date($dm[2]);$ev[]=$cells[$j];break;}
    $licenses[]=$r+$meta($ev);
   }
  }
  // Keep each job's right-column duties within its own position/date boundary.
  foreach($jobs as $index=>&$job) {
   $from=$job['_employer_cell']+1;$until=$jobs[$index+1]['_start_cell']??count($cells);
   $body=[];$evidence=[];
   for($k=$from;$k<$until;$k++) {
    $t=$cells[$k]['text'];
    if(preg_match('/^SKILLS$/iu',$t))break;
    if(preg_match('/^\(former.+\)$/iu',$t)){$job['notes']=$t;$evidence[]=$cells[$k];continue;}
    if(preg_match('/^(?:PERSONAL INFORMATION|EDUCATION|LICENSE|REGISTERED NURSE|Secondary Education.*|Primary Education.*|Bachelor.*|Associate.*)$/iu',$t)
      || preg_match('/^(?:Date of Birth|Address|Phone No\.?|Email|Nationality|Civil Status|License No\.?|Registration date)\s*:/iu',$t)
      || preg_match('/@|college|university|school|Professional Regulation Commission/iu',$t))continue;
    $body[]=preg_replace('/^A\s+(?=[A-Z])/u','',$t);$evidence[]=$cells[$k];
   }
   $duties=trim(implode(' ',$body));
   if($duties!=='')$job['responsibilities']=$duties;
   if(preg_match('/Rotated in different departments:\s*(.+?)(?=\s+Assist|\s+Providing|$)/iu',$duties,$dm))$job['department']=trim($dm[1]);
   elseif(preg_match('/^(Operating Room|General Ward) Nurse$/iu',$job['position_title'],$dm))$job['department']=$dm[1];
   if(preg_match('/^(.*?)\s+([\pL]+),\s*K\.?S\.?A\.?$/iu',$job['employer'],$loc)){
    $job['employer']=trim($loc[1]);$job['city']=$loc[2];$job['country']='Saudi Arabia';
   }elseif(preg_match('/^(.*?)\s*-\s*Philippines$/iu',$job['employer'],$loc)){
    $job['employer']=trim($loc[1]);$job['country']='Philippines';
   }
   if($evidence)$job['source_excerpt'].="\n".implode("\n",array_column($evidence,'text'));
   unset($job['_start_cell'],$job['_employer_cell']);
  }
  unset($job);
  foreach($licenses as &$license) {
   $license['country']=$license['credential_kind']==='saudi'?'Saudi Arabia':'Philippines';
   $license['category']=$license['title'];
   if(isset($license['profile_number']))$license['notes']='SCFHS profile number: '.$license['profile_number'].'. Confirm against the SCFHS record; not assumed to be a separate license number.';
  }
  unset($license);
  // Line breaks in OCR are layout breaks, not necessarily new skills.
  $skills=[];$inSkills=false;
  foreach($cells as $cell){
   $t=$cell['text'];
   if(preg_match('/^SKILLS$/iu',$t)){$inSkills=true;continue;}
   if(!$inSkills)continue;
   if(preg_match('/^(?:SCFHS|Saudi commission|Valid until|Registration date|License No)/iu',$t))continue;
   if(preg_match('/^(?:REFERENCES|EDUCATION|WORK HISTORY|PERSONAL INFORMATION)$/iu',$t))break;
   $last=count($skills)-1;
   if($last>=0 && preg_match('/^(?:[\p{Ll}]|[&,])/u',$t)){
    $skills[$last]['name'].=' '.$t;
    $skills[$last]['source_excerpt'].="\n".$t;
    $skills[$last]['source_pages']=array_values(array_unique([...$skills[$last]['source_pages'],$cell['page']]));
   }else $skills[]=['name'=>$t]+$meta([$cell]);
  }
  if($skills)$parsed['records']['skills']=$skills;
  if($schools)$parsed['records']['education']=$schools;
  if($jobs)$parsed['records']['employment']=$jobs;
  if($licenses)$parsed['records']['credentials']=$licenses;
  foreach($schools as $school)if(preg_match('/nursing/iu',$school['qualification']))$parsed['fields']['highest_nursing_education']=['value'=>$school['qualification']]+$school;
  // Split a numbered neighbourhood and trailing locality, retaining the full address for review.
  $address=$parsed['fields']['address_line1']??null;
  if($address && preg_match('/^(.+?\d+)\s+([\pL]+(?: City)?),\s*([^,]+),\s*Philippines$/iu',$address['value'],$am)){
   $parsed['fields']['city']=['value'=>$am[2]]+array_diff_key($address,['value'=>true]);
   $parsed['fields']['city']['confidence']=.55;$parsed['fields']['city']['review_required']=true;
  }
  return $parsed;
 }
}
