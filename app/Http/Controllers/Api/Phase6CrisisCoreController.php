<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Phase6CrisisCoreController extends Controller
{
    public function memberOverview(Request $request): JsonResponse {
        $uid=$request->user()->id;
        return response()->json(['data'=>[
            'last_location'=>DB::table('nurselink_welfare_locations')->where('user_id',$uid)->latest('captured_at')->first(),
            'alerts'=>DB::table('nurselink_crisis_alerts')->where('user_id',$uid)->latest()->limit(20)->get(),
            'crisis_updates'=>DB::table('nurselink_crisis_updates')->where('status','published')->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))->latest('published_at')->limit(20)->get(),
            'safe_locations'=>DB::table('nurselink_safe_locations')->where('status','active')->orderBy('country')->orderBy('city')->limit(100)->get(),
            'privacy'=>['location_is_opt_in'=>true,'location_retention_hours'=>72,'panic_location_is_optional'=>true],
        ]]);
    }

    public function storeLocation(Request $request): JsonResponse {
        $d=$request->validate([
            'latitude'=>['required','numeric','between:-90,90'],
            'longitude'=>['required','numeric','between:-180,180'],
            'accuracy_meters'=>['nullable','numeric','min:0','max:100000'],
            'consent_confirmed'=>['required','accepted'],
        ]);
        $id=DB::table('nurselink_welfare_locations')->insertGetId([
            'user_id'=>$request->user()->id,'latitude'=>$d['latitude'],'longitude'=>$d['longitude'],
            'accuracy_meters'=>$d['accuracy_meters']??null,'consent_confirmed'=>true,
            'captured_at'=>now(),'expires_at'=>now()->addHours(72),'created_at'=>now(),'updated_at'=>now()
        ]);
        return response()->json(['message'=>'Location shared for welfare support.','data'=>DB::table('nurselink_welfare_locations')->where('id',$id)->first()],201);
    }

    public function panic(Request $request): JsonResponse {
        $d=$request->validate([
            'message'=>['nullable','string','max:2000'],
            'latitude'=>['nullable','numeric','between:-90,90'],
            'longitude'=>['nullable','numeric','between:-180,180'],
            'accuracy_meters'=>['nullable','numeric','min:0','max:100000'],
        ]);
        $id=DB::table('nurselink_crisis_alerts')->insertGetId([
            'user_id'=>$request->user()->id,'alert_type'=>'panic','severity'=>'critical','status'=>'open',
            'message'=>$d['message']??'Member requested urgent welfare assistance.',
            'latitude'=>$d['latitude']??null,'longitude'=>$d['longitude']??null,'accuracy_meters'=>$d['accuracy_meters']??null,
            'escalation_level'=>1,'created_at'=>now(),'updated_at'=>now()
        ]);
        DB::table('nurselink_crisis_escalations')->insert([
            'alert_id'=>$id,'level'=>1,'status'=>'open',
            'notes'=>'Automatic Level 1 escalation created from member panic alert.',
            'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()
        ]);
        return response()->json(['message'=>'Emergency alert sent to the NurseLink crisis queue.','data'=>DB::table('nurselink_crisis_alerts')->where('id',$id)->first()],201);
    }

    public function memberAlerts(Request $request): JsonResponse {
        return response()->json(['data'=>DB::table('nurselink_crisis_alerts')->where('user_id',$request->user()->id)->latest()->limit(100)->get()]);
    }

    public function safeLocations(Request $request): JsonResponse {
        $q=DB::table('nurselink_safe_locations')->where('status','active');
        if($request->filled('country')) $q->where('country',$request->string('country')->toString());
        return response()->json(['data'=>$q->orderBy('country')->orderBy('city')->get()]);
    }

    public function updates(): JsonResponse {
        return response()->json(['data'=>DB::table('nurselink_crisis_updates')->where('status','published')->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))->latest('published_at')->limit(100)->get()]);
    }

    public function adminOverview(): JsonResponse {
        return response()->json(['data'=>[
            'open_alerts'=>DB::table('nurselink_crisis_alerts')->where('status','open')->count(),
            'critical_alerts'=>DB::table('nurselink_crisis_alerts')->where('status','open')->where('severity','critical')->count(),
            'open_escalations'=>DB::table('nurselink_crisis_escalations')->where('status','open')->count(),
            'active_safe_locations'=>DB::table('nurselink_safe_locations')->where('status','active')->count(),
            'recent_alerts'=>DB::table('nurselink_crisis_alerts')->latest()->limit(100)->get(),
        ]]);
    }

    public function updateAlert(Request $request,int $alert): JsonResponse {
        $d=$request->validate([
            'status'=>['sometimes',Rule::in(['open','acknowledged','in_progress','resolved','closed'])],
            'assigned_to'=>['nullable','string','max:191'],
            'escalation_level'=>['sometimes','integer','between:1,5'],
        ]);
        if(($d['status']??null)==='acknowledged') $d['acknowledged_at']=now();
        if(in_array(($d['status']??''),['resolved','closed'],true)) $d['resolved_at']=now();
        $d['updated_at']=now();
        abort_unless(DB::table('nurselink_crisis_alerts')->where('id',$alert)->update($d),404);
        return response()->json(['message'=>'Crisis alert updated.','data'=>DB::table('nurselink_crisis_alerts')->where('id',$alert)->first()]);
    }

    public function escalate(Request $request,int $alert): JsonResponse {
        abort_unless(DB::table('nurselink_crisis_alerts')->where('id',$alert)->exists(),404);
        $d=$request->validate(['level'=>['required','integer','between:1,5'],'assigned_to'=>['nullable','string','max:191'],'notes'=>['nullable','string','max:5000']]);
        $id=DB::table('nurselink_crisis_escalations')->insertGetId([
            'alert_id'=>$alert,'level'=>$d['level'],'status'=>'open','assigned_to'=>$d['assigned_to']??null,
            'notes'=>$d['notes']??null,'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()
        ]);
        DB::table('nurselink_crisis_alerts')->where('id',$alert)->update([
            'escalation_level'=>$d['level'],'assigned_to'=>$d['assigned_to']??null,'status'=>'in_progress','updated_at'=>now()
        ]);
        return response()->json(['message'=>'Crisis alert escalated.','data'=>DB::table('nurselink_crisis_escalations')->where('id',$id)->first()],201);
    }

    public function storeUpdate(Request $request): JsonResponse {
        $d=$request->validate([
            'title'=>['required','string','max:220'],'body'=>['required','string','max:30000'],
            'severity'=>['nullable',Rule::in(['info','advisory','moderate','high','critical'])],
            'country'=>['nullable','string','max:120'],'region'=>['nullable','string','max:160'],'expires_at'=>['nullable','date']
        ]);
        $id=DB::table('nurselink_crisis_updates')->insertGetId([
            'title'=>$d['title'],'body'=>$d['body'],'severity'=>$d['severity']??'info','country'=>$d['country']??null,
            'region'=>$d['region']??null,'status'=>'published','published_at'=>now(),'expires_at'=>$d['expires_at']??null,
            'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()
        ]);
        return response()->json(['message'=>'Crisis update published.','data'=>DB::table('nurselink_crisis_updates')->where('id',$id)->first()],201);
    }

    public function storeSafeLocation(Request $request): JsonResponse {
        $d=$request->validate([
            'name'=>['required','string','max:220'],'location_type'=>['nullable','string','max:50'],
            'country'=>['nullable','string','max:120'],'city'=>['nullable','string','max:160'],'address'=>['nullable','string','max:500'],
            'latitude'=>['nullable','numeric','between:-90,90'],'longitude'=>['nullable','numeric','between:-180,180'],
            'contact'=>['nullable','string','max:220'],'instructions'=>['nullable','string','max:5000']
        ]);
        $id=DB::table('nurselink_safe_locations')->insertGetId(array_merge($d,['location_type'=>$d['location_type']??'safe_center','status'=>'active','created_at'=>now(),'updated_at'=>now()]));
        return response()->json(['message'=>'Safe location added.','data'=>DB::table('nurselink_safe_locations')->where('id',$id)->first()],201);
    }

    public function reportSummary(): JsonResponse {
        return response()->json(['data'=>[
            'alerts_total'=>DB::table('nurselink_crisis_alerts')->count(),
            'alerts_open'=>DB::table('nurselink_crisis_alerts')->where('status','open')->count(),
            'alerts_resolved'=>DB::table('nurselink_crisis_alerts')->whereIn('status',['resolved','closed'])->count(),
            'locations_shared_72h'=>DB::table('nurselink_welfare_locations')->where('captured_at','>=',now()->subHours(72))->count(),
            'active_safe_locations'=>DB::table('nurselink_safe_locations')->where('status','active')->count(),
            'published_updates'=>DB::table('nurselink_crisis_updates')->where('status','published')->count(),
            'open_escalations'=>DB::table('nurselink_crisis_escalations')->where('status','open')->count(),
        ]]);
    }

    public function reportCsv(): StreamedResponse {
        return response()->streamDownload(function(): void {
            $out=fopen('php://output','w');
            fputcsv($out,['id','user_id','alert_type','severity','status','message','latitude','longitude','assigned_to','escalation_level','created_at','resolved_at']);
            foreach(DB::table('nurselink_crisis_alerts')->orderBy('id')->cursor() as $r){
                fputcsv($out,[$r->id,$r->user_id,$r->alert_type,$r->severity,$r->status,$r->message,$r->latitude,$r->longitude,$r->assigned_to,$r->escalation_level,$r->created_at,$r->resolved_at]);
            }
            fclose($out);
        },'NurseLink-welfare-crisis-report.csv',['Content-Type'=>'text/csv']);
    }
}
