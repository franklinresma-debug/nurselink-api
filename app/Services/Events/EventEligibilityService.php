<?php
namespace App\Services\Events;
use App\Models\Event; use App\Models\Member;
class EventEligibilityService {
 public function eligible(Member $member,Event $event):bool{$f=$event->audience_filters??[];if(!$f)return true;$user=$member->user;
  if(($ids=$f['member_ids']??[])&&!in_array($member->id,$ids,true))return false;
  if(($statuses=$f['member_statuses']??[])&&!in_array($member->status,$statuses,true))return false;
  if(($roles=$f['role_codes']??[])&&!array_intersect($roles,$user->roleCodes()))return false;
  $profile=$member->profile;
  if(($countries=$f['countries']??[])&&!in_array($profile?->country,$countries,true))return false;
  if(($regions=$f['regions']??[])&&!in_array($profile?->region,$regions,true))return false;
  return true;
 }
}
