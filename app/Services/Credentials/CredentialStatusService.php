<?php
namespace App\Services\Credentials;
use App\Models\CredentialStatusEvent; use App\Models\ProfessionalCredential; use Carbon\CarbonImmutable;
class CredentialStatusService
{
    public function evaluate(ProfessionalCredential $credential, ?CarbonImmutable $today=null):string
    {
        $today=$today ?: CarbonImmutable::today();
        if (in_array($credential->credential_status,['revoked','lapsed'],true)) return $credential->credential_status;
        if ($credential->does_not_expire || !$credential->expires_on) return 'active';
        $expiry=CarbonImmutable::parse($credential->expires_on)->startOfDay();
        $days=$today->diffInDays($expiry,false);
        return match(true){
            $days < 0 => 'expired',
            $days <= 30 => 'expiring_critical',
            $days <= 60 => 'expiring_soon',
            $days <= 90 => 'renewal_due',
            default => 'active',
        };
    }
    public function refresh(ProfessionalCredential $credential, ?CarbonImmutable $today=null):ProfessionalCredential
    {
        $from=$credential->credential_status; $to=$this->evaluate($credential,$today);
        $credential->forceFill(['credential_status'=>$to,'last_status_evaluated_at'=>now()])->save();
        if ($from!==$to) CredentialStatusEvent::query()->create(['credential_id'=>$credential->id,'from_status'=>$from,'to_status'=>$to,'reason'=>'automatic_expiry_evaluation','occurred_at'=>now()]);
        return $credential->fresh();
    }
}
