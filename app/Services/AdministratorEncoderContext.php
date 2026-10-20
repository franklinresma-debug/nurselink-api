<?php
namespace App\Services;
use App\Models\TemporaryEncoderAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB,Hash};
use Illuminate\Support\Str;
class AdministratorEncoderContext
{
 public function resolve(Request $request): ?TemporaryEncoderAccount
 {
  $user=Auth::guard('web')->user();
  if(!$user||!$request->hasSession())return null;
  $id=(string)$user->getKey();
  if((string)$request->session()->get('nurselink_admin_elevated_user_id')!==$id || (int)$request->session()->get('nurselink_admin_expires_at')<=time())return null;
  $super=DB::table('nurselink_admin_role_assignments')->where('user_id',$id)->where('role_key','super_administrator')->where('active',true)->exists() || DB::table('nurselink_super_admin_access')->where('user_id',$id)->where('active',true)->exists();
  if(!$super)return null;
  $name='staff-admin-'.hash('sha256',$id);
  $account=TemporaryEncoderAccount::firstOrCreate(['username'=>$name],['display_name'=>$user->name.' (Administrator)','password'=>Hash::make(Str::random(80)),'active'=>true,'expires_at'=>now()->addHours(8),'created_by_user_id'=>$id]);
  abort_unless((string)$account->created_by_user_id===$id && $account->active,403,'Administrator encoding access is unavailable.');
  $expires=\Carbon\Carbon::createFromTimestamp((int)$request->session()->get('nurselink_admin_expires_at'), config('app.timezone'));
  if(!$account->expires_at || !$account->expires_at->equalTo($expires))$account->forceFill(['expires_at'=>$expires])->save();
  // Request-scoped identity only: never log in as, or impersonate, an encoder.
  Auth::guard('encoder')->setUser($account);
  $request->attributes->set('administrator_encoder_context',true);
  return $account;
 }
}
