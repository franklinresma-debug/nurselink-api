<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class SuperAdminTestModeController extends Controller
{
    private const ADMIN_ELEVATION_TTL_SECONDS = 28800;
    private const MAX_TEST_MODE_MINUTES = 120;

    public function session(Request $request): JsonResponse
    {
        $access = $this->requireSuperAdmin($request);

        return response()->json([
            'data' => [
                'active' => $this->testModeActive($request),
                'started_at' => (int) $request->session()->get(
                    'nurselink_super_admin_test_mode_started_at',
                    0
                ),
                'expires_at' => (int) $request->session()->get(
                    'nurselink_super_admin_test_mode_expires_at',
                    0
                ),
                'membership_status' => $this->membershipStatus(
                    (string) $request->user()->getKey()
                ),
                'system_role' => $access['role'],
                'member_gate_bypass' => true,
                'membership_status_mutated' => false,
                'public_identity_mutated' => false,
                'partner_tenant_bypass' => false,
            ],
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'minutes' => [
                'nullable',
                'integer',
                Rule::in([15, 30, 60, 90, 120]),
            ],
        ]);

        $minutes = (int) ($data['minutes'] ?? 60);
        $minutes = min($minutes, self::MAX_TEST_MODE_MINUTES);

        $now = time();
        $expires = $now + ($minutes * 60);
        $userId = (string) $request->user()->getKey();

        $request->session()->put([
            'nurselink_super_admin_test_mode_user_id' => $userId,
            'nurselink_super_admin_test_mode_started_at' => $now,
            'nurselink_super_admin_test_mode_expires_at' => $expires,
        ]);

        $this->audit(
            $request,
            'super_admin.test_mode_started',
            [
                'minutes' => $minutes,
                'expires_at' => $expires,
                'membership_status' => $this->membershipStatus($userId),
            ]
        );

        return response()->json([
            'message' => 'Super Administrator Test Mode started.',
            'data' => [
                'active' => true,
                'started_at' => $now,
                'expires_at' => $expires,
                'minutes' => $minutes,
                'member_gate_bypass' => true,
                'membership_status_mutated' => false,
            ],
        ]);
    }

    public function stop(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $before = [
            'started_at' => (int) $request->session()->get(
                'nurselink_super_admin_test_mode_started_at',
                0
            ),
            'expires_at' => (int) $request->session()->get(
                'nurselink_super_admin_test_mode_expires_at',
                0
            ),
        ];

        $request->session()->forget([
            'nurselink_super_admin_test_mode_user_id',
            'nurselink_super_admin_test_mode_started_at',
            'nurselink_super_admin_test_mode_expires_at',
        ]);

        $this->audit(
            $request,
            'super_admin.test_mode_stopped',
            $before
        );

        return response()->json([
            'message' => 'Super Administrator Test Mode ended.',
            'data' => [
                'active' => false,
            ],
        ]);
    }

    public function checks(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        return response()->json([
            'data' => [
                'test_mode_active' => $this->testModeActive($request),
                'groups' => [
                    [
                        'name' => 'Applicant',
                        'items' => [
                            ['name' => 'Dashboard', 'url' => '/dashboard'],
                            ['name' => 'Profile', 'url' => '/profile?nlstep=1'],
                            ['name' => 'Professional Information', 'url' => '/profile?nlstep=2'],
                            ['name' => 'Credentials Registration', 'url' => '/smart-registration?nlstep=3'],
                            ['name' => 'Employment / OFW History', 'url' => '/profile?nlstep=4'],
                            ['name' => 'Documents', 'url' => '/smart-registration?nlstep=5'],
                            ['name' => 'Application Status', 'url' => '/application-status'],
                        ],
                    ],
                    [
                        'name' => 'Approved Member',
                        'items' => [
                            ['name' => 'Qualifications', 'url' => '/qualifications'],
                            ['name' => 'Credentials', 'url' => '/credentials'],
                            ['name' => 'Documents', 'url' => '/documents'],
                            ['name' => 'Portfolio', 'url' => '/portfolio'],
                            ['name' => 'Learning', 'url' => '/learning'],
                            ['name' => 'Jobs', 'url' => '/jobs'],
                            ['name' => 'Applications', 'url' => '/applications'],
                            ['name' => 'Messages', 'url' => '/messages'],
                            ['name' => 'Events', 'url' => '/events'],
                            ['name' => 'Mentoring', 'url' => '/mentoring'],
                            ['name' => 'Career Intelligence', 'url' => '/nurselink-career-intelligence.html'],
                            ['name' => 'Credential Renewal Center', 'url' => '/nurselink-credential-renewal.html'],
                            ['name' => 'Events & Programs', 'url' => '/nurselink-events.html'],
                            ['name' => 'Chapters & Communities', 'url' => '/nurselink-chapters.html'],
                            ['name' => 'Mentoring & Peer Support', 'url' => '/nurselink-mentoring.html'],
                            ['name' => 'Member Engagement Hub', 'url' => '/nurselink-engagement.html'],
                            ['name' => 'Enterprise Participation', 'url' => '/nurselink-enterprise.html'],
                            ['name' => 'Enterprise Goals', 'url' => '/nurselink-enterprise-goals.html'],
                            ['name' => 'Enterprise Invitations', 'url' => '/nurselink-enterprise-invitations.html'],
                            ['name' => 'Enterprise Outcomes', 'url' => '/nurselink-enterprise-outcomes.html'],
                            ['name' => 'Enterprise Support', 'url' => '/nurselink-enterprise-support.html'],
                            ['name' => 'Member Benefits & Resources', 'url' => '/nurselink-benefits.html'],
                            ['name' => 'Notifications', 'url' => '/nurselink-notifications.html'],
                        ],
                    ],
                    [
                        'name' => 'Administration',
                        'items' => [
                            ['name' => 'Administrator Dashboard', 'url' => '/nurselink-admin-dashboard.html'],
                            ['name' => 'Membership Welcome Center', 'url' => '/nurselink-membership-welcome.html'],
                            ['name' => 'Administration Operations Center', 'url' => '/nurselink-admin-dashboard.html'],
                            ['name' => 'Membership Administration Suite', 'url' => '/nurselink-membership-administration.html'],
                            ['name' => 'Membership Onboarding Admin', 'url' => '/nurselink-membership-onboarding-admin.html'],
                            ['name' => 'Membership Command Center', 'url' => '/nurselink-membership-command-center.html'],
                            ['name' => 'Approved Member Registry', 'url' => '/nurselink-member-registry.html'],
                            ['name' => 'Review Center', 'url' => '/admin'],
                            ['name' => 'Institutional Analytics', 'url' => '/nurselink-institutional-analytics.html'],
                            ['name' => 'Operations Center', 'url' => '/nurselink-operations-center.html'],
                            ['name' => 'Production Readiness', 'url' => '/nurselink-production-readiness.html'],
                            ['name' => 'Credential Compliance', 'url' => '/nurselink-credential-compliance.html'],
                            ['name' => 'Event Management', 'url' => '/nurselink-event-management.html'],
                            ['name' => 'Chapter Management', 'url' => '/nurselink-chapter-management.html'],
                            ['name' => 'Engagement Command Center', 'url' => '/nurselink-engagement-command-center.html'],
                            ['name' => 'Benefit Management', 'url' => '/nurselink-benefit-management.html'],
                            ['name' => 'Enterprise Command Center', 'url' => '/nurselink-enterprise-command-center.html'],
                            ['name' => 'Enterprise Goal Management', 'url' => '/nurselink-enterprise-goals-admin.html'],
                            ['name' => 'Enterprise Enrollment', 'url' => '/nurselink-enterprise-enrollment-admin.html'],
                            ['name' => 'Enterprise Outcome Review', 'url' => '/nurselink-enterprise-outcomes-admin.html'],
                            ['name' => 'Enterprise Support Triage', 'url' => '/nurselink-enterprise-support-admin.html'],
                        ],
                    ],
                    [
                        'name' => 'Independent Security Boundaries',
                        'items' => [
                            [
                                'name' => 'Partner Portal',
                                'url' => '/nurselink-partner-portal.html',
                                'note' => 'Requires its own partner-organization authorization. Test Mode does not bypass tenant access.',
                            ],
                            [
                                'name' => 'Public Membership Verification',
                                'url' => '/nurselink-member-verify.html',
                                'note' => 'Uses real approved membership state. Test Mode never changes public verification results.',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function requireSuperAdmin(Request $request): array
    {
        $user = $request->user();
        abort_unless($user, 401);

        $userId = (string) $user->getKey();

        $elevatedUserId = (string) $request->session()->get(
            'nurselink_admin_elevated_user_id',
            ''
        );

        $elevatedAt = (int) $request->session()->get(
            'nurselink_admin_elevated_at',
            0
        );

        $adminExpiresAt = (int) $request->session()->get(
            'nurselink_admin_expires_at',
            0
        );

        abort_unless(
            $elevatedUserId !== ''
            && hash_equals($elevatedUserId, $userId)
            && $elevatedAt > 0
            && $adminExpiresAt >= time()
            && (time() - $elevatedAt)
                <= self::ADMIN_ELEVATION_TTL_SECONDS,
            403,
            'A separate NurseLink Administrator Portal sign-in is required for Super Administrator Test Mode.'
        );

        abort_unless(
            $this->isSuperAdmin($user),
            403,
            'Super Administrator access is required for Test Mode.'
        );

        return [
            'role' => 'super_admin',
            'is_super_admin' => true,
        ];
    }

    private function isSuperAdmin($user): bool
    {
        $userId = $user->getKey();

        $explicit = Schema::hasTable('nurselink_super_admin_access')
            && DB::table('nurselink_super_admin_access')
                ->where('user_id', $userId)
                ->where('active', true)
                ->exists();

        $reviewRole = Schema::hasTable('nurselink_reviewer_access')
            ? strtolower((string) (
                DB::table('nurselink_reviewer_access')
                    ->where('user_id', $userId)
                    ->where('active', true)
                    ->value('role')
                ?? ''
            ))
            : '';

        $modelRole = strtolower(trim((string) (
            $user->role
            ?? $user->user_role
            ?? $user->user_type
            ?? ''
        )));

        return $explicit
            || $reviewRole === 'super_admin'
            || (bool) ($user->is_super_admin ?? false)
            || in_array(
                $modelRole,
                [
                    'super_admin',
                    'super-administrator',
                    'super_administrator',
                    'superadministrator',
                ],
                true
            );
    }

    private function testModeActive(Request $request): bool
    {
        $userId = (string) $request->user()->getKey();
        $modeUserId = (string) $request->session()->get(
            'nurselink_super_admin_test_mode_user_id',
            ''
        );
        $expiresAt = (int) $request->session()->get(
            'nurselink_super_admin_test_mode_expires_at',
            0
        );

        return $modeUserId !== ''
            && hash_equals($modeUserId, $userId)
            && $expiresAt >= time();
    }

    private function membershipStatus(string $userId): ?string
    {
        if (! Schema::hasTable('nurselink_memberships')) {
            return null;
        }

        return DB::table('nurselink_memberships')
            ->where('user_id', $userId)
            ->value('status');
    }

    private function audit(
        Request $request,
        string $action,
        array $after
    ): void {
        if (! Schema::hasTable('nurselink_review_audit')) {
            return;
        }

        DB::table('nurselink_review_audit')->insert([
            'reviewer_user_id' => (string) $request->user()->getKey(),
            'action' => $action,
            'target_type' => 'super_admin_test_mode',
            'target_id' => (string) $request->user()->getKey(),
            'before_state' => null,
            'after_state' => json_encode(
                $after,
                JSON_UNESCAPED_UNICODE
            ),
            'created_at' => now(),
        ]);
    }
}
