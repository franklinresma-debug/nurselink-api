<?php
namespace App\Services\SmartRegistration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-through bridge preserves applicant confirmation separately from officer verification. */
class ProfileEvidenceBridge
{
    public function records(string $userId,string $source):Collection
    {
        $group=match($source){'employment'=>'employment','education'=>'education','credential'=>'credentials','language'=>'languages','competency'=>'skills',default=>null};
        if(!$group) return collect();
        $profile=DB::table('nurselink_smart_registration_profiles')->where('user_id',$userId)->first();
        $rows=json_decode($profile->structured_data??'[]',true)[$group]??[];
        $docs=DB::table('nurselink_smart_registration_documents')->where('user_id',$userId)->where('is_current',true)->where('security_status','clean')->get()->keyBy('id');
        return collect($rows)->filter(fn($r)=>($r['review_status']??'')==='applicant_confirmed')->map(function($r)use($docs,$source){
            $doc=$docs->get($r['source_document_id']??0);
            $state=$doc?'document_supported':'self_declared';
            $r['id']='smart:'.hash('sha256',json_encode([$source,$r]));$r['status']=$state;$r['evidence_state']=$state;
            if($source==='credential') {
                $r['verification_status']=$state;
                $r['credential_status']=!empty($r['expires_on']) && $r['expires_on']<date('Y-m-d')?'expired':'active';
                if($r['credential_status']==='expired') $r['verification_status']='expired';
                $r['category']=$r['category']??'other';
            }
            $r['source_document_id']=$doc?->id;$r['updated_at']=$r['confirmed_at']??null;
            $m=new class extends Model {protected $guarded=[];public $timestamps=false;};
            $m->setRawAttributes($r);return $m;
        })->values();
    }

    public function experienceYears(Collection $rows,string $startKey='started_on',string $endKey='ended_on'):float
    {
        $intervals=[];$today=date('Y-m-d');
        foreach($rows as $r) {
            $start=$r->$startKey;$end=$r->$endKey;
            if(!$start || !preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$start)) continue;
            if(!$end && !($r->is_current??false)) continue;
            $end=$end?:$today;
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$end)) continue;
            $a=strtotime($start);$b=min(strtotime($end),strtotime($today));
            if($a!==false && $b!==false && $b>=$a) $intervals[]=[$a,$b];
        }
        usort($intervals,fn($a,$b)=>$a[0]<=>$b[0]);$merged=[];
        foreach($intervals as $i) { $last=count($merged)-1;
            if($last>=0 && $i[0]<=$merged[$last][1]) $merged[$last][1]=max($merged[$last][1],$i[1]);else $merged[]=$i;
        }
        return round(array_sum(array_map(fn($i)=>($i[1]-$i[0])/86400,$merged))/365.25,2);
    }
}
