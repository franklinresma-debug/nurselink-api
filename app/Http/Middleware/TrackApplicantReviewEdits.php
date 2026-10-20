<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\MembershipLifecycleService;

class TrackApplicantReviewEdits
{
    public function handle(Request $request,Closure $next)
    {
        if($request->isMethod('GET') || $request->isMethod('HEAD') || str_ends_with($request->path(),'/submit')) return $next($request);
        return DB::transaction(function()use($request,$next){
            $before=DB::table('nurselink_memberships')->where('user_id',$request->user()->getKey())->lockForUpdate()->first();
            $response=$next($request);
            if(!$before || $response->getStatusCode()>=400)return $response;
            $isUpdate=$before->status==='approved';
            $reviewStatus=$isUpdate ? ($before->profile_update_status ?? '') : $before->status;
            if(!in_array($reviewStatus,['submitted','under_review','ready_for_approval','needs_information'],true))return $response;
            $nextStatus=$reviewStatus==='ready_for_approval'?'under_review':$reviewStatus;
            // Approval uses the same membership lock and updated_at conflict check.
            // Always invalidate a reviewer snapshot, including saves in the same second.
            $stamp=now()->startOfSecond();
            if(!empty($before->updated_at) && $stamp->lte(\Illuminate\Support\Carbon::parse($before->updated_at)))$stamp=\Illuminate\Support\Carbon::parse($before->updated_at)->addSecond();
            $update=['updated_at'=>$stamp];
            if($isUpdate){$update['profile_update_status']=$nextStatus;$update['profile_update_reviewed_at']=null;}
            else {$update['status']=$nextStatus;$update['reviewed_at']=null;}
            DB::table('nurselink_memberships')->where('id',$before->id)->update($update);
            $after=DB::table('nurselink_memberships')->where('id',$before->id)->first();
            app(MembershipLifecycleService::class)->recordTransition($after,$reviewStatus,$nextStatus,(string)$request->user()->getKey(),'applicant',
                'Applicant updated registration during staff review. Review the latest information before approval.',
                ['review_type'=>$isUpdate?'profile_update':'membership','applicant_edit'=>true,'section'=>basename($request->path())]);
            return $response;
        });
    }
}
