<?php
namespace App\Http\Controllers\Api\Admin\Member;
use App\Http\Controllers\Controller; use App\Models\Member; use App\Services\Credentials\CredentialHealthService; use Illuminate\Http\JsonResponse;
class MemberCredentialController extends Controller
{
    public function show(Member $member,CredentialHealthService $health):JsonResponse{$member->load(['credentials.primaryDocument','documents','professionalDevelopment.evidenceDocument']);return response()->json(['data'=>['member'=>$member,'health'=>$health->for($member)]]);}
}
