<?php
namespace App\Http\Controllers\Api\Member\Portfolio;
use App\Http\Controllers\Controller; use App\Http\Requests\Portfolio\UpsertEducationRequest; use App\Models\Member; use App\Models\PortfolioEducation; use App\Services\Portfolio\PortfolioCompletionService; use App\Services\Portfolio\PortfolioTimelineService; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class EducationController extends Controller
{
    public function store(UpsertEducationRequest $request, PortfolioCompletionService $completion, PortfolioTimelineService $timeline):JsonResponse { $m=$this->member($request); $row=$m->education()->create($request->validated()+['status'=>'self_declared']); $completion->refresh($m); $timeline->rebuild($m); return response()->json(['data'=>$row],201); }
    public function update(UpsertEducationRequest $request, PortfolioEducation $education, PortfolioCompletionService $completion, PortfolioTimelineService $timeline):JsonResponse { $m=$this->member($request); abort_unless($education->member_id===$m->id,403); $education->update($request->validated()); $completion->refresh($m); $timeline->rebuild($m); return response()->json(['data'=>$education->fresh()]); }
    public function destroy(Request $request, PortfolioEducation $education, PortfolioCompletionService $completion, PortfolioTimelineService $timeline):JsonResponse { $m=$this->member($request); abort_unless($education->member_id===$m->id,403); $education->delete(); $completion->refresh($m); $timeline->rebuild($m); return response()->json([],204); }
    private function member(Request $r):Member{return Member::query()->where('user_id',$r->user()->id)->firstOrFail();}
}
