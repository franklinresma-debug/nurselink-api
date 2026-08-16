<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class CredentialRenewalController extends Controller
{
    private const TABLE = 'nurselink_credentials_registry';
    private const RENEWAL_TABLE = 'nurselink_credential_renewals';

    private const MEMBER_STATUSES = [
        'planning',
        'in_progress',
        'submitted',
        'cancelled',
    ];

    private const ADMIN_STATUSES = [
        'planning',
        'in_progress',
        'submitted',
        'returned',
        'completed',
        'cancelled',
    ];

    public function member(Request $request): JsonResponse
    {
        $userId = (string) $request->user()->getKey();

        $rows = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('credential_type')
            ->get()
            ->map(
                fn ($row): array =>
                    $this->presentCredential(
                        $row,
                        $this->latestRenewal(
                            (int) $row->id,
                            $userId
                        )
                    )
            )
            ->values();

        return response()->json([
            'data' => [
                'summary' => $this->summary($rows->all()),
                'workflow_summary' =>
                    $this->workflowSummary($rows->all()),
                'credentials' => $rows,
                'advisory' => [
                    'official_authority' => false,
                    'message' => 'NurseLink renewal planning is an internal professional-development and compliance aid. Official renewal requirements and validity remain with the issuing body or regulator.',
                ],
            ],
        ]);
    }

    public function start(
        Request $request,
        int $credentialId
    ): JsonResponse {
        $userId = (string) $request->user()->getKey();

        $credential = DB::table(self::TABLE)
            ->where('id', $credentialId)
            ->where('user_id', $userId)
            ->first();

        abort_unless($credential, 404);

        $data = $request->validate([
            'status' => [
                'nullable',
                'string',
                Rule::in(self::MEMBER_STATUSES),
            ],
            'target_date' => [
                'nullable',
                'date',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:3000',
            ],
            'evidence_reference' => [
                'nullable',
                'string',
                'max:512',
            ],
        ]);

        $existing = $this->latestOpenRenewal(
            $credentialId,
            $userId
        );

        if ($existing) {
            return response()->json([
                'message' => 'An open renewal workflow already exists for this credential.',
                'data' => $this->presentRenewal($existing),
            ], 409);
        }

        $status = $data['status'] ?? 'planning';
        $timestamps = $this->statusTimestamps(
            $status,
            null
        );

        $id = DB::table(self::RENEWAL_TABLE)
            ->insertGetId([
                'user_id' => $userId,
                'credential_id' => $credentialId,
                'status' => $status,
                'target_date' => $data['target_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'evidence_reference' =>
                    $data['evidence_reference'] ?? null,
                ...$timestamps,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $renewal = DB::table(self::RENEWAL_TABLE)
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        $this->audit(
            $request,
            'credential_renewal.started',
            (string) $id,
            null,
            $renewal
        );

        return response()->json([
            'message' => 'Credential renewal plan started.',
            'data' => $this->presentRenewal($renewal),
        ], 201);
    }

    public function update(
        Request $request,
        int $credentialId,
        int $renewalId
    ): JsonResponse {
        $userId = (string) $request->user()->getKey();

        $credential = DB::table(self::TABLE)
            ->where('id', $credentialId)
            ->where('user_id', $userId)
            ->first();

        abort_unless($credential, 404);

        $before = DB::table(self::RENEWAL_TABLE)
            ->where('id', $renewalId)
            ->where('credential_id', $credentialId)
            ->where('user_id', $userId)
            ->first();

        abort_unless($before, 404);

        if (in_array(
            $before->status,
            ['completed', 'cancelled'],
            true
        )) {
            return response()->json([
                'message' =>
                    'Completed or cancelled renewal workflows cannot be changed by the member.',
            ], 422);
        }

        $data = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(self::MEMBER_STATUSES),
            ],
            'target_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'evidence_reference' => [
                'nullable',
                'string',
                'max:512',
            ],
        ]);

        $timestamps = $this->statusTimestamps(
            $data['status'],
            $before
        );

        DB::table(self::RENEWAL_TABLE)
            ->where('id', $renewalId)
            ->where('user_id', $userId)
            ->update([
                'status' => $data['status'],
                'target_date' => $data['target_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'evidence_reference' =>
                    $data['evidence_reference'] ?? null,
                ...$timestamps,
                'updated_at' => now(),
            ]);

        $after = DB::table(self::RENEWAL_TABLE)
            ->where('id', $renewalId)
            ->first();

        $this->audit(
            $request,
            'credential_renewal.updated',
            (string) $renewalId,
            $before,
            $after
        );

        return response()->json([
            'message' => 'Credential renewal plan updated.',
            'data' => $this->presentRenewal($after),
        ]);
    }

    public function adminSummary(Request $request): JsonResponse
    {
        $this->requireAdministratorSession($request);

        $rows = DB::table(self::TABLE)
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->limit(5000)
            ->get();

        $summary = [
            'expired' => 0,
            'critical_30' => 0,
            'due_90' => 0,
            'upcoming_180' => 0,
            'current' => 0,
            'no_expiry' => 0,
            'total' => $rows->count(),
        ];

        foreach ($rows as $row) {
            $state = $this->expiryState($row->expiry_date);
            $summary[$state]++;
        }

        $workflow = [
            'planning' => 0,
            'in_progress' => 0,
            'submitted' => 0,
            'returned' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'total' => 0,
        ];

        if (Schema::hasTable(self::RENEWAL_TABLE)) {
            $latest = [];

            foreach (
                DB::table(self::RENEWAL_TABLE)
                    ->orderByDesc('id')
                    ->get()
                as $row
            ) {
                $credentialId = (int) $row->credential_id;

                if (! isset($latest[$credentialId])) {
                    $latest[$credentialId] = $row;
                }
            }

            foreach ($latest as $row) {
                $status = (string) $row->status;
                $workflow['total']++;

                if (array_key_exists($status, $workflow)) {
                    $workflow[$status]++;
                }
            }
        }

        return response()->json([
            'data' => [
                'summary' => $summary,
                'workflow' => $workflow,
                'credential_numbers_exposed' => false,
                'member_documents_exposed' => false,
            ],
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireAdministratorSession($request);

        $data = $request->validate([
            'expiry_state' => [
                'nullable',
                'string',
                Rule::in([
                    'all',
                    'expired',
                    'critical_30',
                    'due_90',
                    'upcoming_180',
                ]),
            ],
            'workflow_status' => [
                'nullable',
                'string',
                Rule::in([
                    'all',
                    ...self::ADMIN_STATUSES,
                    'none',
                ]),
            ],
            'search' => [
                'nullable',
                'string',
                'max:190',
            ],
        ]);

        $rows = DB::table(self::TABLE)
            ->whereNotNull('expiry_date')
            ->whereDate(
                'expiry_date',
                '<=',
                CarbonImmutable::today()
                    ->addDays(180)
                    ->toDateString()
            )
            ->orderBy('expiry_date')
            ->limit(2000)
            ->get();

        $userIds = $rows
            ->pluck('user_id')
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values()
            ->all();

        $users = $this->userMap($userIds);
        $memberships = $this->membershipMap($userIds);
        $renewals = $this->latestRenewalMap(
            $rows->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        $result = $rows
            ->map(function ($row) use (
                $users,
                $memberships,
                $renewals
            ): array {
                $userId = (string) $row->user_id;
                $user = $users[$userId] ?? [];
                $membership = $memberships[$userId] ?? [];
                $renewal = $renewals[(int) $row->id] ?? null;

                return [
                    'credential_id' => (int) $row->id,
                    'user_id' => $userId,
                    'member_name' => $user['name'] ?? $userId,
                    'member_email' => $user['email'] ?? '',
                    'member_number' =>
                        $membership['member_number'] ?? null,
                    'membership_standing' =>
                        $membership['standing'] ?? null,
                    'credential_type' => $row->credential_type,
                    'title' => $row->title,
                    'issuing_body' => $row->issuing_body,
                    'country' => $row->country,
                    'expiry_date' => $row->expiry_date,
                    'verification_status' =>
                        $row->verification_status,
                    'expiry_state' =>
                        $this->expiryState($row->expiry_date),
                    'days_until_expiry' =>
                        $this->daysUntilExpiry(
                            $row->expiry_date
                        ),
                    'renewal' => $renewal
                        ? $this->presentRenewal($renewal)
                        : null,
                ];
            });

        $expiryFilter = $data['expiry_state'] ?? 'all';

        if ($expiryFilter !== 'all') {
            $result = $result->filter(
                fn (array $row): bool =>
                    $row['expiry_state'] === $expiryFilter
            );
        }

        $workflowFilter =
            $data['workflow_status'] ?? 'all';

        if ($workflowFilter === 'none') {
            $result = $result->filter(
                fn (array $row): bool =>
                    $row['renewal'] === null
            );
        } elseif ($workflowFilter !== 'all') {
            $result = $result->filter(
                fn (array $row): bool =>
                    ($row['renewal']['status'] ?? null)
                        === $workflowFilter
            );
        }

        $search = strtolower(trim((string) (
            $data['search'] ?? ''
        )));

        if ($search !== '') {
            $result = $result->filter(
                function (array $row) use ($search): bool {
                    $haystack = strtolower(
                        ($row['member_name'] ?? '')
                        . ' '
                        . ($row['member_email'] ?? '')
                        . ' '
                        . ($row['member_number'] ?? '')
                        . ' '
                        . ($row['title'] ?? '')
                        . ' '
                        . ($row['issuing_body'] ?? '')
                    );

                    return str_contains($haystack, $search);
                }
            );
        }

        return response()->json([
            'data' => $result->values(),
            'privacy' => [
                'credential_numbers_exposed' => false,
                'uploaded_documents_exposed' => false,
                'reviewer_notes_exposed' => false,
            ],
        ]);
    }

    public function adminUpdate(
        Request $request,
        int $renewalId
    ): JsonResponse {
        $this->requireAdministratorSession($request);

        $before = DB::table(self::RENEWAL_TABLE)
            ->where('id', $renewalId)
            ->first();

        abort_unless($before, 404);

        $data = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(self::ADMIN_STATUSES),
            ],
            'notes' => [
                'nullable',
                'string',
                'max:3000',
            ],
        ]);

        $timestamps = $this->statusTimestamps(
            $data['status'],
            $before
        );

        DB::table(self::RENEWAL_TABLE)
            ->where('id', $renewalId)
            ->update([
                'status' => $data['status'],
                'notes' => $data['notes']
                    ?? $before->notes,
                ...$timestamps,
                'updated_at' => now(),
            ]);

        $after = DB::table(self::RENEWAL_TABLE)
            ->where('id', $renewalId)
            ->first();

        $this->audit(
            $request,
            'credential_renewal.admin_status_changed',
            (string) $renewalId,
            $before,
            $after
        );

        $this->notifyWorkflowUpdate($after);

        return response()->json([
            'message' =>
                'Credential renewal workflow updated.',
            'data' => $this->presentRenewal($after),
            'advisory' => [
                'official_renewal_certification' => false,
                'message' =>
                    'Completing the NurseLink workflow does not certify renewal by the issuing body. The member must update the credential record and any reviewer verification remains separate.',
            ],
        ]);
    }

    private function presentCredential(
        object $row,
        ?object $renewal = null
    ): array {
        $state = $this->expiryState($row->expiry_date);

        return [
            'id' => (int) $row->id,
            'credential_type' => $row->credential_type,
            'title' => $row->title,
            'issuing_body' => $row->issuing_body,
            'country' => $row->country,
            'issue_date' => $row->issue_date,
            'expiry_date' => $row->expiry_date,
            'verification_status' =>
                $row->verification_status,
            'expiry_state' => $state,
            'expiry_label' =>
                $this->expiryLabel($state),
            'days_until_expiry' =>
                $this->daysUntilExpiry($row->expiry_date),
            'priority' => $this->priority($state),
            'recommended_action' =>
                $this->recommendedAction($state),
            'renewal' => $renewal
                ? $this->presentRenewal($renewal)
                : null,
        ];
    }

    private function presentRenewal(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'credential_id' => (int) $row->credential_id,
            'status' => $row->status,
            'status_label' =>
                $this->workflowLabel($row->status),
            'target_date' => $row->target_date,
            'notes' => $row->notes,
            'evidence_reference' =>
                $row->evidence_reference,
            'started_at' => $row->started_at,
            'submitted_at' => $row->submitted_at,
            'completed_at' => $row->completed_at,
            'cancelled_at' => $row->cancelled_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function summary(array $rows): array
    {
        $summary = [
            'expired' => 0,
            'critical_30' => 0,
            'due_90' => 0,
            'upcoming_180' => 0,
            'current' => 0,
            'no_expiry' => 0,
            'total' => count($rows),
        ];

        foreach ($rows as $row) {
            $state = $row['expiry_state'] ?? 'no_expiry';

            if (array_key_exists($state, $summary)) {
                $summary[$state]++;
            }
        }

        return $summary;
    }

    private function workflowSummary(array $rows): array
    {
        $summary = [
            'none' => 0,
            'planning' => 0,
            'in_progress' => 0,
            'submitted' => 0,
            'returned' => 0,
            'completed' => 0,
            'cancelled' => 0,
        ];

        foreach ($rows as $row) {
            $status = $row['renewal']['status'] ?? 'none';

            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    private function latestRenewal(
        int $credentialId,
        string $userId
    ): ?object {
        if (! Schema::hasTable(self::RENEWAL_TABLE)) {
            return null;
        }

        return DB::table(self::RENEWAL_TABLE)
            ->where('credential_id', $credentialId)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->first();
    }

    private function latestOpenRenewal(
        int $credentialId,
        string $userId
    ): ?object {
        if (! Schema::hasTable(self::RENEWAL_TABLE)) {
            return null;
        }

        return DB::table(self::RENEWAL_TABLE)
            ->where('credential_id', $credentialId)
            ->where('user_id', $userId)
            ->whereNotIn(
                'status',
                ['completed', 'cancelled']
            )
            ->orderByDesc('id')
            ->first();
    }

    private function latestRenewalMap(
        array $credentialIds
    ): array {
        if (
            $credentialIds === []
            || ! Schema::hasTable(self::RENEWAL_TABLE)
        ) {
            return [];
        }

        $rows = DB::table(self::RENEWAL_TABLE)
            ->whereIn('credential_id', $credentialIds)
            ->orderByDesc('id')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $credentialId = (int) $row->credential_id;

            if (! isset($map[$credentialId])) {
                $map[$credentialId] = $row;
            }
        }

        return $map;
    }

    private function statusTimestamps(
        string $status,
        ?object $before
    ): array {
        $now = now();

        return [
            'started_at' =>
                $before?->started_at ?? $now,
            'submitted_at' =>
                $status === 'submitted'
                    ? ($before?->submitted_at ?? $now)
                    : ($before?->submitted_at ?? null),
            'completed_at' =>
                $status === 'completed'
                    ? ($before?->completed_at ?? $now)
                    : ($before?->completed_at ?? null),
            'cancelled_at' =>
                $status === 'cancelled'
                    ? ($before?->cancelled_at ?? $now)
                    : ($before?->cancelled_at ?? null),
        ];
    }

    private function expiryState(
        ?string $expiryDate
    ): string {
        if (! $expiryDate) {
            return 'no_expiry';
        }

        $days = $this->daysUntilExpiry($expiryDate);

        if ($days === null) {
            return 'no_expiry';
        }

        if ($days < 0) {
            return 'expired';
        }

        if ($days <= 30) {
            return 'critical_30';
        }

        if ($days <= 90) {
            return 'due_90';
        }

        if ($days <= 180) {
            return 'upcoming_180';
        }

        return 'current';
    }

    private function daysUntilExpiry(
        ?string $expiryDate
    ): ?int {
        if (! $expiryDate) {
            return null;
        }

        try {
            return CarbonImmutable::today()->diffInDays(
                CarbonImmutable::parse($expiryDate)
                    ->startOfDay(),
                false
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function expiryLabel(string $state): string
    {
        return match ($state) {
            'expired' => 'Expired',
            'critical_30' => 'Expires within 30 days',
            'due_90' => 'Expires within 90 days',
            'upcoming_180' => 'Expires within 180 days',
            'current' => 'Current',
            default => 'No expiry date',
        };
    }

    private function workflowLabel(string $status): string
    {
        return match ($status) {
            'planning' => 'Planning',
            'in_progress' => 'In Progress',
            'submitted' => 'Submitted',
            'returned' => 'Returned for Update',
            'completed' => 'Workflow Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function priority(string $state): string
    {
        return match ($state) {
            'expired' => 'urgent',
            'critical_30' => 'high',
            'due_90' => 'medium',
            'upcoming_180' => 'planning',
            default => 'routine',
        };
    }

    private function recommendedAction(string $state): string
    {
        return match ($state) {
            'expired' =>
                'Confirm official renewal or replacement requirements with the issuing body immediately.',
            'critical_30' =>
                'Begin renewal immediately and verify required documents with the issuing body.',
            'due_90' =>
                'Prepare renewal requirements and confirm appointment or processing timelines.',
            'upcoming_180' =>
                'Add the credential to your renewal plan and review official requirements.',
            'current' =>
                'No immediate renewal action. Keep your professional record updated.',
            default =>
                'Confirm whether this credential requires periodic renewal.',
        };
    }

    private function requireAdministratorSession(
        Request $request
    ): void {
        $user = $request->user();
        abort_unless($user, 401);

        $userId = (string) $user->getKey();

        $elevatedUserId = (string) $request->session()->get(
            'nurselink_admin_elevated_user_id',
            ''
        );

        $expiresAt = (int) $request->session()->get(
            'nurselink_admin_expires_at',
            0
        );

        abort_unless(
            $elevatedUserId !== ''
            && hash_equals($elevatedUserId, $userId)
            && $expiresAt >= time(),
            403,
            'A separate NurseLink Administrator Portal sign-in is required.'
        );

        $reviewRole = Schema::hasTable(
            'nurselink_reviewer_access'
        )
            ? strtolower((string) (
                DB::table('nurselink_reviewer_access')
                    ->where('user_id', $userId)
                    ->where('active', true)
                    ->value('role')
                ?? ''
            ))
            : '';

        $explicitSuperAdmin = Schema::hasTable(
            'nurselink_super_admin_access'
        )
            && DB::table('nurselink_super_admin_access')
                ->where('user_id', $userId)
                ->where('active', true)
                ->exists();

        abort_unless(
            $explicitSuperAdmin
            || in_array(
                $reviewRole,
                ['admin', 'super_admin'],
                true
            )
            || (bool) ($user->is_admin ?? false)
            || (bool) ($user->is_super_admin ?? false),
            403,
            'Administrator access is required for credential renewal monitoring.'
        );
    }

    private function userMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $columns = ['id'];

        foreach (
            ['email', 'name', 'first_name', 'last_name']
            as $column
        ) {
            if (Schema::hasColumn('users', $column)) {
                $columns[] = $column;
            }
        }

        return DB::table('users')
            ->whereIn('id', $ids)
            ->get($columns)
            ->mapWithKeys(function ($row): array {
                $name = trim((string) ($row->name ?? ''));

                if ($name === '') {
                    $name = trim(
                        (string) ($row->first_name ?? '')
                        . ' '
                        . (string) ($row->last_name ?? '')
                    );
                }

                return [
                    (string) $row->id => [
                        'name' => $name !== ''
                            ? $name
                            : (string) (
                                $row->email
                                ?? $row->id
                            ),
                        'email' => (string) (
                            $row->email ?? ''
                        ),
                    ],
                ];
            })
            ->all();
    }

    private function membershipMap(array $ids): array
    {
        if (
            $ids === []
            || ! Schema::hasTable(
                'nurselink_memberships'
            )
        ) {
            return [];
        }

        return DB::table('nurselink_memberships')
            ->whereIn('user_id', $ids)
            ->where('status', 'approved')
            ->get([
                'user_id',
                'member_number',
                'standing',
            ])
            ->mapWithKeys(fn ($row): array => [
                (string) $row->user_id => [
                    'member_number' => $row->member_number,
                    'standing' =>
                        $row->standing ?: 'active',
                ],
            ])
            ->all();
    }

    private function audit(
        Request $request,
        string $action,
        string $targetId,
        ?object $before,
        ?object $after
    ): void {
        if (! Schema::hasTable('nurselink_review_audit')) {
            return;
        }

        DB::table('nurselink_review_audit')->insert([
            'reviewer_user_id' =>
                (string) $request->user()->getKey(),
            'action' => $action,
            'target_type' => 'credential_renewal',
            'target_id' => $targetId,
            'before_state' => $before
                ? json_encode(
                    $before,
                    JSON_UNESCAPED_UNICODE
                )
                : null,
            'after_state' => $after
                ? json_encode(
                    $after,
                    JSON_UNESCAPED_UNICODE
                )
                : null,
            'created_at' => now(),
        ]);
    }

    private function notifyWorkflowUpdate(
        object $renewal
    ): void {
        if (! Schema::hasTable('nurselink_notifications')) {
            return;
        }

        $status = (string) $renewal->status;
        $label = $this->workflowLabel($status);

        DB::table('nurselink_notifications')->insert([
            'user_id' => $renewal->user_id,
            'type' => 'credential.renewal.workflow.' . $status,
            'severity' =>
                $status === 'returned'
                    ? 'warning'
                    : 'info',
            'title' =>
                'Credential renewal workflow updated',
            'message' =>
                'Your NurseLink credential renewal workflow is now '
                . $label
                . '. This is a NurseLink workflow status and does not replace official confirmation from the issuing body.',
            'action_url' =>
                '/nurselink-credential-renewal.html',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
