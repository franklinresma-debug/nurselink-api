<?php
namespace App\Http\Controllers\Api;

use App\Services\Communications\MetaMessengerSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

class AdminMetaMessengerController extends AdminMetaWhatsAppController
{
    public function index(Request $request,MetaMessengerSettings $settings)
    {
        $this->authorizeSetup($request);
        return response()->json(['data'=>$settings->publicStatus()])->header('Cache-Control','no-store, private');
    }
    public function store(Request $request,MetaMessengerSettings $settings)
    {
        $this->authorizeSetup($request);
        $v=$request->validate([
            'revision'=>'required|integer|min:0',
            'page_id'=>['required','string','regex:/^\d{5,30}$/'],
            'app_id'=>['required','string','regex:/^\d{5,30}$/'],
            'graph_version'=>['required','string','regex:/^v\d{1,3}\.\d{1,2}$/'],
            'access_token'=>'nullable|string|min:20|max:4096',
            'app_secret'=>['nullable','string','regex:/^[a-fA-F0-9]{32,128}$/'],
            'verify_token'=>['nullable','string','min:24','max:128','regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        $settings->update(function($s)use($v,$request){
            abort_unless((int)($s['revision']??0)===(int)$v['revision'],409,'Settings changed in another session. Reload before saving.');
            foreach(['access_token','app_secret','verify_token'] as $key)if(!empty($v[$key]))$s[$key]=$v[$key];
            foreach(['page_id','app_id','graph_version'] as $key)$s[$key]=$v[$key];
            $s['revision']=(int)$v['revision']+1;$s['connection']=null;$s['webhook_verified_at']=null;$s['last_event_at']=null;
            $s['updated_at']=now()->toIso8601String();$s['updated_by']=(string)$request->user()->getKey();return $s;
        });
        return $this->index($request,$settings);
    }
    public function connection(Request $request,MetaMessengerSettings $settings)
    {
        $this->authorizeSetup($request);$v=$request->validate(['revision'=>'required|integer|min:0']);$s=$settings->effective();
        abort_unless((int)($s['revision']??0)===(int)$v['revision'],409,'Reload the saved settings before checking.');
        foreach(['page_id','app_id','graph_version','access_token'] as $key)abort_if(empty($s[$key]),422,'Save the Page ID, app ID, API version and Page access token first.');
        abort_unless(preg_match('/^v\d{1,3}\.\d{1,2}$/',$s['graph_version']) && ctype_digit((string)$s['page_id']) && ctype_digit((string)$s['app_id']),422,'Invalid Meta configuration.');
        $base='https://graph.facebook.com/'.$s['graph_version'].'/';
        $client=Http::withToken($s['access_token'])->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();
        try {
            $page=$client->get($base.'me',['fields'=>'id,name']);
            abort_unless($page->successful() && (string)$page->json('id')===(string)$s['page_id'],422,'The Page access token does not match this Facebook Page, or Meta rejected it.');
            $subscriptions=$client->get($base.$s['page_id'].'/subscribed_apps',['fields'=>'id,subscribed_fields','limit'=>100]);
            $app=$subscriptions->successful()?collect($subscriptions->json('data',[]))->first(fn($row)=>(string)($row['id']??'')===(string)$s['app_id']):null;
            $subscribed=is_array($app) && in_array('messages',$app['subscribed_fields']??[],true);
        }catch(ConnectionException $e){abort(503,'Meta could not be reached. Your saved settings have not changed.');}
        $settings->update(function($current)use($v,$page,$subscribed,$subscriptions){
            abort_unless((int)($current['revision']??0)===(int)$v['revision'],409,'Settings changed during the check. Check the new settings again.');
            $current['connection']=['checked_at'=>now()->toIso8601String(),'page_name'=>(string)$page->json('name',''),'messages_subscribed'=>$subscribed,'subscription_check_available'=>$subscriptions->successful()];return $current;
        });
        return $this->index($request,$settings);
    }
}
