<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JobApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('nurselink_job_applications as a')
            ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
            ->where('a.user_id', $request->user()->getKey())
            ->orderByDesc('a.submitted_at')
            ->orderByDesc('a.id')
            ->get([
                'a.id',
                'a.job_opportunity_id',
                'a.status',
                'a.cover_note',
                'a.submitted_at',
                'a.withdrawn_at',
                'a.created_at',
                'a.updated_at',
                'j.reference_code',
                'j.title',
                'j.employer_name',
                'j.country',
                'j.city',
                'j.specialty',
                'j.employment_type',
                'j.apply_url',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'job_opportunity_id' => (int) $row->job_opportunity_id,
                'status' => $row->status,
                'cover_note' => $row->cover_note,
                'submitted_at' => $row->submitted_at,
                'withdrawn_at' => $row->withdrawn_at,
                'reference_code' => $row->reference_code,
                'title' => $row->title,
                'employer_name' => $row->employer_name,
                'country' => $row->country,
                'city' => $row->city,
                'specialty' => $row->specialty,
                'employment_type' => $row->employment_type,
                'apply_url' => $row->apply_url,
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'job_opportunity_id' => ['required', 'integer'],
            'cover_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $job = DB::table('nurselink_job_opportunities')
            ->where('id', $data['job_opportunity_id'])
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            })
            ->first();

        abort_unless($job, 404);

        $userId = $request->user()->getKey();

        $existing = DB::table('nurselink_job_applications')
            ->where('user_id', $userId)
            ->where('job_opportunity_id', $job->id)
            ->first();

        if ($existing && $existing->status !== 'withdrawn') {
            return response()->json([
                'message' => 'You are already tracking an active application for this opportunity.',
            ], 422);
        }

        if ($existing) {
            DB::table('nurselink_job_applications')
                ->where('id', $existing->id)
                ->update([
                    'status' => 'submitted',
                    'cover_note' => $data['cover_note'] ?? null,
                    'submitted_at' => now(),
                    'withdrawn_at' => null,
                    'updated_at' => now(),
                ]);

            $id = (int) $existing->id;
        } else {
            $id = (int) DB::table('nurselink_job_applications')->insertGetId([
                'user_id' => $userId,
                'job_opportunity_id' => $job->id,
                'status' => 'submitted',
                'cover_note' => $data['cover_note'] ?? null,
                'submitted_at' => now(),
                'withdrawn_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Application added to your NurseLink tracker.',
            'data' => ['id' => $id],
        ], 201);
    }

    public function withdraw(Request $request, int $id): JsonResponse
    {
        $row = DB::table('nurselink_job_applications')
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->first();

        abort_unless($row, 404);

        if (in_array($row->status, ['withdrawn', 'declined'], true)) {
            return response()->json([
                'message' => 'This application is already closed.',
            ], 422);
        }

        DB::table('nurselink_job_applications')
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->update([
                'status' => 'withdrawn',
                'withdrawn_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Application withdrawn.']);
    }
}
