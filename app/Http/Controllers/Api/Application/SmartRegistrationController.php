<?php
namespace App\Http\Controllers\Api\Application;
use App\Http\Controllers\Controller;
use App\Http\Requests\SmartRegistration\ConfirmFactRequest;
use App\Http\Requests\SmartRegistration\UploadDocumentRequest;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ExtractedFact;
use App\Services\SmartRegistration\DocumentStorageService;
use App\Services\SmartRegistration\ExtractionService;
use App\Services\SmartRegistration\FactReviewService;
use App\Services\SmartRegistration\MissingFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class SmartRegistrationController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $application = $request->user()->application()->with(['documents','extractedFacts','dataReviews'])->firstOrFail();
        return response()->json(['application'=>$application]);
    }
    public function upload(UploadDocumentRequest $request, Application $application, DocumentStorageService $storage): JsonResponse
    {
        abort_unless($application->user_id === $request->user()->id,403);
        abort_if(in_array($application->status,['approved','rejected'],true),409,'Application is closed.');
        $document = $storage->store($application,$request->user(),$request->file('file'),$request->string('category'));
        return response()->json(['document'=>$document],201);
    }
    public function extract(Request $request, ApplicationDocument $document, ExtractionService $extraction): JsonResponse
    {
        abort_unless($document->application->user_id === $request->user()->id,403);
        abort_unless($document->malware_scan_status === 'clean', 423, 'Document security scan must complete before extraction.');
        $job = $extraction->run($document);
        return response()->json(['job'=>$job,'document'=>$document->fresh()]);
    }
    public function confirmFact(ConfirmFactRequest $request, ExtractedFact $fact, FactReviewService $review, MissingFieldService $missingFields): JsonResponse
    {
        $application = $fact->application;
        $updated = $review->confirm($fact,$application,$request->user(),$request->input('value'));
        $missing = $missingFields->refresh($application->fresh());
        return response()->json(['fact'=>$updated,'missing_count'=>count($missing),'application'=>$application->fresh()]);
    }
    public function rejectFact(Request $request, ExtractedFact $fact, FactReviewService $review): JsonResponse
    {
        return response()->json(['fact'=>$review->reject($fact,$fact->application,$request->user())]);
    }
    public function refreshMissing(Request $request, Application $application, MissingFieldService $service): JsonResponse
    {
        abort_unless($application->user_id === $request->user()->id,403);
        $missing = $service->refresh($application);
        return response()->json(['missing'=>$missing,'application'=>$application->fresh()]);
    }
}
