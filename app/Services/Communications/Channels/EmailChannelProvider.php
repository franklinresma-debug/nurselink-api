<?php
namespace App\Services\Communications\Channels;
use App\Models\User; use Illuminate\Support\Facades\Mail; use Throwable;
class EmailChannelProvider implements ChannelProvider { public function name():string{return 'laravel_mail';} public function send(User $user,string $subject,string $body,array $context=[]):array{try{Mail::raw($body,fn($m)=>$m->to($user->email,$user->name)->subject($subject));return ['status'=>'sent','provider'=>$this->name()];}catch(Throwable $e){return ['status'=>'failed','provider'=>$this->name(),'error_code'=>'mail_send_failed','error_message'=>$e->getMessage()];}} }
