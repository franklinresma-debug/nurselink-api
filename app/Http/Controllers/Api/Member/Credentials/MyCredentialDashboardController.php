<?php
namespace App\Http\Controllers\Api\Member\Credentials;
use App\Http\Controllers\Controller; use App\Models\Member; use App\Services\Credentials\CredentialHealthService; use App\Services\Credentials\CredentialStatusService; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class MyCredentialDashboardController extends Controller
{
    public function __invoke(Request $request,CredentialHealthService $health,CredentialStatusService $statuses):JsonResponse
    {
        $member=Member::query()->where('user_id',$request->user()->id)->firstOrFail();
        $member->credentials()->get()->each(fn($c)=>$statuses->refresh($c));
        $member->load(['credentials'=>fn($q)=>$q->with('primaryDocument')->orderByRaw('expires_on is null')->orderBy('expires_on'),'documents'=>fn($q)=>$q->latest(),'professionalDevelopment'=>fn($q)=>$q->orderByDesc('completed_on')]);
        return response()->json(['data'=>['health'=>$health->for($member),'credentials'=>$member->credentials,'documents'=>$member->documents,'professional_development'=>$member->professionalDevelopment]]);
    }
}
