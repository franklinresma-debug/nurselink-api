<?php

namespace App\Http\Controllers\Api\Admin\Application;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\ApplicationLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = trim((string) $request->query('status', ''));
        $search = trim((string) $request->query('search', ''));
        $items = Application::query()->with('user:id,name,email','reviewer:id,name')
            ->when($status !== '', fn($q) => $q->where('status', $status))
            ->when($search !== '', fn($q) => $q->where(fn($x) => $x->where('application_no','like',"%{$search}%")->orWhereHas('user', fn($u) => $u->where('name','like',"%{$search}%")->orWhere('email','like',"%{$search}%"))))
            ->latest()->paginate(25);
        return response()->json($items);
    }

    public function show(Application $application): JsonResponse
    {
        return response()->json(['data' => $application->load('user:id,name,email','reviewer:id,name','events.actor:id,name','member.profile')]);
    }

    public function start(Request $request, Application $application, ApplicationLifecycleService $flow): JsonResponse
    {
        $application->current_reviewer_user_id = $request->user()->id; $application->save();
        return response()->json(['data' => $flow->transition($application, $request->user(), 'under_review')]);
    }

    public function returnForInformation(Request $request, Application $application, ApplicationLifecycleService $flow): JsonResponse
    {
        $data = $request->validate(['reason' => ['required','string','min:5','max:4000']]);
        return response()->json(['data' => $flow->transition($application, $request->user(), 'returned_for_information', $data['reason'])]);
    }

    public function approve(Request $request, Application $application, ApplicationLifecycleService $flow): JsonResponse
    {
        return response()->json(['data' => $flow->approve($application, $request->user())]);
    }

    public function reject(Request $request, Application $application, ApplicationLifecycleService $flow): JsonResponse
    {
        $data = $request->validate(['reason' => ['required','string','min:5','max:4000']]);
        return response()->json(['data' => $flow->transition($application, $request->user(), 'rejected', $data['reason'])]);
    }
}
