<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
class LbsOperationsAccess {
 public function handle(Request $r,Closure $next): Response {
  $u=$r->user();abort_unless($u,401);
  $rows=DB::table('nurselink_admin_role_assignments')->where('user_id',$u->getKey())->get(['role_key','active']);
  if($rows->isNotEmpty()){
   $roles=$rows->filter(fn($x)=>(bool)$x->active)->pluck('role_key')->all();
   $allowed=in_array('super_administrator',$roles,true) || ($r->isMethod('GET') && in_array('auditor_read_only',$roles,true));
  }else{
   $allowed=$u->hasRole('super_administrator') || $u->hasRole('administrator') || DB::table('nurselink_super_admin_access')->where('user_id',$u->getKey())->where('active',true)->exists();
  }
  abort_unless($allowed,403,'Queue administration requires an active administrator.');
  $r->attributes->set('lbs_queue_can_manage',!isset($roles) || in_array('super_administrator',$roles,true));
  return $next($r);
 }
}
