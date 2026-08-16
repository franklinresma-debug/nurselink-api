<?php
namespace App\Http\Controllers\Api\Admin\Events;
use App\Http\Controllers\Controller; use App\Http\Requests\Events\StoreEventRequest; use App\Models\Event; use App\Services\Events\EventCommunicationService; use App\Services\IdentifierService; use Illuminate\Http\JsonResponse;
class EventAdminController extends Controller {
 public function index():JsonResponse{return response()->json(['data'=>Event::query()->withCount('registrations')->orderByDesc('starts_at')->paginate(30)]);}
 public function store(StoreEventRequest $r,IdentifierService $ids):JsonResponse{$d=$r->validated();$d['event_no']=$ids->next('event','NLEV');$d['created_by']=$r->user()->id;$event=Event::query()->create($d);return response()->json(['data'=>$event->makeVisible('online_url')],201);}
 public function show(Event $event):JsonResponse{return response()->json(['data'=>$event->loadCount('registrations')->load(['registrations.member.profile'])->makeVisible('online_url')]);}
 public function update(StoreEventRequest $r,Event $event):JsonResponse{$event->update($r->validated());return response()->json(['data'=>$event->fresh()->makeVisible('online_url')]);}
 public function cancel(Event $event,EventCommunicationService $communications):JsonResponse{abort_if($event->status==='cancelled',422,'Event is already cancelled.');$event->update(['status'=>'cancelled']);$notified=$communications->notifyCancellation($event);return response()->json(['data'=>$event,'registrants_notified'=>$notified]);}
}
