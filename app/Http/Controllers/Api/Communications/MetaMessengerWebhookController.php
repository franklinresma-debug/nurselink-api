<?php
namespace App\Http\Controllers\Api\Communications;

use App\Http\Controllers\Controller;
use App\Services\Communications\MetaMessengerSettings;
use Illuminate\Http\Request;

class MetaMessengerWebhookController extends Controller
{
    public function verify(Request $request,MetaMessengerSettings $settings)
    {
        $s=$settings->effective();$token=(string)($s['verify_token']??'');
        abort_unless($token!=='' && $request->query('hub_mode')==='subscribe' && hash_equals($token,(string)$request->query('hub_verify_token')),403);
        $challenge=(string)$request->query('hub_challenge');abort_unless(preg_match('/^\d{1,100}$/',$challenge),400);
        $settings->update(function($current)use($token){if(hash_equals((string)($current['verify_token']??''),$token))$current['webhook_verified_at']=now()->toIso8601String();return $current;});
        return response($challenge)->header('Content-Type','text/plain');
    }
    public function receive(Request $request,MetaMessengerSettings $settings)
    {
        $s=$settings->effective();$secret=(string)($s['app_secret']??'');
        abort_unless($secret!=='' && hash_equals('sha256='.hash_hmac('sha256',$request->getContent(),$secret),(string)$request->header('X-Hub-Signature-256')),403);
        abort_unless($request->input('object')==='page' && is_array($request->input('entry')),400);
        $matched=false;
        foreach($request->input('entry',[]) as $entry)if(is_array($entry) && (string)($entry['id']??'')===(string)($s['page_id']??'') && !empty($entry['messaging']))$matched=true;
        if($matched)$settings->update(function($current)use($s){
            if(($current['revision']??0)===($s['revision']??0))$current['last_event_at']=now()->toIso8601String();
            return $current;
        });
        // Setup diagnostics only. Message bodies and participant IDs are not stored.
        return response()->json(['received'=>true]);
    }
}
