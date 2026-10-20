<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

if (!function_exists('nl6436_resolve_identity')) {
    function nl6436_resolve_identity(Request $request): array
    {
        $u=$request->user();
        if (!$u) return ['user_id'=>null,'member_id'=>null,'membership_id'=>null,'member_number'=>null];

        $membershipTable = Schema::hasTable('nurselink_memberships')
            ? 'nurselink_memberships'
            : (Schema::hasTable('memberships') ? 'memberships' : null);

        if (!$membershipTable) {
            return ['user_id'=>$u->id ?? null,'member_id'=>null,'membership_id'=>null,'member_number'=>null];
        }

        $cols=Schema::getColumnListing($membershipTable);
        $m=null;
        if (in_array('user_id',$cols,true)) {
            $m=DB::table($membershipTable)->where('user_id',$u->id)->first();
        }

        return [
            'user_id'=>$u->id ?? null,
            'member_id'=>$m->member_id ?? ($m->id ?? null),
            'membership_id'=>$m->id ?? null,
            'member_number'=>$m->member_number ?? ($m->member_no ?? ($m->membership_number ?? null)),
        ];
    }
}

Route::middleware('auth:sanctum')->get('/member-qualification-framework-v6436/me', function (Request $request) {
    $identity=nl6436_resolve_identity($request);
    if (!$identity['member_number']) {
        return response()->json(['data'=>null,'message'=>'Membership not resolved'],404);
    }

    $qf = Schema::hasTable('nurselink_qualification_framework_references')
        ? DB::table('nurselink_qualification_framework_references')
            ->where('member_number',$identity['member_number'])
            ->orderByDesc('is_primary')
            ->orderByDesc('updated_at')
            ->first()
        : null;

    if (!$qf) {
        return response()->json(['data'=>null,'message'=>'No qualification framework reference found'],404);
    }

    $policy=null;
    if (Schema::hasTable('nurselink_qf_catalog_frameworks') && ($qf->country_code ?? null) && ($qf->framework_code ?? null)) {
        $policy=DB::table('nurselink_qf_catalog_frameworks')
            ->where('country_code',$qf->country_code)
            ->where('framework_code',$qf->framework_code)
            ->orderByDesc('is_current')
            ->orderByDesc('updated_at')
            ->first();
    }

    return response()->json(['data'=>[
        'member_number'=>$identity['member_number'],
        'qualification'=>(array)$qf,
        'policy'=>$policy ? (array)$policy : null,
    ]]);
});
