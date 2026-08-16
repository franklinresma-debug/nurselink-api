<?php
namespace App\Services\Credentials;
use App\Models\CredentialReminderEvent; use App\Models\ProfessionalCredential; use Carbon\CarbonImmutable; use Illuminate\Support\Collection;
class CredentialReminderService
{
    public function rebuild(ProfessionalCredential $credential):void
    {
        $credential->reminders()->whereIn('state',['pending','suppressed'])->delete();
        if ($credential->does_not_expire || !$credential->expires_on || in_array($credential->credential_status,['revoked','lapsed'],true)) return;
        $expiry=CarbonImmutable::parse($credential->expires_on)->startOfDay();
        $rules=['90_day'=>90,'60_day'=>60,'30_day'=>30];
        foreach($rules as $type=>$days){
            CredentialReminderEvent::query()->firstOrCreate(
                ['credential_id'=>$credential->id,'reminder_type'=>$type,'trigger_on'=>$expiry->subDays($days)->toDateString()],
                ['member_id'=>$credential->member_id,'state'=>'pending']
            );
        }
        CredentialReminderEvent::query()->firstOrCreate(
            ['credential_id'=>$credential->id,'reminder_type'=>'expired','trigger_on'=>$expiry->addDay()->toDateString()],
            ['member_id'=>$credential->member_id,'state'=>'pending']
        );
    }
    public function due(?CarbonImmutable $today=null):Collection
    {
        $date=($today ?: CarbonImmutable::today())->toDateString();
        return CredentialReminderEvent::query()->with('credential')->where('state','pending')->whereDate('trigger_on','<=',$date)->orderBy('trigger_on')->get();
    }
    public function markQueued(CredentialReminderEvent $event):void{$event->forceFill(['state'=>'queued','queued_at'=>now()])->save();}
}
