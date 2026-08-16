<?php

namespace App\Http\Controllers\Api\Application;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\ApplicationLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationActionController extends Controller
{
    public function ready(Request $request, Application $application, ApplicationLifecycleService $flow): JsonResponse
    {
        $this->own($request, $application);
        if ($application->progress_percent < 60) return response()->json(['message' => 'Complete the required profile information first.'], 422);
        return response()->json(['data' => $flow->transition($application, $request->user(), 'ready_to_submit')]);
    }

    public function submit(Request $request, Application $application, ApplicationLifecycleService $flow): JsonResponse
    {
        $this->own($request, $application);
        return response()->json(['data' => $flow->transition($application, $request->user(), 'submitted')]);
    }

    public function resubmit(Request $request, Application $application, ApplicationLifecycleService $flow): JsonResponse
    {
        $this->own($request, $application);
        return response()->json(['data' => $flow->transition($application, $request->user(), 'resubmitted')]);
    }

    private function own(Request $request, Application $application): void
    {
        abort_unless($application->user_id === $request->user()->id, 403);
    }
}
