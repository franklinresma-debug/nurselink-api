<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

if(!function_exists('nl6446_demo_rows')){
    function nl6446_demo_rows(string $memberNumber,string $type): array
    {
        if(!Schema::hasTable('nurselink_demo_profile_records'))return [];

        $rows=DB::table('nurselink_demo_profile_records')
            ->where('member_number',$memberNumber)
            ->where('record_type',$type)
            ->where('is_active',1)
            ->orderBy('id')
            ->get();

        $out=[];
        foreach($rows as $row){
            $payload=json_decode($row->payload ?? '{}',true);
            if(is_array($payload))$out[]=$payload;
        }
        return $out;
    }
}

Route::middleware('auth:sanctum')->get('/member-profile-tabs-v6441', function(Request $request){
    $requested=trim((string)$request->query('member_number',''));

    // Explicitly support the documented fictional demo profile.
    if($requested==='NL-2026-000001'){
        $types=['certificates','portfolio','experience','licenses','education','documents'];
        $data=['identity'=>['member_number'=>$requested]];

        foreach($types as $type){
            $data[$type]=nl6446_demo_rows($requested,$type);
        }

        return response()->json([
            'data'=>$data,
            'meta'=>[
                'source'=>'nurselink_demo_profile_records',
                'member_number'=>$requested,
                'version'=>'64.4.6'
            ]
        ])->header('Cache-Control','no-store, no-cache, must-revalidate, max-age=0');
    }

    // For non-demo users, return empty arrays rather than exposing another member's demo records.
    return response()->json([
        'data'=>[
            'identity'=>['member_number'=>$requested ?: null],
            'certificates'=>[],
            'portfolio'=>[],
            'experience'=>[],
            'licenses'=>[],
            'education'=>[],
            'documents'=>[],
        ],
        'meta'=>['source'=>'no-demo-fallback','version'=>'64.4.6']
    ]);
});
