<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\NurseLinkEmailSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminEmailSetupController extends Controller
{
    public function __construct(private readonly NurseLinkEmailSettings $settings, private readonly AuditLogger $audit) {}

    private function authorizeSetup(Request $request): void
    {
        $user=$request->user(); abort_unless($user,401);
        $id=(string)$user->getKey();
        $super = Schema::hasTable('nurselink_admin_role_assignments') && DB::table('nurselink_admin_role_assignments')->where('user_id',$id)->where('active',true)->where('role_key','super_administrator')->exists();
        $super = $super || (Schema::hasTable('nurselink_super_admin_access') && DB::table('nurselink_super_admin_access')->where('user_id',$id)->where('active',true)->exists());
        $super = $super || $user->hasRole('super_administrator') || (bool)($user->is_super_admin ?? false) || in_array(strtolower((string)($user->role ?? $user->user_role ?? $user->user_type ?? '')),['super_admin','super-administrator','super_administrator','superadministrator'],true);
        abort_unless($super,403,'Email Setup requires Super Administrator access.');
        abort_unless($request->hasSession() && hash_equals($id,(string)$request->session()->get('nurselink_admin_elevated_user_id','')) && (int)$request->session()->get('nurselink_admin_expires_at',0)>=time() && (int)$request->session()->get('nurselink_admin_elevated_at',0)>0 && time()-(int)$request->session()->get('nurselink_admin_elevated_at',0)<=7200,403,'Sign in through the NurseLink Administrator sign-in before opening Email Setup.');
    }

    public function show(Request $request) { $this->authorizeSetup($request); return response()->json(['data'=>$this->settings->publicValues()])->header('Cache-Control','private, no-store'); }

    public function update(Request $request)
    {
        $this->authorizeSetup($request);
        $rules=['mailbox'=>['required','email','max:254'],'allowed_admins'=>['present','array','max:25'],'allowed_admins.*'=>['email','max:254','distinct'],'sync_enabled'=>['required','boolean'],'smtp_enabled'=>['required','boolean']];
        foreach (['imap','smtp'] as $kind) {
            $rules[$kind]=['required','array:host,port,encryption,username,password'];
            $rules[$kind.'.host']=['nullable','string','max:253','regex:/\A[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?\z/D'];
            $rules[$kind.'.port']=['required','integer',Rule::in($kind==='imap'?[993,143]:[465,587])];
            $rules[$kind.'.encryption']=['required',Rule::in(['ssl','tls'])];
            $rules[$kind.'.username']=['nullable','string','max:254'];
            $rules[$kind.'.password']=['nullable','string','max:1024'];
        }
        $data=$request->validate($rules);
        foreach (['imap','smtp'] as $kind) { $data[$kind]['host']=strtolower(trim($data[$kind]['host'] ?? '')); $data[$kind]['username']=trim($data[$kind]['username'] ?? ''); }
        $data['allowed_admins']=array_values(array_unique(array_map('strtolower',$data['allowed_admins'])));
        $this->settings->save($data);
        $this->audit->write('email.setup_updated',$request->user(),'email_settings','1',['sync_enabled'=>(bool)$data['sync_enabled'],'smtp_enabled'=>(bool)$data['smtp_enabled'],'credentials_excluded'=>true],$request);
        return response()->json(['message'=>'NurseLink email settings saved.','data'=>$this->settings->publicValues()])->header('Cache-Control','private, no-store');
    }

    public function test(Request $request)
    {
        $this->authorizeSetup($request); $data=$request->validate(['connection'=>['required',Rule::in(['imap','smtp'])]]);
        try { $ok=$this->settings->test($data['connection']); } catch (\Throwable $exception) { $ok=false; }
        $this->audit->write('email.connection_checked',$request->user(),'email_settings','1',['connection'=>$data['connection'],'connected'=>$ok,'no_email_sent'=>true],$request);
        return response()->json(['connected'=>$ok,'message'=>$ok?'Secure connection and sign-in succeeded. No email was sent.':'Connection failed. Check the server, TLS, username and password. No email was sent.'], $ok?200:422)->header('Cache-Control','private, no-store');
    }
}
