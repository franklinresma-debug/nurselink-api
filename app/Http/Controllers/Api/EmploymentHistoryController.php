<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmploymentHistoryController extends Controller
{
    private const TABLE = 'nurselink_employment_histories';

    public function index(Request $request): JsonResponse
    {
        $rows = DB::table(self::TABLE)
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('is_current')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => $this->present($row))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        if (($data['is_current'] ?? false) === true) {
            $data['end_date'] = null;
        }

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
            'message' => 'Employment record added.',
            'data' => $this->present($row),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $row = DB::table(self::TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->first();

        abort_unless($row, 404);

        $data = $this->validated($request);

        if (($data['is_current'] ?? false) === true) {
            $data['end_date'] = null;
        }

        DB::table(self::TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->update([
                ...$data,
                'updated_at' => now(),
            ]);

        $updated = DB::table(self::TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->first();

        return response()->json([
            'message' => 'Employment record updated.',
            'data' => $this->present($updated),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = DB::table(self::TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->delete();

        abort_unless($deleted, 404);

        return response()->json(['message' => 'Employment record removed.']);
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'employer_name' => ['required', 'string', 'max:190'],
            'facility_type' => ['nullable', 'string', Rule::in([
                'hospital', 'clinic', 'care_facility', 'government',
                'private_company', 'recruitment_agency', 'home_care',
                'education', 'other',
            ])],
            'country' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'position' => ['required', 'string', 'max:150'],
            'specialty' => ['nullable', 'string', 'max:150'],
            'employment_type' => ['nullable', 'string', Rule::in([
                'full_time', 'part_time', 'contract', 'temporary',
                'project_based', 'volunteer', 'other',
            ])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_current' => ['required', 'boolean'],
            'is_overseas' => ['required', 'boolean'],
            'deployment_type' => ['nullable', 'string', Rule::in([
                'licensed_agency', 'direct_hire', 'government_to_government',
                'name_hire', 'local_employment', 'other',
            ])],
            'agency_or_program' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['is_current'] = (bool) $validated['is_current'];
        $validated['is_overseas'] = (bool) $validated['is_overseas'];

        if (! $validated['is_overseas']) {
            $validated['deployment_type'] = 'local_employment';
            $validated['agency_or_program'] = null;
        }

        return $validated;
    }

    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'employer_name' => $row->employer_name,
            'facility_type' => $row->facility_type,
            'country' => $row->country,
            'city' => $row->city,
            'position' => $row->position,
            'specialty' => $row->specialty,
            'employment_type' => $row->employment_type,
            'start_date' => $row->start_date,
            'end_date' => $row->end_date,
            'is_current' => (bool) $row->is_current,
            'is_overseas' => (bool) $row->is_overseas,
            'deployment_type' => $row->deployment_type,
            'agency_or_program' => $row->agency_or_program,
            'notes' => $row->notes,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
