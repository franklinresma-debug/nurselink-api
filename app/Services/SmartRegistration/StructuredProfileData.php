<?php
namespace App\Services\SmartRegistration;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StructuredProfileData
{
    public const FIELDS = [
        'personal_data'=>'label value',
        'employment'=>'employer position_title country city department started_on ended_on is_current responsibilities reason_for_leaving bed_capacity date_text notes',
        'education'=>'qualification institution field_of_study country started_on completed_on date_text notes',
        'credentials'=>'title credential_number issuing_authority country issued_on expires_on category credential_type profile_number notes',
        'languages'=>'language proficiency speaking reading writing exam score notes',
        'references'=>'name position_title organization relationship phone email notes',
        'skills'=>'name domain proficiency notes',
    ];
    public function suggestions(array $documents): array
    {
        $groups=array_fill_keys(array_keys(self::FIELDS),[]); $seen=[]; $parser=app(ApplicantDocumentParser::class);
        foreach ($documents as $doc) foreach ($doc['structured_records'] ?? [] as $group=>$rows) {
            if (!isset($groups[$group])) continue;
            foreach ($rows as $row) {
                $key=$parser->identity($row); if (isset($seen[$group][$key])) continue; $seen[$group][$key]=true;
                $groups[$group][]=$row+['source_document_id'=>$doc['id'],'source_name'=>$doc['name'],'source_record_id'=>$key];
            }
        }
        return $groups;
    }
    public function merge(array $saved,array $input,string $section,mixed $userId): array
    {
        $allowed=$section==='personal' ? ['personal_data'] : array_values(array_diff(array_keys(self::FIELDS),['personal_data']));
        $total=0; $parser=app(ApplicantDocumentParser::class); $documents=[];
        foreach ($input as $group=>$rows) {
            if (!in_array($group,$allowed,true) || !is_array($rows) || !array_is_list($rows) || count($rows)>100)
                throw ValidationException::withMessages(['structured_data'=>'Invalid document record group or too many records.']);
            $total+=count($rows);
            if ($total>200) throw ValidationException::withMessages(['structured_data'=>'Review at most 200 records per save.']);
            $clean=[]; $seen=[]; $keys=array_fill_keys(explode(' ',self::FIELDS[$group]),true);
            foreach ($rows as $row) {
                if (!is_array($row)) throw ValidationException::withMessages(['structured_data'=>'Each record must contain named fields.']);
                $values=array_intersect_key($row,$keys);
                foreach ($values as $key=>$value) {
                    if ($key==='is_current') {
                        if (!is_bool($value)) throw ValidationException::withMessages(['structured_data'=>'Current employment must be true or false.']);
                    } elseif (!is_scalar($value) || mb_strlen((string)$value)>6000)
                        throw ValidationException::withMessages(['structured_data'=>'A document field is invalid or exceeds 6,000 characters.']);
                    else $values[$key]=trim((string)$value);
                }
                $values=array_filter($values,fn($v)=>$v!=='' && $v!==null);
                if (!$values) continue;
                if (!empty($values['email']) && !filter_var($values['email'],FILTER_VALIDATE_EMAIL))
                    throw ValidationException::withMessages(['structured_data'=>'Correct the email address in the reference record.']);
                $meta=['source_kind'=>'applicant'];
                $documentId=(int)($row['source_document_id'] ?? 0);
                if ($documentId>0) {
                    if (!array_key_exists($documentId,$documents)) $documents[$documentId]=DB::table('nurselink_smart_registration_documents')
                        ->where('id',$documentId)->where('user_id',$userId)->first();
                    $doc=$documents[$documentId]; $original=null;
                    $extracted=$doc ? json_decode((string)$doc->extracted_fields,true) : [];
                    foreach ($extracted['_structured'][$group] ?? [] as $candidate)
                        if ($parser->identity($candidate)===($row['source_record_id'] ?? '')) { $original=$candidate; break; }
                    if (!$original) foreach ($saved[$group] ?? [] as $previous)
                        if (($previous['source_document_id'] ?? null)===$documentId && ($previous['source_record_id'] ?? '')===($row['source_record_id'] ?? '')) { $original=$previous; break; }
                    if (!$original) throw ValidationException::withMessages(['structured_data'=>'This record source is unavailable. Refresh the page before saving.']);
                    $meta=array_intersect_key($original,array_fill_keys(['source_page','source_pages','source_excerpt','confidence','field_provenance','normalization_version'],true));
                    $meta+=['source_document_id'=>$documentId,'source_name'=>$doc?->original_name ?? ($original['source_name'] ?? ''),
                        'source_record_id'=>$row['source_record_id'],'source_kind'=>'document'];
                    if ($parser->identity($values)!==$parser->identity(array_intersect_key($original,$keys))) $meta['source_kind']='applicant_edited';
                }
                $key=$parser->identity($values); if (isset($seen[$key])) continue; $seen[$key]=true;
                $clean[]=$values+$meta+['review_status'=>'applicant_confirmed','confirmed_at'=>now()->toIso8601String()];
            }
            $saved[$group]=$clean;
        }
        return $saved;
    }
}
