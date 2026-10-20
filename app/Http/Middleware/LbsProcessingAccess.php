<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use App\Services\LbsProcessingService;
use Illuminate\Support\Facades\DB;
class LbsProcessingAccess {
 public function handle(Request $r,Closure $next){
  $manage=LbsProcessingService::canManage((string)$r->user()->getKey());
  $audit=DB::table('nurselink_admin_role_assignments')->where('user_id',$r->user()->getKey())->where('role_key','auditor_read_only')->where('active',true)->exists();
  abort_unless($manage||($r->isMethod('GET')&&$audit),403,'An authorized LBS administrator is required.');
  $r->attributes->set('lbs_processing_manage',$manage);return $next($r);
 }
}
