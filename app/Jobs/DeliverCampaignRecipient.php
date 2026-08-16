<?php
namespace App\Jobs;
use App\Models\CampaignRecipient; use App\Models\CommunicationCampaign; use App\Models\DeliveryAttempt; use App\Services\Communications\DeliveryService; use Illuminate\Bus\Queueable; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Foundation\Bus\Dispatchable; use Illuminate\Queue\InteractsWithQueue; use Illuminate\Queue\SerializesModels; use Throwable;
class DeliverCampaignRecipient implements ShouldQueue {
 use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
 public int $tries=3; public int $timeout=60;
 public function __construct(public string $recipientId){}
 public function handle(DeliveryService $delivery):void{$recipient=CampaignRecipient::query()->with(['user','campaign'])->findOrFail($this->recipientId);if(!in_array($recipient->status,['pending','queued'],true))return;$campaign=$recipient->campaign;$out=$delivery->deliver($recipient->user,$campaign->category,$campaign->subject,$campaign->body,$campaign->channels??[],['priority'=>$campaign->priority],$recipient);$statuses=collect($out['results'])->pluck('status');$status=$statuses->contains('failed')?'partial':($statuses->contains(fn($s)=>in_array($s,['sent','delivered'],true))?'sent':'skipped');$recipient->update(['status'=>$status,'processed_at'=>now()]);$this->finishCampaign($campaign);}
 public function failed(Throwable $e):void{$recipient=CampaignRecipient::query()->with('campaign')->find($this->recipientId);if(!$recipient)return;$recipient->update(['status'=>'failed','processed_at'=>now()]);DeliveryAttempt::query()->create(['campaign_recipient_id'=>$recipient->id,'user_id'=>$recipient->user_id,'channel'=>'system','provider'=>'queue','status'=>'failed','error_code'=>'job_failed','error_message'=>$e->getMessage(),'attempted_at'=>now()]);$this->finishCampaign($recipient->campaign);}
 private function finishCampaign(CommunicationCampaign $campaign):void{if(!$campaign->recipients()->whereIn('status',['pending','queued'])->exists())$campaign->update(['status'=>'completed','completed_at'=>now()]);}
}
