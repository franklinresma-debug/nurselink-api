<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PortfolioItemController extends Controller
{
    private const TABLE = 'nurselink_portfolio_items';

    public function index(Request $request): JsonResponse
    {
        $rows = DB::table(self::TABLE)
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('is_featured')
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
            'message' => 'Portfolio item added.',
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
            'message' => 'Portfolio item updated.',
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

        return response()->json(['message' => 'Portfolio item removed.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'item_type' => ['required', 'string', Rule::in([
                'achievement',
                'leadership',
                'research',
                'project',
                'training',
                'volunteer',
                'recognition',
                'publication',
                'community_service',
                'other',
            ])],
            'title' => ['required', 'string', 'max:190'],
            'organization' => ['nullable', 'string', 'max:190'],
            'location' => ['nullable', 'string', 'max:190'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'reference_url' => ['nullable', 'url', 'max:512'],
            'visibility' => ['required', 'string', Rule::in([
                'private',
                'members',
                'public',
            ])],
            'is_featured' => ['required', 'boolean'],
        ]);
    }

    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'item_type' => $row->item_type,
            'title' => $row->title,
            'organization' => $row->organization,
            'location' => $row->location,
            'start_date' => $row->start_date,
            'end_date' => $row->end_date,
            'description' => $row->description,
            'reference_url' => $row->reference_url,
            'visibility' => $row->visibility,
            'is_featured' => (bool) $row->is_featured,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
