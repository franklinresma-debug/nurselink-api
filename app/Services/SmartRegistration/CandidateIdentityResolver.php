<?php
namespace App\Services\SmartRegistration;

use Illuminate\Support\Facades\DB;

/** Suggestions only: a matching email must never authorize merging applicant records. */
class CandidateIdentityResolver
{
    public function resolve(array $fields, ?string $excludeUser = null): array
    {
        $ids=[];
        $add=function($id,string $basis) use (&$ids,$excludeUser) {
            if ((string)$id!==$excludeUser) $ids[(string)$id][$basis]=true;
        };
        $email=mb_strtolower(trim((string)($fields['email']??'')));
        if(filter_var($email,FILTER_VALIDATE_EMAIL)) foreach(DB::table('users')->whereRaw('LOWER(email) = ?',[$email])->pluck('id') as $id) $add($id,'email');
        $normalizer=new FieldNormalizer;
        $token=fn($v)=>preg_replace('/[^\pL\pN]/u','',mb_strtolower((string)$v));
        foreach(['phone','primary_license_number'] as $key) {
            $wanted=$normalizer->normalize($key,$fields[$key]??null);
            if($wanted===null) continue;
            foreach(DB::table('nurselink_smart_registration_profiles')->whereNotNull($key)->select('user_id',$key)->cursor() as $row)
                if($token($normalizer->normalize($key,$row->$key))===$token($wanted)) $add($row->user_id,$key);
        }
        if(!empty($fields['birth_date']) && !empty($fields['first_name']) && !empty($fields['last_name']))
            foreach(DB::table('nurselink_smart_registration_profiles')->where('birth_date',$fields['birth_date'])->select('user_id','first_name','last_name')->cursor() as $row)
                if($token($row->first_name)===$token($fields['first_name']) && $token($row->last_name)===$token($fields['last_name'])) $add($row->user_id,'name_birth_date');
        $passport=$token($fields['passport_number']??'');
        if($passport!=='') foreach(DB::table('nurselink_smart_registration_profiles')->whereNotNull('structured_data')->select('user_id','structured_data')->cursor() as $row)
            foreach(json_decode($row->structured_data,true)['personal_data']??[] as $item)
                if(preg_match('/^passport (?:number|no)$/i',$item['label']??'') && $token($item['value']??'')===$passport) $add($row->user_id,'passport');
        ksort($ids);
        return ['status'=>count($ids)>1?'identity_review':(count($ids)===1?'existing_match':'new_candidate'),
            'matches'=>array_map(fn($id,$basis)=>['user_id'=>$id,'basis'=>array_keys($basis)],array_keys($ids),array_values($ids))];
    }
}
