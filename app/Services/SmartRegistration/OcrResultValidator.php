<?php
namespace App\Services\SmartRegistration;

/** Third pass checks extracted evidence and formats without inventing values. */
class OcrResultValidator
{
    public function validate(array &$fields, array &$records, array $pages): array
    {
        $validPages=array_map('intval',array_column($pages,'page'));
        $checked=0; $flagged=0;
        $check=function(array &$item, ?string $field=null) use (&$checked,&$flagged,$validPages): void {
            $checked++; $issues=[];
            $sources=$item['source_pages']??(isset($item['source_page'])?[$item['source_page']]:[]);
            if (!$sources || array_diff(array_map('intval',$sources),$validPages)) $issues[]='Source page evidence needs review';
            if (empty($item['source_excerpt'])) $issues[]='Source excerpt is missing';
            if (!empty($item['conflict'])) $issues[]='Conflicting values require confirmation';
            $value=$item['value']??null;
            if ($field==='email' && !filter_var($value,FILTER_VALIDATE_EMAIL)) $issues[]='Email format needs correction';
            if ($field==='phone' && !preg_match('/^\+?[0-9]{7,15}$/',preg_replace('/[ ()-]/','',(string)$value))) $issues[]='Phone format needs review';
            if ($field==='birth_date' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',(string)$value,$m) || !checkdate((int)($m[2]??0),(int)($m[3]??0),(int)($m[1]??0)) || $value>=date('Y-m-d'))) $issues[]='Birth date needs confirmation';
            $start=$item['started_on']??null; $end=$item['ended_on']??$item['completed_on']??null;
            if ($start && $end && preg_match('/^\d{4}(?:-\d{2}){0,2}$/',$start) && preg_match('/^\d{4}(?:-\d{2}){0,2}$/',$end) && substr($start,0,min(strlen($start),strlen($end)))>substr($end,0,min(strlen($start),strlen($end)))) $issues[]='End date precedes start date';
            $item['validation_issues']=$issues;
            $item['validation_status']=$issues?'needs_review':'checks_passed';
            if ($issues) {$flagged++;$item['review_required']=true;}
        };
        foreach($fields as $key=>&$field) if(is_array($field))$check($field,$key);
        unset($field);
        foreach($records as &$group)foreach($group as &$record)if(is_array($record))$check($record);
        unset($group,$record);
        return ['version'=>1,'checked_items'=>$checked,'flagged_items'=>$flagged,'note'=>'Format and provenance checks only; applicant review remains required.'];
    }
}
