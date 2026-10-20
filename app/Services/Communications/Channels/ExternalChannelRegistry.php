<?php
namespace App\Services\Communications\Channels;
use App\Models\User;
/** Explicit server-side enablement is independent of a member's preferences. */
class ExternalChannelRegistry {
 public function provider(string $channel):ChannelProvider {
  if ($channel === 'whatsapp') app(\App\Services\Communications\MetaWhatsAppSettings::class)->apply();
  if(!in_array($channel,['sms','whatsapp'],true))return new UnconfiguredChannelProvider($channel);
  if(!config('communications.external_delivery_enabled.'.$channel,false))return new class($channel) implements ChannelProvider {
   public function __construct(private string $channel){}
   public function name():string{return 'disabled';}
   public function send(User $user,string $subject,string $body,array $context=[]):array{return ['status'=>'skipped','provider'=>'disabled','error_code'=>'channel_disabled','error_message'=>strtoupper($this->channel).' sending is disabled by the administrator.'];}
  };
  $provider=config('communications.external_providers.'.$channel,'unconfigured');
  $class=config('communications.provider_classes.'.$provider);
  if(!is_string($class)||!is_subclass_of($class,ChannelProvider::class))return new UnconfiguredChannelProvider($channel);
  return app($class);
 }
}
