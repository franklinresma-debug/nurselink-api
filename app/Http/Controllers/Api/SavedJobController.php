<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SavedJobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ids = DB::table('nurselink_saved_jobs')
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('id')
            ->pluck('job_opportunity_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        return response()->json(['data' => $ids]);
    }

    public function store(Request $request, int $jobId): JsonResponse
    {
        $exists = DB::table('nurselink_job_opportunities')
            ->where('id', $jobId)
            ->where('status', 'active')
            ->exists();

        abort_unless($exists, 404);

        DB::table('nurselink_saved_jobs')->updateOrInsert(
            [
                'user_id' => $request->user()->getKey(),
                'job_opportunity_id' => $jobId,
            ],
            [
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json(['message' => 'Job saved.']);
    }

    public function destroy(Request $request, int $jobId): JsonResponse
    {
        DB::table('nurselink_saved_jobs')
            ->where('user_id', $request->user()->getKey())
            ->where('job_opportunity_id', $jobId)
            ->delete();

        return response()->json(['message' => 'Saved job removed.']);
    }
}
