<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

if (!function_exists('nl6431_qfr_resolve_member')) {
    function nl6431_qfr_resolve_member(Request $request): array
    {
        $user = $request->user();
        $userId = $user?->id;

        $membershipTable = Schema::hasTable('nurselink_memberships')
            ? 'nurselink_memberships'
            : (Schema::hasTable('memberships') ? 'memberships' : null);

        if (!$membershipTable || !$userId) {
            return ['user_id'=>$userId, 'member_id'=>null, 'membership_id'=>null, 'member_number'=>null];
        }

        $membership = DB::table($membershipTable)->where('user_id', $userId)->first();

        return [
            'user_id'=>$userId,
            'member_id'=>$membership->member_id ?? ($membership->id ?? null),
            'membership_id'=>$membership->id ?? null,
            'member_number'=>$membership->member_number
                ?? ($membership->member_no
                ?? ($membership->membership_number ?? null)),
        ];
    }
}

Route::middleware('auth:sanctum')->prefix('global-qualification-framework')->group(function () {

    Route::get('/me', function (Request $request) {
        $identity = nl6431_qfr_resolve_member($request);

        if (!$identity['member_number']) {
            return response()->json(['data'=>null, 'message'=>'Membership not resolved'], 404);
        }

        $row = DB::table('nurselink_qualification_framework_references')
            ->where('member_number', $identity['member_number'])
            ->orderByDesc('is_primary')
            ->orderByDesc('updated_at')
            ->first();

        return response()->json(['data'=>$row]);
    });

    Route::get('/catalog', function () {
        return response()->json([
            'data'=>[
                [
                    'country_code'=>'PH',
                    'framework_code'=>'PQF',
                    'framework_name'=>'Philippine Qualifications Framework',
                    'notes'=>'National framework reference; framework level must remain distinct from other countries’ numbering.'
                ],
                [
                    'country_code'=>'AU',
                    'framework_code'=>'AQF',
                    'framework_name'=>'Australian Qualifications Framework',
                    'notes'=>'AQF level numbering is framework-specific and should not be numerically compared directly with PQF/RQF.'
                ],
                [
                    'country_code'=>'GB',
                    'framework_code'=>'RQF',
                    'framework_name'=>'Regulated Qualifications Framework',
                    'notes'=>'UK framework reference.'
                ],
                [
                    'country_code'=>'EU',
                    'framework_code'=>'EQF',
                    'framework_name'=>'European Qualifications Framework',
                    'notes'=>'Reference framework; national framework level should also be retained when available.'
                ],
            ]
        ]);
    });
});
