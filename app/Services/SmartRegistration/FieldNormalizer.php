<?php
namespace App\Services\SmartRegistration;

/** Syntax validation only: no inferred citizenship, ID digits, or date convention. */
class FieldNormalizer
{
    public function normalize(string $field, mixed $value): mixed
    {
        if (!is_scalar($value)) return null;
        $s=trim(preg_replace('/[\s\p{Z}]+/u',' ',(string)$value) ?? '');
        if ($s==='' || preg_match('/^(?:n\/?a|none|unknown|not available|[-?.]+)$/iu',$s)) return null;
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFD}]/u',$s)) return null;
        if (in_array($field,['email'],true)) {
            $s=preg_replace('/\s*([@.])\s*/u','$1',$s);
            return filter_var($s,FILTER_VALIDATE_EMAIL) ? mb_strtolower($s) : null;
        }
        if (in_array($field,['phone','emergency_contact_phone'],true)) {
            if (!preg_match('/^(?:\+|00)?[0-9 ()\-.]+$/D',$s)) return null;
            $s=preg_replace('/[ ()\-.]/','',$s);
            if (str_starts_with($s,'00')) $s='+'.substr($s,2);
            if (preg_match('/^09\d{9}$/D',$s)) return '+63'.substr($s,1);
            if (preg_match('/^639\d{9}$/D',$s)) return '+'.$s;
            if (preg_match('/^\+639\d{9}$/D',$s)) return $s;
            if (str_starts_with($s,'+639')) return null;
            if (preg_match('/^0[2-8]\d{8,9}$/D',$s)) return '+63'.substr($s,1);
            return preg_match('/^\+[1-9]\d{7,14}$/D',$s) ? $s : null;
        }
        if ($field==='sex') return match(mb_strtolower($s)) {
            'm','male'=>'male','f','female'=>'female','non-binary','non binary','non_binary'=>'non_binary',
            'other'=>'other','prefer not to say','prefer_not_to_say'=>'prefer_not_to_say',default=>null};
        if (in_array($field,['first_name','middle_name','last_name','full_name','name'],true)) {
            $s=preg_replace('/,?\s+(?:R\.?N\.?|BSN|MSN)$/iu','',$s);
            return mb_strlen($s)<=120 && preg_match('/^[\pL][\pL\pM .\x{27}\x{2019},-]*$/u',$s) ? $s : null;
        }
        if ($field==='years_experience') {
            return preg_match('/^(\d{1,2})(?:\s+years?)?$/i',$s,$m) && (int)$m[1]<=80 ? (int)$m[1] : null;
        }
        if ($field==='graduation_year') return preg_match('/^\d{4}$/',$s) && (int)$s>=1940 && (int)$s<=(int)date('Y')+1 ? (int)$s : null;
        if (in_array($field,['primary_license_number','credential_number'],true)) {
            // Preserve leading zeros and separators; never substitute O/0 or I/1.
            return mb_strlen($s)<=160 && preg_match('/^(?=.*\d)[\pL\d][\pL\d .\/-]*$/u',$s) ? $s : null;
        }
        if ($field==='highest_nursing_education') return match(mb_strtolower($s)) {
            'bsn','bsc nursing','b.s.n.'=>'Bachelor of Science in Nursing','msn'=>'Master of Science in Nursing',
            'dnp'=>'Doctor of Nursing Practice',default=>$s};
        return mb_strlen($s)<=6000 && preg_match('/[\pL\pN]/u',$s) ? $s : null;
    }
}
