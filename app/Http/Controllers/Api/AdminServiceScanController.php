<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminServiceScanController extends Controller
{
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required', 'string', 'max:1000'],
        ]);

        $membership = $this->resolveMembership($data['value']);

        return response()->json([
            'data' => $this->presentMembership($membership),
        ]);
    }

    public function record(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required', 'string', 'max:1000'],
            'purpose' => ['required', Rule::in(['benefit', 'workshop', 'event', 'program', 'other'])],
            'reference_type' => ['nullable', Rule::in(['general', 'benefit', 'workshop', 'event', 'program', 'other'])],
            'reference_id' => ['nullable', 'string', 'max:120'],
            'reference_label' => ['nullable', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $membership = $this->resolveMembership($data['value']);
        $referenceType = $data['reference_type'] ?? $data['purpose'];
        $referenceIdentity = trim((string) ($data['reference_id'] ?? $data['reference_label'] ?? 'general'));
        $window = now()->format('Y-m-d-H-i');
        $dedupeKey = hash('sha256', implode('|', [
            $membership->id,
            $data['purpose'],
            $referenceType,
            mb_strtolower($referenceIdentity),
            $window,
        ]));

        $existing = DB::table('nurselink_service_scans')
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'This member was already recorded for the same service within the current minute.',
                'duplicate' => true,
                'data' => $this->presentScan($existing),
            ], 409);
        }

        $id = (string) Str::uuid();
        $now = now();

        DB::table('nurselink_service_scans')->insert([
            'id' => $id,
            'membership_id' => $membership->id,
            'user_id' => $membership->user_id,
            'recorded_by' => $request->user()->getKey(),
            'purpose' => $data['purpose'],
            'reference_type' => $referenceType,
            'reference_id' => $data['reference_id'] ?? null,
            'reference_label' => $data['reference_label'] ?? null,
            'dedupe_key' => $dedupeKey,
            'note' => $data['note'] ?? null,
            'metadata' => json_encode(['standing_at_scan' => 'active']),
            'scanned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $audit->write(
            'nurselink.service_scan.recorded',
            $request->user(),
            'nurselink_service_scan',
            $id,
            [
                'membership_id' => (int) $membership->id,
                'member_number' => $membership->member_number,
                'purpose' => $data['purpose'],
                'reference_type' => $referenceType,
                'reference_id' => $data['reference_id'] ?? null,
            ],
            $request
        );

        $scan = DB::table('nurselink_service_scans')->where('id', $id)->first();

        return response()->json([
            'message' => 'Member service use recorded.',
            'data' => [
                'member' => $this->presentMembership($membership),
                'scan' => $this->presentScan($scan),
            ],
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['nullable', Rule::in(['benefit', 'workshop', 'event', 'program', 'other'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DB::table('nurselink_service_scans as s')
            ->join('nurselink_memberships as m', 'm.id', '=', 's.membership_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('users as a', 'a.id', '=', 's.recorded_by')
            ->select(['s.*', 'm.member_number', 'u.name as member_name', 'a.name as recorded_by_name']);

        if (! empty($data['purpose'])) {
            $query->where('s.purpose', $data['purpose']);
        }

        return response()->json([
            'data' => $query->orderByDesc('s.scanned_at')
                ->limit((int) ($data['limit'] ?? 30))
                ->get()
                ->map(fn ($row) => $this->presentScan($row))
                ->values(),
        ]);
    }

    private function resolveMembership(string $value): object
    {
        $value = trim($value);
        $code = $value;

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $query = [];
            parse_str((string) parse_url($value, PHP_URL_QUERY), $query);
            $code = trim((string) ($query['code'] ?? ''));
        }

        $membership = DB::table('nurselink_memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where(function ($query) use ($code, $value): void {
                $query->where('m.verification_code', $code)
                    ->orWhere('m.member_number', $value);
            })
            ->select(['m.*', 'u.name as member_name', 'u.email as member_email', 'u.profile_photo_path'])
            ->first();

        abort_unless($membership, 404, 'No NurseLink member matches this QR code or member number.');
        abort_unless($membership->status === 'approved', 422, 'This membership is not approved.');

        $standing = strtolower(trim((string) ($membership->standing ?? 'active')));
        abort_unless($standing === 'active', 422, 'This membership is not currently active.');

        return $membership;
    }

    private function presentMembership(object $membership): array
    {
        return [
            'membership_id' => (int) $membership->id,
            'member_number' => $membership->member_number,
            'member_name' => $membership->member_name,
            'status' => 'approved',
            'standing' => 'active',
            'approved_at' => $membership->approved_at,
            'profile_photo_available' => ! empty($membership->profile_photo_path),
            'valid' => true,
        ];
    }

    private function presentScan(object $scan): array
    {
        return [
            'id' => $scan->id,
            'member_number' => $scan->member_number ?? null,
            'member_name' => $scan->member_name ?? null,
            'purpose' => $scan->purpose,
            'reference_type' => $scan->reference_type,
            'reference_id' => $scan->reference_id,
            'reference_label' => $scan->reference_label,
            'note' => $scan->note,
            'recorded_by_name' => $scan->recorded_by_name ?? null,
            'scanned_at' => $scan->scanned_at,
        ];
    }
}
