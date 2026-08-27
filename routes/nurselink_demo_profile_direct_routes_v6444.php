<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

if (!function_exists('nl6444_demo_records')) {
    function nl6444_demo_records(string $memberNumber,string $type): array
    {
        if (!Schema::hasTable('nurselink_demo_profile_records')) return [];

        return DB::table('nurselink_demo_profile_records')
            ->where('member_number',$memberNumber)
            ->where('record_type',$type)
            ->where('is_active',true)
            ->orderBy('id')
            ->get()
            ->map(function($row){
                $payload=json_decode($row->payload ?? '{}',true);
                if(!is_array($payload))$payload=[];
                $payload['record_key']=$row->record_key ?? null;
                $payload['source_table']='nurselink_demo_profile_records';
                return $payload;
            })
            ->values()
            ->all();
    }
}

Route::get('/demo-profile-tabs-v6444/{memberNumber}', function(string $memberNumber){
    if($memberNumber!=='NL-2026-000001'){
        return response()->json(['message'=>'Demo dataset not available'],404);
    }

    $types=['certificates','portfolio','experience','licenses','education','documents'];
    $data=['member_number'=>$memberNumber];

    foreach($types as $type){
        $data[$type]=nl6444_demo_records($memberNumber,$type);
    }

    return response()->json([
        'data'=>$data,
        'meta'=>[
            'source'=>'database',
            'table'=>'nurselink_demo_profile_records',
            'demo'=>true,
            'version'=>'64.4.4',
        ]
    ])->header('Cache-Control','no-store, no-cache, must-revalidate, max-age=0');
});
