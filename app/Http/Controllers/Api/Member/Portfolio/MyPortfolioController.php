<?php
namespace App\Http\Controllers\Api\Member\Portfolio;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\Portfolio\PortfolioCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class MyPortfolioController extends Controller
{
    public function show(Request $request, PortfolioCompletionService $completion): JsonResponse
    {
        $member=Member::query()->where('user_id',$request->user()->id)->firstOrFail();
        $completion->refresh($member);
        $member->load(['profile','portfolioSummary','education'=>fn($q)=>$q->orderByDesc('completed_on'),'employment'=>fn($q)=>$q->orderByDesc('is_current')->orderByDesc('started_on'),'specialties','competencies','technologySkills','languages','timelineEvents'=>fn($q)=>$q->orderByDesc('occurred_on')]);
        return response()->json(['data'=>$member]);
    }
    public function updateSummary(Request $request, PortfolioCompletionService $completion): JsonResponse
    {
        $data=$request->validate(['professional_headline'=>['nullable','string','max:180'],'professional_summary'=>['nullable','string','max:4000'],'primary_specialty'=>['nullable','string','max:150'],'years_experience'=>['nullable','integer','min:0','max:80'],'current_country'=>['nullable','string','size:2'],'available_for_opportunities'=>['boolean']]);
        $member=Member::query()->where('user_id',$request->user()->id)->firstOrFail();
        $summary=$member->portfolioSummary()->updateOrCreate(['member_id'=>$member->id],$data);
        $completion->refresh($member);
        return response()->json(['data'=>$summary->fresh()]);
    }
}
