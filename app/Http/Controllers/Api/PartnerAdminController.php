<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class PartnerAdminController extends Controller
{
    public function organizations(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'data' => DB::table('nurselink_partner_organizations')
                ->orderByRaw("CASE status WHEN 'pending' THEN 1 WHEN 'verified' THEN 2 ELSE 3 END")
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeOrganization(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validateOrganization($request);

        $verified = $data['status'] === 'verified';

        $id = DB::table('nurselink_partner_organizations')->insertGetId([
            ...$data,
            'verified_by' => $verified ? (string) $request->user()->getKey() : null,
            'verified_at' => $verified ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Partner organization created.',
            'data' => DB::table('nurselink_partner_organizations')->where('id', $id)->first(),
        ], 201);
    }

    public function updateOrganization(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $before = DB::table('nurselink_partner_organizations')->where('id', $id)->first();
        abort_unless($before, 404);

        $data = $this->validateOrganization($request);
        $verified = $data['status'] === 'verified';

        DB::table('nurselink_partner_organizations')
            ->where('id', $id)
            ->update([
                ...$data,
                'verified_by' => $verified
                    ? ($before->verified_by ?: (string) $request->user()->getKey())
                    : null,
                'verified_at' => $verified
                    ? ($before->verified_at ?: now())
                    : null,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Partner organization updated.',
            'data' => DB::table('nurselink_partner_organizations')->where('id', $id)->first(),
        ]);
    }

    public function access(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $rows = DB::table('nurselink_partner_access as a')
            ->join('nurselink_partner_organizations as o', 'o.id', '=', 'a.partner_organization_id')
            ->orderByDesc('a.active')
            ->orderBy('o.name')
            ->get([
                'a.id',
                'a.user_id',
                'a.partner_organization_id',
                'a.role',
                'a.active',
                'a.created_at',
                'a.updated_at',
                'o.name as organization_name',
                'o.status as organization_status',
            ]);

        return response()->json([
            'data' => $rows->map(function ($row): array {
                $user = DB::table('users')->where('id', $row->user_id)->first();
                $label = trim((string) ($user->name ?? ''));

                if ($label === '') {
                    $label = trim(
                        (string) ($user->first_name ?? '')
                        . ' '
                        . (string) ($user->last_name ?? '')
                    );
                }

                if (Schema::hasColumn('users', 'email') && ! empty($user->email)) {
                    $label = trim($label . ' · ' . $user->email, ' ·');
                }

                return [
                    'id' => (int) $row->id,
                    'user_id' => (string) $row->user_id,
                    'user' => $label !== '' ? $label : (string) $row->user_id,
                    'partner_organization_id' => (int) $row->partner_organization_id,
                    'organization_name' => $row->organization_name,
                    'organization_status' => $row->organization_status,
                    'role' => $row->role,
                    'active' => (bool) $row->active,
                ];
            })->values(),
        ]);
    }

    public function grantAccess(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'user_id' => ['required', 'string', 'max:191'],
            'partner_organization_id' => ['required', 'integer', 'min:1'],
            'role' => ['required', 'string', Rule::in(['viewer', 'recruiter', 'manager'])],
            'active' => ['required', 'boolean'],
        ]);

        abort_unless(
            DB::table('users')->where('id', $data['user_id'])->exists(),
            422,
            'User does not exist.'
        );

        abort_unless(
            DB::table('nurselink_partner_organizations')
                ->where('id', $data['partner_organization_id'])
                ->exists(),
            422,
            'Partner organization does not exist.'
        );

        DB::table('nurselink_partner_access')->updateOrInsert(
            ['user_id' => $data['user_id']],
            [
                'partner_organization_id' => $data['partner_organization_id'],
                'role' => $data['role'],
                'active' => $data['active'],
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json(['message' => 'Partner access saved.']);
    }

    public function linkOpportunity(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'partner_organization_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if (! empty($data['partner_organization_id'])) {
            abort_unless(
                DB::table('nurselink_partner_organizations')
                    ->where('id', $data['partner_organization_id'])
                    ->exists(),
                422,
                'Partner organization does not exist.'
            );
        }

        abort_unless(
            DB::table('nurselink_job_opportunities')->where('id', $id)->exists(),
            404
        );

        DB::table('nurselink_job_opportunities')
            ->where('id', $id)
            ->update([
                'partner_organization_id' => $data['partner_organization_id'] ?? null,
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Opportunity partner link updated.']);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless($user, 401);

        $access = DB::table('nurselink_reviewer_access')
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->where('role', 'admin')
            ->first();

        $modelRole = strtolower((string) (
            $user->role
            ?? $user->user_role
            ?? $user->user_type
            ?? ''
        ));

        $modelAdmin = (bool) (
            $user->is_admin
            ?? $user->is_super_admin
            ?? false
        );

        abort_unless(
            $access || $modelAdmin || in_array($modelRole, ['admin', 'administrator', 'super_admin'], true),
            403,
            'NurseLink administrator access is required.'
        );
    }

    private function validateOrganization(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'organization_type' => ['required', 'string', Rule::in([
                'hospital',
                'health_system',
                'clinic',
                'recruitment_agency',
                'government',
                'education',
                'professional_organization',
                'other',
            ])],
            'country' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'url', 'max:512'],
            'status' => ['required', 'string', Rule::in(['pending', 'verified', 'suspended'])],
        ]);
    }
}
