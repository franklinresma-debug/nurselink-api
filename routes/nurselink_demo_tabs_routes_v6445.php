<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/demo-tabs-v6445/{memberNumber}', function(string $memberNumber){
    if($memberNumber!=='NL-2026-000001'){
        return response()->json(['message'=>'Demo dataset not available'],404);
    }

    $types=['certificates','portfolio','experience','licenses','education','documents'];
    $data=['member_number'=>$memberNumber];

    if(!Schema::hasTable('nurselink_demo_profile_records')){
        foreach($types as $type)$data[$type]=[];
        return response()->json(['data'=>$data,'meta'=>['source'=>'missing_table','version'=>'64.4.5']]);
    }

    foreach($types as $type){
        $rows=DB::table('nurselink_demo_profile_records')
            ->where('member_number',$memberNumber)
            ->where('record_type',$type)
            ->where('is_active',true)
            ->orderBy('id')
            ->get();

        $data[$type]=$rows->map(function($row){
            $payload=json_decode($row->payload ?? '{}',true);
            return is_array($payload)?$payload:[];
        })->values()->all();
    }

    return response()->json([
        'data'=>$data,
        'meta'=>[
            'source'=>'nurselink_demo_profile_records',
            'version'=>'64.4.5',
            'generated_at'=>now()->toIso8601String(),
        ]
    ])->header('Cache-Control','no-store, no-cache, must-revalidate, max-age=0');
});
