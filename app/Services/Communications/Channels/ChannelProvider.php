<?php
namespace App\Services\Communications\Channels;
use App\Models\User;
interface ChannelProvider { public function name():string; public function send(User $user,string $subject,string $body,array $context=[]):array; }
