<?php
namespace App\Services\Events;
use App\Models\Event; use App\Models\MessageTemplate; use App\Services\Communications\DeliveryService; use Throwable;
class EventCommunicationService {
 public function __construct(private DeliveryService $delivery){}
 public function notifyCancellation(Event $event):int{$template=MessageTemplate::query()->where('code','event_cancelled')->where('governance_status','published')->first();if(!$template)return 0;$count=0;$event->registrations()->with('member.user')->whereIn('status',['registered','waitlisted'])->get()->each(function($r)use($event,$template,&$count){$u=$r->member?->user;if(!$u)return;try{$this->delivery->deliver($u,$template->category,$template->subject_template,$template->body_template,['in_app','email'],['event_title'=>$event->title,'action_url'=>'/events','dedupe_key'=>'event-cancelled:'.$event->id.':'.$u->id]);$count++;}catch(Throwable){/* cancellation itself must remain successful even if one notification fails */}});return $count;}
}
