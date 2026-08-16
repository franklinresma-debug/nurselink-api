<?php
namespace App\Services\Communications;
use App\Models\NotificationPreference; use App\Models\User;
class PreferenceService {
 public function get(User $user):NotificationPreference{return NotificationPreference::query()->firstOrCreate(['user_id'=>$user->id],array_merge(config('communications.default_channels',[]),['timezone'=>'Asia/Manila']));}
 public function resolveChannels(User $user,string $category,array $requested):array{
  $p=$this->get($user); $mandatory=in_array($category,config('communications.mandatory_categories',[]),true); $resolved=[];$suppressed=[];
  foreach(array_values(array_unique($requested)) as $channel){$field=$channel.'_enabled';$enabled=(bool)($p->{$field}??false);$categoryPrefs=$p->category_preferences??[];$categoryEnabled=$categoryPrefs[$category]??true;if($channel==='in_app'&&$mandatory){$enabled=true;$categoryEnabled=true;} if($enabled&&$categoryEnabled)$resolved[]=$channel;else $suppressed[$channel]=$mandatory&&$channel==='in_app'?'forced_service_channel':'member_preference';}
  return ['resolved'=>$resolved,'suppressed'=>$suppressed];
 }
}
