<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
class RequirePermission
{
    // LBS_CORE_ADMIN_PERMISSION_BRIDGE_20260929. No roles are assigned here.
    private const ROLE_PERMISSIONS = [
        'membership_administrator' => ['directory.view','application.review','extraction.verify','portfolio.verify','reports.export'],
        'verification_officer' => ['directory.view','application.review','extraction.verify','portfolio.verify','credential.verify','assessment.perform','qualification.view','reports.export'],
        'content_administrator' => ['broadcast.send','communication.template.manage','communication.delivery.view'],
        'program_administrator' => ['directory.view','program.manage','organization.document.manage','event.manage','event.attendance.manage','reports.export'],
        'employment_administrator' => ['directory.view','reports.export'],
        'training_events_administrator' => ['directory.view','event.manage','event.attendance.manage','program.manage'],
        'communications_administrator' => ['directory.view','broadcast.send','communication.template.manage','communication.delivery.view'],
        'support_officer' => ['directory.view','welfare.view.assigned','communication.delivery.view'],
        'finance_treasurer' => ['program.finance.manage','reports.export'],
        'reports_analytics' => ['analytics.view','reports.export','audit.view'],
    ];
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user=$request->user();
        $access=app(\App\Services\ApplicantPortalAccess::class);
        $staffRoute=preg_match('~(?:^|/)(admin|reviewer|partner|encoder)(?:/|$)~',$request->path());
        if(!$staffRoute && $access->eligible($user) && in_array($permission,\App\Services\ApplicantPortalAccess::PERMISSIONS,true)){
            $access->ownRecord($user);
            return $next($request);
        }
        $allowed=$user && ($request->is('api/admin/*') ? $this->adminAllowed($request,$user,$permission) : $user->hasPermission($permission));
        if (!$allowed) return response()->json(['message'=>'You do not have permission to perform this action.','code'=>'forbidden','required_permission'=>$permission],403);
        return $next($request);
    }
    private function adminAllowed(Request $request, $user, string $permission): bool
    {
        $id=(string)$user->getKey();
        $assignments=Schema::hasTable('nurselink_admin_role_assignments')
            ? DB::table('nurselink_admin_role_assignments')->where('user_id',$id)->get(['role_key','active']) : collect();
        if ($assignments->isNotEmpty()) {
            $roles=$assignments->filter(fn($row)=>(bool)$row->active)->pluck('role_key')->all();
            if (in_array('super_administrator',$roles,true)) return true;
            if (in_array('auditor_read_only',$roles,true)) {
                return in_array($request->method(),['GET','HEAD','OPTIONS'],true)
                    && in_array($permission,['directory.view','analytics.view','reports.export','audit.view','operations.view','qualification.view','communication.delivery.view'],true);
            }
            foreach ($roles as $role) if (in_array($permission,self::ROLE_PERMISSIONS[$role] ?? [],true)) return true;
            return false; // An inactive/restricted assignment cannot fall back to an older core grant.
        }
        if (Schema::hasTable('nurselink_super_admin_access') && DB::table('nurselink_super_admin_access')->where('user_id',$id)->where('active',true)->exists()) return true;
        $staff=['super_administrator','administrator','membership_officer','assessor','communications_officer','program_policy_officer','welfare_officer'];
        // directory.view is also a member permission; it alone must not expose the admin directory.
        return (bool)array_intersect($staff,$user->roleCodes()) && $user->hasPermission($permission);
    }
}
