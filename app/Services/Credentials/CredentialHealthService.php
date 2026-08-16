<?php
namespace App\Services\Credentials;
use App\Models\Member;
class CredentialHealthService
{
    public function for(Member $member):array
    {
        $rows=$member->credentials()->get();
        $counts=['total'=>$rows->count(),'active'=>0,'renewal_due'=>0,'expiring_soon'=>0,'expiring_critical'=>0,'expired'=>0,'unverified'=>0,'verified'=>0];
        foreach($rows as $row){ if(array_key_exists($row->credential_status,$counts))$counts[$row->credential_status]++; if($row->verification_status==='verified')$counts['verified']++; else $counts['unverified']++; }
        $risk=$counts['expired']*30+$counts['expiring_critical']*15+$counts['expiring_soon']*8+$counts['renewal_due']*4+$counts['unverified']*2;
        $score=max(0,min(100,100-$risk));
        return ['score'=>$score,'counts'=>$counts,'attention_required'=>$counts['expired']+$counts['expiring_critical']+$counts['expiring_soon']+$counts['renewal_due']+$counts['unverified']];
    }
}
