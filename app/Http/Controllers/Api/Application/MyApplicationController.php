<?php

namespace App\Http\Controllers\Api\Application;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\IdentifierService;
use App\Services\ApplicationLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyApplicationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $application = Application::query()->where('user_id', $request->user()->id)->with('events')->first();
        return response()->json(['data' => $application]);
    }

    public function store(Request $request, IdentifierService $ids, ApplicationLifecycleService $flow): JsonResponse
    {
        $existing = Application::query()->where('user_id', $request->user()->id)->first();
        if ($existing) return response()->json(['data' => $existing], 200);

        $application = Application::query()->create([
            'application_no' => $ids->next('application', 'NLA'),
            'user_id' => $request->user()->id,
            'status' => 'draft',
            'progress_percent' => 0,
            'profile_data' => [],
        ]);
        $application = $flow->transition($application, $request->user(), 'in_progress');
        return response()->json(['data' => $application], 201);
    }

    public function updateProfile(Request $request, Application $application): JsonResponse
    {
        abort_unless($application->user_id === $request->user()->id, 403);
        abort_if(in_array($application->status, ['submitted','under_review','approved','rejected'], true), 409, 'Application is locked for editing.');

        $data = $request->validate([
            'first_name' => ['required','string','max:100'],
            'middle_name' => ['nullable','string','max:100'],
            'last_name' => ['required','string','max:100'],
            'suffix' => ['nullable','string','max:30'],
            'date_of_birth' => ['nullable','date','before:today'],
            'nationality' => ['nullable','string','max:80'],
            'mobile_phone' => ['nullable','string','max:40'],
            'city' => ['nullable','string','max:120'],
            'region' => ['nullable','string','max:120'],
            'country' => ['nullable','string','max:120'],
            'professional_title' => ['nullable','string','max:160'],
            'current_position' => ['nullable','string','max:180'],
            'current_employer' => ['nullable','string','max:220'],
            'years_experience' => ['nullable','integer','min:0','max:70'],
            'progress_percent' => ['nullable','integer','min:0','max:100'],
        ]);

        $application->profile_data = collect($data)->except('progress_percent')->all();
        $application->progress_percent = $data['progress_percent'] ?? max($application->progress_percent, 60);
        $application->lock_version++;
        $application->save();
        return response()->json(['data' => $application]);
    }
}
