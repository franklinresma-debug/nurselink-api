<?php
namespace App\Http\Controllers\Api\Admin\Communications;
use App\Http\Controllers\Controller; use App\Http\Requests\Communications\StoreCampaignRequest; use App\Models\CommunicationCampaign; use App\Models\DeliveryAttempt; use App\Services\Communications\AudienceResolver; use App\Services\Communications\CampaignService; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class CampaignController extends Controller {
 public function index():JsonResponse{return response()->json(['data'=>CommunicationCampaign::query()->withCount('recipients')->latest()->paginate(30)]);}
 public function store(StoreCampaignRequest $r,CampaignService $s):JsonResponse{return response()->json(['data'=>$s->create($r->validated(),$r->user()->id)],201);}
 public function show(CommunicationCampaign $campaign):JsonResponse{$ids=$campaign->recipients()->pluck('id');$summary=DeliveryAttempt::query()->whereIn('campaign_recipient_id',$ids)->selectRaw('channel, status, count(*) as total')->groupBy('channel','status')->get();return response()->json(['data'=>$campaign->loadCount('recipients')->load(['recipients'=>fn($q)=>$q->latest()->limit(100)->with('deliveryAttempts')]),'delivery_summary'=>$summary]);}
 public function previewAudience(Request $r,AudienceResolver $a):JsonResponse{$filters=$r->validate(['audience_filters'=>['required','array','min:1']])['audience_filters'];$users=$a->resolve($filters);return response()->json(['count'=>$users->count(),'sample'=>$users->take(20)->map->only(['id','name','email'])->values()]);}
 public function schedule(Request $r,CommunicationCampaign $campaign,CampaignService $s):JsonResponse{$data=$r->validate(['scheduled_at'=>['nullable','date']]);return response()->json(['data'=>$s->schedule($campaign,$data['scheduled_at']??null)]);}
 public function sendNow(CommunicationCampaign $campaign,CampaignService $s):JsonResponse{return response()->json(['data'=>$s->dispatch($campaign)],202);}
 public function cancel(CommunicationCampaign $campaign):JsonResponse{abort_unless(in_array($campaign->status,['draft','scheduled'],true),422,'Only draft/scheduled campaigns can be cancelled.');$campaign->update(['status'=>'cancelled']);return response()->json(['data'=>$campaign]);}
}
