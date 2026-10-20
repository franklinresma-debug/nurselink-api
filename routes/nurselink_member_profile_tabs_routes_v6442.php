<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

if (!function_exists('nl6442_member_identity')) {
    function nl6442_member_identity(Request $request): array
    {
        $u=$request->user();
        $out=['user_id'=>$u->id ?? null,'member_id'=>null,'membership_id'=>null,'member_number'=>null,'email'=>$u->email ?? null];

        foreach (['nurselink_memberships','memberships'] as $table) {
            if (!Schema::hasTable($table)) continue;
            $cols=Schema::getColumnListing($table);
            $m=null;

            if (($u->id ?? null)!==null && in_array('user_id',$cols,true)) {
                try {$m=DB::table($table)->where('user_id',$u->id)->first();} catch(Throwable $e){}
            }
            if (!$m && ($u->email ?? null) && in_array('email',$cols,true)) {
                try {$m=DB::table($table)->where('email',$u->email)->first();} catch(Throwable $e){}
            }
            if (!$m) continue;

            $out['member_id']=$m->member_id ?? ($m->id ?? null);
            $out['membership_id']=$m->id ?? null;
            $out['member_number']=$m->member_number ?? ($m->member_no ?? ($m->membership_number ?? null));
            break;
        }

        return $out;
    }
}

if (!function_exists('nl6442_demo_records')) {
    function nl6442_demo_records(?string $memberNumber,string $type): array
    {
        if (!$memberNumber || !Schema::hasTable('nurselink_demo_profile_records')) return [];

        $rows=DB::table('nurselink_demo_profile_records')
            ->where('member_number',$memberNumber)
            ->where('record_type',$type)
            ->where('is_active',true)
            ->orderBy('id')
            ->get();

        $out=[];
        foreach ($rows as $row) {
            $payload=json_decode($row->payload ?? '{}',true);
            if (!is_array($payload)) continue;
            $payload['source_table']='nurselink_demo_profile_records';
            $payload['record_key']=$row->record_key ?? null;
            $out[]=$payload;
        }
        return $out;
    }
}

if (!function_exists('nl6442_candidates')) {
    function nl6442_candidates(string $type): array
    {
        return match($type) {
            'certificates'=>['nurselink_certificates','certificates','member_certificates','nurselink_credentials','credentials','professional_credentials'],
            'portfolio'=>['nurselink_portfolio_items','portfolio_items','nurselink_portfolios','portfolios'],
            'experience'=>['nurselink_employment_history','employment_history','nurselink_work_experience','work_experience','professional_experience','experiences'],
            'licenses'=>['nurselink_licenses','licenses','professional_licenses','nurselink_credentials','credentials'],
            'education'=>['nurselink_education','education','member_education','educational_background','professional_education'],
            'documents'=>['nurselink_documents','documents','member_documents','uploaded_documents','professional_documents'],
            default=>[],
        };
    }
}

if (!function_exists('nl6442_first_col')) {
    function nl6442_first_col(array $cols,array $names): ?string
    {
        foreach($names as $n)if(in_array($n,$cols,true))return $n;
        return null;
    }
}

if (!function_exists('nl6442_member_query')) {
    function nl6442_member_query(string $table,array $identity)
    {
        $cols=Schema::getColumnListing($table);
        $filters=[];

        $defs=[
            ['member_number',['member_number','member_no','membership_number']],
            ['membership_id',['membership_id']],
            ['member_id',['member_id']],
            ['user_id',['user_id']],
            ['email',['email','member_email','user_email']],
        ];

        foreach($defs as [$key,$names]){
            $val=$identity[$key] ?? null;
            if($val===null||$val==='')continue;
            $col=nl6442_first_col($cols,$names);
            if($col)$filters[]=[$col,$val];
        }

        if(!$filters)return [null,$cols];

        $q=DB::table($table)->where(function($sub)use($filters){
            foreach($filters as $i=>$f){
                [$col,$val]=$f;
                $i===0?$sub->where($col,$val):$sub->orWhere($col,$val);
            }
        });

        $order=nl6442_first_col($cols,['updated_at','created_at','id']);
        if($order)$q->orderByDesc($order);

        return [$q,$cols];
    }
}

if (!function_exists('nl6442_v')) {
    function nl6442_v($r,array $names,$default=null)
    {
        foreach($names as $n)if(isset($r->{$n})&&$r->{$n}!==''&&$r->{$n}!==null)return $r->{$n};
        return $default;
    }
}

if (!function_exists('nl6442_b')) {
    function nl6442_b($v): bool
    {
        if(is_bool($v))return $v;
        return in_array(strtolower(trim((string)$v)),['1','true','yes','active','verified','approved','published','current'],true);
    }
}

if (!function_exists('nl6442_normalize')) {
    function nl6442_normalize(string $type,$r,string $table): array
    {
        $base=['id'=>nl6442_v($r,['id','uuid','record_id']),'source_table'=>$table];

        return match($type) {
            'certificates'=>$base+[
                'title'=>nl6442_v($r,['certificate_name','certification_name','credential_name','title','name'],'Certificate'),
                'issuer'=>nl6442_v($r,['issuer','issuing_organization','issuing_body','authority','provider'],'—'),
                'description'=>nl6442_v($r,['description','details','notes','summary'],''),
                'issue_date'=>nl6442_v($r,['issue_date','issued_at','date_issued','start_date']),
                'expiry_date'=>nl6442_v($r,['expiry_date','expires_at','expiration_date','valid_until','end_date']),
                'status'=>nl6442_v($r,['status','verification_status','state'],'Recorded'),
                'verified'=>nl6442_b(nl6442_v($r,['verified','is_verified','verification_status'],false)),
                'credential_number'=>nl6442_v($r,['credential_number','certificate_number','license_number','registration_number']),
                'file_url'=>nl6442_v($r,['file_url','document_url','attachment_url','url']),
            ],
            'portfolio'=>$base+[
                'title'=>nl6442_v($r,['title','project_title','name'],'Portfolio Item'),
                'description'=>nl6442_v($r,['description','summary','details','content'],''),
                'type'=>nl6442_v($r,['type','item_type','category'],'Portfolio'),
                'date'=>nl6442_v($r,['date','project_date','published_at','completed_at','created_at']),
                'status'=>nl6442_v($r,['status','publication_status','state'],'Recorded'),
                'published'=>nl6442_b(nl6442_v($r,['published','is_published','status'],false)),
                'url'=>nl6442_v($r,['url','link','project_url','external_url']),
                'image_url'=>nl6442_v($r,['image_url','thumbnail_url','cover_url','photo_url']),
            ],
            'experience'=>$base+[
                'title'=>nl6442_v($r,['job_title','position','role','title'],'Professional Experience'),
                'organization'=>nl6442_v($r,['employer','organization','hospital','company','facility'],'—'),
                'location'=>nl6442_v($r,['location','city','country','work_location'],''),
                'description'=>nl6442_v($r,['description','duties','responsibilities','summary','notes'],''),
                'start_date'=>nl6442_v($r,['start_date','date_from','from_date','started_at']),
                'end_date'=>nl6442_v($r,['end_date','date_to','to_date','ended_at']),
                'current'=>nl6442_b(nl6442_v($r,['current','is_current','currently_employed'],false)),
            ],
            'licenses'=>$base+[
                'title'=>nl6442_v($r,['license_name','credential_name','title','name'],'Professional License'),
                'authority'=>nl6442_v($r,['regulatory_authority','authority','issuer','issuing_body'],'—'),
                'number'=>nl6442_v($r,['license_number','registration_number','credential_number','number']),
                'issue_date'=>nl6442_v($r,['issue_date','issued_at','date_issued']),
                'expiry_date'=>nl6442_v($r,['expiry_date','expires_at','valid_until','expiration_date']),
                'status'=>nl6442_v($r,['status','verification_status','state'],'Recorded'),
                'verified'=>nl6442_b(nl6442_v($r,['verified','is_verified','verification_status'],false)),
                'country'=>nl6442_v($r,['country','jurisdiction','country_name'],''),
            ],
            'education'=>$base+[
                'title'=>nl6442_v($r,['degree','qualification','program','course','title'],'Education'),
                'institution'=>nl6442_v($r,['institution','school','university','college','provider'],'—'),
                'field'=>nl6442_v($r,['field_of_study','major','specialization','field'],''),
                'start_date'=>nl6442_v($r,['start_date','date_from','from_date']),
                'end_date'=>nl6442_v($r,['end_date','graduation_date','date_to','to_date']),
                'description'=>nl6442_v($r,['description','notes','details'],''),
                'status'=>nl6442_v($r,['status','verification_status'],'Recorded'),
            ],
            'documents'=>$base+[
                'title'=>nl6442_v($r,['title','document_name','file_name','name'],'Document'),
                'type'=>nl6442_v($r,['document_type','type','category'],'Document'),
                'description'=>nl6442_v($r,['description','notes','details'],''),
                'uploaded_at'=>nl6442_v($r,['uploaded_at','created_at','date_uploaded']),
                'status'=>nl6442_v($r,['status','verification_status'],'Recorded'),
                'url'=>nl6442_v($r,['url','file_url','document_url','path']),
            ],
            default=>$base,
        };
    }
}

if (!function_exists('nl6442_live')) {
    function nl6442_live(string $type,array $identity): array
    {
        $out=[];
        foreach(nl6442_candidates($type) as $table){
            if(!Schema::hasTable($table))continue;
            try{
                [$q,$cols]=nl6442_member_query($table,$identity);
                if(!$q)continue;
                foreach($q->limit(100)->get() as $r)$out[]=nl6442_normalize($type,$r,$table);
            }catch(Throwable $e){continue;}
        }
        return $out;
    }
}

Route::middleware('auth:sanctum')->get('/member-profile-tabs-v6442', function(Request $request){
    $identity=nl6442_member_identity($request);

    // Demo account can be resolved from the authenticated membership. As a safety
    // fallback for the dedicated demo environment, use the documented demo member.
    $memberNumber=$identity['member_number'] ?: null;

    $types=['certificates','portfolio','experience','licenses','education','documents'];
    $data=['identity'=>$identity];

    foreach($types as $type){
        $live=nl6442_live($type,$identity);
        if($live){
            $data[$type]=$live;
            continue;
        }

        $demo=[];
        if($memberNumber==='NL-2026-000001'){
            $demo=nl6442_demo_records($memberNumber,$type);
        }
        $data[$type]=$demo;
    }

    return response()->json(['data'=>$data]);
});
