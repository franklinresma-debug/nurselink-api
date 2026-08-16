<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CredentialRegistryController extends Controller
{
    private const TABLE = 'nurselink_credentials_registry';

    public function index(Request $request): JsonResponse
    {
        $rows = DB::table(self::TABLE)
            ->where('user_id', $request->user()->getKey())
            ->orderByRaw("CASE verification_status
                WHEN 'verified' THEN 1
                WHEN 'pending' THEN 2
                WHEN 'unverified' THEN 3
                WHEN 'expired' THEN 4
                ELSE 5 END")
            ->orderBy('credential_type')
            ->orderByDesc('issue_date')
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
            'verification_status' => 'unverified',
            'review_notes' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'user_id' => $request->user()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table(self::TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->first();

        return response()->json([
            'message' => 'Credential added.',
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

        DB::table(self::TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->update([
                ...$data,

                /*
                 * Any member-side credential edit invalidates the prior
                 * review. Only ReviewCenterController can restore reviewed
                 * verification state.
                 */
                'verification_status' => 'unverified',
                'review_notes' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'updated_at' => now(),
            ]);

        $updated = DB::table(self::TABLE)
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->first();

        return response()->json([
            'message' => 'Credential updated.',
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

        return response()->json(['message' => 'Credential removed.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'credential_type' => ['required', 'string', Rule::in([
                'prc_license',
                'nursing_diploma',
                'international_license',
                'specialty_certification',
                'training_certificate',
                'professional_membership',
                'language_certificate',
                'other',
            ])],
            'title' => ['required', 'string', 'max:190'],
            'issuing_body' => ['nullable', 'string', 'max:190'],
            'credential_number' => ['nullable', 'string', 'max:160'],
            'country' => ['nullable', 'string', 'max:120'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],

            /*
             * verification_status is intentionally reviewer-controlled and
             * is not accepted from member/applicant requests.
             */
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'credential_type' => $row->credential_type,
            'title' => $row->title,
            'issuing_body' => $row->issuing_body,
            'credential_number' => $row->credential_number,
            'country' => $row->country,
            'issue_date' => $row->issue_date,
            'expiry_date' => $row->expiry_date,
            'verification_status' => $row->verification_status,
            'notes' => $row->notes,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
