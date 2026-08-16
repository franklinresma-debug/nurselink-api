<?php
namespace App\Http\Controllers\Api\Member\Portfolio;
use App\Http\Controllers\Controller; use App\Http\Requests\Portfolio\UpsertEmploymentRequest; use App\Models\Member; use App\Models\PortfolioEmployment; use App\Services\Portfolio\PortfolioCompletionService; use App\Services\Portfolio\PortfolioTimelineService; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class EmploymentController extends Controller
{
    public function store(UpsertEmploymentRequest $request, PortfolioCompletionService $completion, PortfolioTimelineService $timeline):JsonResponse { $m=$this->member($request); $row=$m->employment()->create($request->validated()+['status'=>'self_declared']); $completion->refresh($m); $timeline->rebuild($m); return response()->json(['data'=>$row],201); }
    public function update(UpsertEmploymentRequest $request, PortfolioEmployment $employment, PortfolioCompletionService $completion, PortfolioTimelineService $timeline):JsonResponse { $m=$this->member($request); abort_unless($employment->member_id===$m->id,403); $employment->update($request->validated()); $completion->refresh($m); $timeline->rebuild($m); return response()->json(['data'=>$employment->fresh()]); }
    public function destroy(Request $request, PortfolioEmployment $employment, PortfolioCompletionService $completion, PortfolioTimelineService $timeline):JsonResponse { $m=$this->member($request); abort_unless($employment->member_id===$m->id,403); $employment->delete(); $completion->refresh($m); $timeline->rebuild($m); return response()->json([],204); }
    private function member(Request $r):Member{return Member::query()->where('user_id',$r->user()->id)->firstOrFail();}
}
