<?php
namespace App\Http\Controllers\Api\Admin\Application;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ExtractedFact;
use App\Services\SmartRegistration\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class SmartRegistrationReviewController extends Controller
{
    public function show(Application $application): JsonResponse
    {
        return response()->json(['application'=>$application->load(['documents','extractedFacts.document','dataReviews','user'])]);
    }
    public function verifyFact(Request $request, ExtractedFact $fact, VerificationService $service): JsonResponse
    {
        $data = $request->validate(['status'=>['required','string','in:verified,unable_to_verify,discrepancy'],'note'=>['nullable','string','max:4000']]);
        return response()->json(['fact'=>$service->verify($fact,$request->user(),$data['status'],$data['note']??null)]);
    }
}
