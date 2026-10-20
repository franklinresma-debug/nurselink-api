<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

if (!function_exists('nl6443_demo_rows')) {
    function nl6443_demo_rows(string $memberNumber,string $type): array
    {
        if (!Schema::hasTable('nurselink_demo_profile_records')) return [];

        $rows=DB::table('nurselink_demo_profile_records')
            ->where('member_number',$memberNumber)
            ->where('record_type',$type)
            ->where('is_active',true)
            ->orderBy('id')
            ->get();

        $out=[];
        foreach($rows as $row){
            $payload=json_decode($row->payload ?? '{}',true);
            if(!is_array($payload)) continue;
            $payload['source_table']='nurselink_demo_profile_records';
            $payload['record_key']=$row->record_key ?? null;
            $out[]=$payload;
        }
        return $out;
    }
}

Route::middleware('auth:sanctum')->get('/member-profile-tabs-v6443', function(Request $request){
    $memberNumber=trim((string)$request->query('member_number',''));

    if ($memberNumber==='') {
        return response()->json(['message'=>'member_number is required'],422);
    }

    // This endpoint is for the dedicated fictional demo account dataset only.
    if ($memberNumber !== 'NL-2026-000001') {
        return response()->json(['message'=>'Demo profile dataset is available only for NL-2026-000001'],403);
    }

    $types=['certificates','portfolio','experience','licenses','education','documents'];
    $data=[
        'identity'=>['member_number'=>$memberNumber],
    ];

    foreach($types as $type){
        $data[$type]=nl6443_demo_rows($memberNumber,$type);
    }

    return response()->json(['data'=>$data]);
});
