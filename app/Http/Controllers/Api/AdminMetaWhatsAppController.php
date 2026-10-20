<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Communications\MetaWhatsAppSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema, Http};
use Illuminate\Http\Client\ConnectionException;

class AdminMetaWhatsAppController extends Controller
{
    protected function authorizeSetup(Request $request): void
    {
        $u = $request->user();
        abort_unless($u, 401);
        $allowed = $u->hasRole('super_administrator') || (bool)($u->is_super_admin??false)
            || in_array(strtolower((string)($u->role??$u->user_role??$u->user_type??'')), ['super_admin','super-administrator','super_administrator','superadministrator'], true);
        if (!$allowed && Schema::hasTable('nurselink_super_admin_access')) $allowed = DB::table('nurselink_super_admin_access')->where('user_id',$u->getKey())->where('active',true)->exists();
        if (!$allowed && Schema::hasTable('nurselink_admin_role_assignments')) $allowed = DB::table('nurselink_admin_role_assignments')->where('user_id',$u->getKey())->where('role_key','super_administrator')->where('active',true)->exists();
        abort_unless($allowed, 403, 'Only Super Administrators can configure WhatsApp credentials.');
    }

    private function response(MetaWhatsAppSettings $settings)
    {
        return response()->json(['data'=>$settings->publicStatus()])->header('Cache-Control','no-store, private');
    }

    public function show(Request $request, MetaWhatsAppSettings $settings)
    {
        $this->authorizeSetup($request);
        return $this->response($settings);
    }

    public function save(Request $request, MetaWhatsAppSettings $settings)
    {
        $this->authorizeSetup($request);
        $v = $request->validate([
            'revision'=>'required|integer|min:0',
            'phone_number_id'=>['required','string','regex:/^\d{5,30}$/'],
            'business_account_id'=>['required','string','regex:/^\d{5,30}$/'],
            'graph_version'=>['required','string','regex:/^v\d{1,3}\.\d{1,2}$/'],
            'access_token'=>'nullable|string|min:20|max:4096',
            'app_secret'=>['nullable','string','regex:/^[a-fA-F0-9]{32,128}$/'],
            'verify_token'=>['nullable','string','min:24','max:128','regex:/^[A-Za-z0-9_-]+$/'],
            'templates'=>'present|array:appointment_reminder,application_update,document_request',
            'templates.*'=>'array:name,language,parameter_count',
            'templates.*.name'=>['required','string','max:100','regex:/^[a-z0-9_]+$/'],
            'templates.*.language'=>['required','string','max:12','regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
            'templates.*.parameter_count'=>'required|integer|min:0|max:10',
        ]);
        $settings->update(function($s) use ($v,$request) {
            abort_unless((int)($s['revision']??0)===$v['revision'],409,'Settings changed in another session. Reload before saving.');
            $secretChanged = false;
            foreach (['access_token','app_secret','verify_token'] as $key) {
                if (!empty($v[$key])) { $secretChanged = $secretChanged || ($s[$key]??'')!==$v[$key]; $s[$key]=$v[$key]; }
            }
            foreach (['phone_number_id','business_account_id','graph_version','templates'] as $key) $s[$key]=$v[$key];
            $s['revision']=$v['revision']+1;
            $s['connection']=null;
            // Account/secret edits require Meta to verify the webhook again.
            $s['webhook_verified_at']=null;
            $s['updated_at']=now()->toIso8601String();
            $s['updated_by']=(string)$request->user()->getKey();
            return $s;
        });
        return $this->response($settings);
    }

    public function check(Request $request, MetaWhatsAppSettings $settings)
    {
        $this->authorizeSetup($request);
        $v=$request->validate(['revision'=>'required|integer|min:0']);
        $s=$settings->effective();
        abort_unless((int)($s['revision']??0)===$v['revision'],409,'Reload the saved settings before checking.');
        foreach (['phone_number_id','business_account_id','graph_version','access_token'] as $key) abort_if(empty($s[$key]),422,'Save the phone number ID, business account ID, API version and access token first.');
        abort_unless(preg_match('/^v\d{1,3}\.\d{1,2}$/',$s['graph_version']) && ctype_digit((string)$s['phone_number_id']) && ctype_digit((string)$s['business_account_id']),422,'Invalid Meta configuration.');
        $base='https://graph.facebook.com/'.$s['graph_version'].'/';
        $client=Http::withToken($s['access_token'])->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();
        try {
            $phone=$client->get($base.$s['phone_number_id'],['fields'=>'id,display_phone_number,verified_name']);
            abort_unless($phone->successful() && (string)$phone->json('id')===(string)$s['phone_number_id'],422,'Meta could not verify this phone number. Check the saved token, number ID and permissions.');
            $numbers=$client->get($base.$s['business_account_id'].'/phone_numbers',['fields'=>'id','limit'=>100]);
            abort_unless($numbers->successful() && collect($numbers->json('data',[]))->contains(fn($n)=>(string)($n['id']??'')===(string)$s['phone_number_id']),422,'The phone number could not be confirmed under this WhatsApp Business Account.');
            $templates=[];
            foreach ($s['templates']??[] as $key=>$template) {
                $r=$client->get($base.$s['business_account_id'].'/message_templates',['name'=>$template['name'],'limit'=>100]);
                abort_unless($r->successful(),422,'Meta could not read templates. Check WhatsApp Business Management permissions.');
                $match=collect($r->json('data',[]))->first(fn($t)=>($t['name']??'')===$template['name'] && ($t['language']??'')===$template['language']);
                $body=collect($match['components']??[])->firstWhere('type','BODY');
                preg_match_all('/\{\{(\d+)\}\}/',$body['text']??'', $parameters);
                $indexes=array_values(array_unique(array_map('intval',$parameters[1])));sort($indexes);
                $expected=$template['parameter_count'] ? range(1,$template['parameter_count']) : [];
                $supported=!(bool)collect($match['components']??[])->contains(fn($c)=>!in_array($c['type']??'', ['BODY','FOOTER','HEADER'],true) || (($c['type']??'')==='HEADER' && (($c['format']??'')!=='TEXT' || str_contains($c['text']??'','{{'))));
                $templates[$key]=['approved'=>($match['status']??'')==='APPROVED' && $indexes===$expected && $supported && ($match['parameter_format']??'POSITIONAL')!=='NAMED'];
            }
        } catch(ConnectionException $e) { abort(503,'Meta could not be reached. Your saved settings have not changed.'); }
        $settings->update(function($current) use($v,$phone,$templates) {
            abort_unless((int)($current['revision']??0)===$v['revision'],409,'Settings changed during the check. Check the new settings again.');
            $current['connection']=['checked_at'=>now()->toIso8601String(),'verified_name'=>(string)$phone->json('verified_name',''),'display_phone_number'=>(string)$phone->json('display_phone_number',''),'templates'=>$templates];
            return $current;
        });
        return $this->response($settings);
    }
}
