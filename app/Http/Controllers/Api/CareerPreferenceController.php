<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CareerPreferenceController extends Controller
{
    private const TABLE = 'nurselink_career_preferences';

    public function show(Request $request): JsonResponse
    {
        $row = DB::table(self::TABLE)
            ->where('user_id', $request->user()->getKey())
            ->first();

        return response()->json([
            'data' => $row ? $this->present($row) : null,
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $userId = $request->user()->getKey();

        $payload = [
            'desired_roles' => $this->encode($data['desired_roles'] ?? []),
            'specialties' => $this->encode($data['specialties'] ?? []),
            'target_countries' => $this->encode($data['target_countries'] ?? []),
            'work_settings' => $this->encode($data['work_settings'] ?? []),
            'employment_types' => $this->encode($data['employment_types'] ?? []),
            'open_to_overseas' => (bool) ($data['open_to_overseas'] ?? false),
            'open_to_relocation' => (bool) ($data['open_to_relocation'] ?? false),
            'open_to_telehealth' => (bool) ($data['open_to_telehealth'] ?? false),
            'available_from' => $data['available_from'] ?? null,
            'preferred_currency' => $data['preferred_currency'] ?? null,
            'minimum_monthly_compensation' => $data['minimum_monthly_compensation'] ?? null,
            'career_stage' => $data['career_stage'] ?? null,
            'career_goal' => $data['career_goal'] ?? null,
            'updated_at' => now(),
        ];

        $exists = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            DB::table(self::TABLE)
                ->where('user_id', $userId)
                ->update($payload);
        } else {
            DB::table(self::TABLE)->insert([
                ...$payload,
                'user_id' => $userId,
                'created_at' => now(),
            ]);
        }

        $row = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->first();

        return response()->json([
            'message' => 'Career matching profile updated.',
            'data' => $this->present($row),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'desired_roles' => ['nullable', 'array', 'max:15'],
            'desired_roles.*' => ['string', 'max:120'],
            'specialties' => ['nullable', 'array', 'max:20'],
            'specialties.*' => ['string', 'max:120'],
            'target_countries' => ['nullable', 'array', 'max:20'],
            'target_countries.*' => ['string', 'max:120'],
            'work_settings' => ['nullable', 'array', 'max:12'],
            'work_settings.*' => ['string', Rule::in([
                'hospital','clinic','community','home_care','long_term_care',
                'education','occupational_health','telehealth','government','other',
            ])],
            'employment_types' => ['nullable', 'array', 'max:8'],
            'employment_types.*' => ['string', Rule::in([
                'full_time','part_time','contract','temporary','project_based','other',
            ])],
            'open_to_overseas' => ['required', 'boolean'],
            'open_to_relocation' => ['required', 'boolean'],
            'open_to_telehealth' => ['required', 'boolean'],
            'available_from' => ['nullable', 'date'],
            'preferred_currency' => ['nullable', 'string', 'max:8'],
            'minimum_monthly_compensation' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'career_stage' => ['nullable', 'string', Rule::in([
                'new_graduate','early_career','mid_career','senior','leadership',
                'returning_ofw','career_transition',
            ])],
            'career_goal' => ['nullable', 'string', 'max:3000'],
        ]);
    }

    private function encode(array $values): ?string
    {
        $values = array_values(array_unique(array_filter(
            array_map(fn ($v) => trim((string) $v), $values),
            fn ($v) => $v !== null && trim((string) $v) !== ''
        )));

        return $values === [] ? null : json_encode($values, JSON_UNESCAPED_UNICODE);
    }

    private function decode(?string $value): array
    {
        if (! $value) return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'desired_roles' => $this->decode($row->desired_roles),
            'specialties' => $this->decode($row->specialties),
            'target_countries' => $this->decode($row->target_countries),
            'work_settings' => $this->decode($row->work_settings),
            'employment_types' => $this->decode($row->employment_types),
            'open_to_overseas' => (bool) $row->open_to_overseas,
            'open_to_relocation' => (bool) $row->open_to_relocation,
            'open_to_telehealth' => (bool) $row->open_to_telehealth,
            'available_from' => $row->available_from,
            'preferred_currency' => $row->preferred_currency,
            'minimum_monthly_compensation' => $row->minimum_monthly_compensation !== null
                ? (float) $row->minimum_monthly_compensation
                : null,
            'career_stage' => $row->career_stage,
            'career_goal' => $row->career_goal,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
