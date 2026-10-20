<?php
namespace App\Services\SmartRegistration;

/** Logical document boundaries retain the original file and page numbers. */
class OcrSandboxDocumentPacketParser
{
 public function parse(array $pages,string $defaultType):array {
  $scoped=app(OcrEvidencePacketParser::class)->parse($pages);
  if($scoped!==null)return $scoped;
  $segments=[];$type=$defaultType;
  foreach($pages as $page) {
   $heading=implode("\n",array_slice(preg_split('/\n/u',$page['text'])?:[],0,12));
   $detected=null;
   foreach([
    'cv'=>'(?:curriculum vitae|^resume$|^résumé$)',
    'identity'=>'(?:^passport$|^republic of.*passport)',
    'prc_license'=>'(?:professional regulation commission|professional identification card)',
    'international_license'=>'(?:saudi commission|scfhs|nursing council)',
    'transcript'=>'(?:transcript of records|academic transcript)',
    'employment_certificate'=>'(?:certificate of employment|employment certificate)',
    'training_certificate'=>'(?:certificate of training|certificate of completion)',
    'nursing_diploma'=>'(?:^diploma$|degree of bachelor)',
   ] as $candidate=>$pattern) if(preg_match('/'.$pattern.'/imu',$heading)) {$detected=$candidate;break;}
   if(preg_match('/WORK HISTORY\s*&\s*EXPERIENCE/iu',$page['text']) && preg_match('/EDUCATION/iu',$page['text']))$detected='cv';
   if($detected!==null)$type=$detected;
   $last=count($segments)-1;
   if($last<0 || $segments[$last]['type']!==$type)$segments[]=['type'=>$type,'pages'=>[]];
   $segments[count($segments)-1]['pages'][]=$page;
  }
  $fields=[];$records=[];$meta=[];$parser=app(OcrSandboxApplicantDocumentParser::class);
  foreach($segments as $segment) {
   $parsed=$parser->parse($segment['pages'],$segment['type']);
   $meta[]=['document_type'=>$segment['type'],'pages'=>array_column($segment['pages'],'page')];
   foreach($parsed['fields'] as $key=>$value) {
    if(!isset($fields[$key])){$fields[$key]=$value;continue;}
    foreach([$value,...($value['alternatives']??[])] as $variant)
     if(mb_strtolower(trim((string)$fields[$key]['value']))!==mb_strtolower(trim((string)$variant['value']))) {
      $fields[$key]['alternatives']??=[$fields[$key]];$fields[$key]['alternatives'][]=$variant;
      $fields[$key]['conflict']=true;$fields[$key]['review_required']=true;
     }
   }
   foreach($parsed['records'] as $group=>$rows) $records[$group]=array_merge($records[$group]??[],$rows);
  }
  foreach($records as $group=>$rows){$seen=[];$records[$group]=array_values(array_filter($rows,function($r)use(&$seen,$parser){$key=$parser->identity($r);if(isset($seen[$key]))return false;$seen[$key]=true;return true;}));}
  $parsed=app(OcrSectionedCvParser::class)->refine($pages,['fields'=>$fields,'records'=>$records,'segments'=>$meta]);
  return app(OcrInterleavedCvParser::class)->refine($pages,$parsed);
 }
}
