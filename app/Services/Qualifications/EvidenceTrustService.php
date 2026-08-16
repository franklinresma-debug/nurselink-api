<?php
namespace App\Services\Qualifications;
class EvidenceTrustService
{
    public function score(?string $state): int
    {
        $state=strtolower((string)$state);
        return match($state){
            'verified'=>5,'assessed'=>4,'document_supported'=>3,'member_confirmed','confirmed'=>2,
            'self_declared','active','renewal_due','expiring_soon','expiring_critical','unverified'=>1,
            'discrepancy','unable_to_verify','revoked','expired','lapsed'=>0,default=>0,
        };
    }
}
