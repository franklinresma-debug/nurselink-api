<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LearningRecordController extends Controller
{
    private const TABLE = 'nurselink_learning_records';

    public function index(Request $request): JsonResponse
    {
        $rows = DB::table(self::TABLE)
            ->where('user_id', $request->user()->getKey())
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 1 WHEN 'planned' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END")
            ->orderByDesc('completed_at')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => $this->present($row))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $id = DB::table(self::TABLE)->insertGetId([
            ...$data,
            'user_id' => $request->user()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table(self::TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->first();

        return response()->json([
            'message' => 'Learning record added.',
            'data' => $this->present($row),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $existing = DB::table(self::TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->first();

        abort_unless($existing, 404);

        $data = $this->validated($request);

        DB::table(self::TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->update([
                ...$data,
                'updated_at' => now(),
            ]);

        $row = DB::table(self::TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->first();

        return response()->json([
            'message' => 'Learning record updated.',
            'data' => $this->present($row),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = DB::table(self::TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->delete();

        abort_unless($deleted, 404);

        return response()->json(['message' => 'Learning record removed.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'learning_type' => ['required', 'string', Rule::in([
                'course','webinar','workshop','conference','certification','self_study','mentoring','other',
            ])],
            'title' => ['required', 'string', 'max:190'],
            'provider' => ['nullable', 'string', 'max:190'],
            'topic' => ['nullable', 'string', 'max:160'],
            'status' => ['required', 'string', Rule::in(['planned','in_progress','completed'])],
            'started_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'learning_hours' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'cpd_units' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'certificate_url' => ['nullable', 'url', 'max:512'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
    }

    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'learning_type' => $row->learning_type,
            'title' => $row->title,
            'provider' => $row->provider,
            'topic' => $row->topic,
            'status' => $row->status,
            'started_at' => $row->started_at,
            'completed_at' => $row->completed_at,
            'learning_hours' => $row->learning_hours !== null ? (float) $row->learning_hours : null,
            'cpd_units' => $row->cpd_units !== null ? (float) $row->cpd_units : null,
            'certificate_url' => $row->certificate_url,
            'notes' => $row->notes,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
