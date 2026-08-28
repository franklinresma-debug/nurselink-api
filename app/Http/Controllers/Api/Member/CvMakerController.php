<?php
namespace App\Http\Controllers\Api\Member;
use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class CvMakerController extends Controller {
 public function show(Request $request): JsonResponse { $member=$this->member($request); $member->load(['profile','portfolioSummary','education'=>fn($q)=>$q->orderBy('completed_on'),'employment'=>fn($q)=>$q->orderBy('started_on'),'competencies','languages','credentials']); return response()->json(['data'=>['member'=>$member,'settings'=>json_decode((string)($member->portfolioSummary?->cv_maker_settings??'{}'),true)?:[]]]); }
 public function save(Request $request): JsonResponse { $data=$request->validate(['include_photo'=>['boolean'],'sections'=>['array'],'sections.*'=>['string','in:summary,experience,education,credentials,competencies,languages']]); $member=$this->member($request); $summary=$member->portfolioSummary()->updateOrCreate(['member_id'=>$member->id],['cv_maker_settings'=>json_encode(['include_photo'=>(bool)($data['include_photo']??true),'sections'=>array_values($data['sections']??[])])]); return response()->json(['message'=>'CV display settings saved.','data'=>['settings'=>json_decode($summary->cv_maker_settings,true)]]); }
 private function member(Request $request): Member { return Member::where('user_id',$request->user()->id)->firstOrFail(); }
}
