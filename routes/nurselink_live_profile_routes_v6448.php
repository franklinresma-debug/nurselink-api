<?php
/*
 * NurseLink Live DB Profile Tabs v64.4.8
 * This file is required by routes/api.php and reads only the isolated
 * fictional demo-member dataset from nurselink_demo_profile_records.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

if (!function_exists('nl6448_demo_profile_rows')) {
    function nl6448_demo_profile_rows(string $memberNumber,string $type): array
    {
        if (!Schema::hasTable('nurselink_demo_profile_records')) return [];

        $rows=DB::table('nurselink_demo_profile_records')
            ->where('member_number',$memberNumber)
            ->where('record_type',$type)
            ->where('is_active',1)
            ->orderBy('id')
            ->get();

        $out=[];
        foreach($rows as $row){
            $payload=json_decode($row->payload ?? '{}',true);
            if(is_array($payload)){
                $payload['_record_key']=$row->record_key ?? null;
                $payload['_updated_at']=$row->updated_at ?? null;
                $out[]=$payload;
            }
        }
        return $out;
    }
}

Route::get('/member-profile-live-v6448/{memberNumber}', function(string $memberNumber){
    if($memberNumber!=='NL-2026-000001'){
        return response()->json(['message'=>'Demo dataset not available'],404);
    }

    $types=['certificates','portfolio','experience','licenses','education','documents'];
    $data=['member_number'=>$memberNumber];

    foreach($types as $type){
        $data[$type]=nl6448_demo_profile_rows($memberNumber,$type);
    }

    return response()->json([
        'data'=>$data,
        'meta'=>[
            'source'=>'live_database',
            'table'=>'nurselink_demo_profile_records',
            'version'=>'64.4.8',
            'generated_at'=>now()->toIso8601String()
        ]
    ])->header('Cache-Control','no-store, no-cache, must-revalidate, max-age=0');
});
