<?php
namespace App\Http\Controllers\Api\Admin\Member;
use App\Http\Controllers\Controller; use App\Models\Member; use Illuminate\Http\JsonResponse;
class MemberPortfolioController extends Controller
{
    public function show(Member $member):JsonResponse { $member->load(['user','profile','portfolioSummary','education','employment','specialties','competencies','technologySkills','languages','timelineEvents']); return response()->json(['data'=>$member]); }
}
