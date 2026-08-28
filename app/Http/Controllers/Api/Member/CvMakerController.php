<?php
namespace App\Http\Controllers\Api\Member;
use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class CvMakerController extends Controller {
 public function show(Request $request): JsonResponse { $member=Member::where('user_id',$request->user()->id)->firstOrFail(); $member->load(['profile','portfolioSummary','education'=>fn($q)=>$q->orderBy('completed_on'),'employment'=>fn($q)=>$q->orderBy('started_on'),'competencies','languages','credentials']); return response()->json(['data'=>['member'=>$member,'settings'=>json_decode((string)($member->portfolioSummary?->cv_maker_settings??'{}'),true)?:[]]]); }
 public function save(Request $request): JsonResponse { $data=$request->validate(['professional_summary'=>['nullable','string','max:4000'],'include_photo'=>['boolean'],'sections'=>['array'],'sections.*'=>['string','in:summary,experience,education,credentials,competencies,languages']]); $member=Member::where('user_id',$request->user()->id)->firstOrFail(); $summary=$member->portfolioSummary()->firstOrCreate([]); $summary->cv_maker_settings=json_encode(['include_photo'=>(bool)($data['include_photo']??true),'sections'=>array_values($data['sections']??[])]); if(array_key_exists('professional_summary',$data)) $summary->professional_summary=$data['professional_summary']; $summary->save(); return response()->json(['data'=>['settings'=>json_decode($summary->cv_maker_settings,true),'professional_summary'=>$summary->professional_summary]]); }
}
