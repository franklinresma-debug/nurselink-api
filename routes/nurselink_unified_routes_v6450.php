<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

if (!function_exists('nl6450_rows')) {
    function nl6450_rows(string $memberNumber,string $type): array
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
            if(is_array($payload)) $out[]=$payload;
        }
        return $out;
    }
}

Route::get('/member-profile-unified-v6450/{memberNumber}', function(string $memberNumber){
    if($memberNumber!=='NL-2026-000001'){
        return response()->json(['message'=>'Demo dataset not available'],404);
    }

    $types=['certificates','portfolio','experience','licenses','education','documents'];
    $data=['member_number'=>$memberNumber];
    $counts=[];

    foreach($types as $type){
        $data[$type]=nl6450_rows($memberNumber,$type);
        $counts[$type]=count($data[$type]);
    }

    return response()->json([
        'data'=>$data,
        'counts'=>$counts,
        'meta'=>[
            'source'=>'live_database',
            'table'=>'nurselink_demo_profile_records',
            'version'=>'64.5.0',
            'generated_at'=>now()->toIso8601String()
        ]
    ])->header('Cache-Control','no-store, no-cache, must-revalidate, max-age=0');
});
