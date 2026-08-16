<?php
namespace App\Services\Communications\Channels;
use App\Models\User;
class UnconfiguredChannelProvider implements ChannelProvider { public function __construct(private string $channel){} public function name():string{return 'unconfigured';} public function send(User $user,string $subject,string $body,array $context=[]):array{return ['status'=>'skipped','provider'=>'unconfigured','error_code'=>'provider_not_configured','error_message'=>strtoupper($this->channel).' provider is not configured.'];} }
