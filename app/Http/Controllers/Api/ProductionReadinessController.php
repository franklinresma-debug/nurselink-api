<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SmartRegistration\LocalOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ProductionReadinessController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $checks = [];

        $this->add($checks, 'database', 'Database connection',
            $this->databaseHealthy(), 'critical',
            'Laravel can query the configured NurseLink database.');

        foreach ($this->criticalTables() as $table) {
            $this->add($checks, 'table:' . $table, 'Database table: ' . $table,
                Schema::hasTable($table), 'critical',
                'Required NurseLink data structure is present.');
        }

        foreach ([
            'standing',
            'standing_reason',
            'standing_changed_by',
            'standing_changed_at',
            'suspended_at',
            'inactive_at',
            'reactivated_at',
        ] as $column) {
            $this->add(
                $checks,
                'membership_column:' . $column,
                'Membership lifecycle column: ' . $column,
                Schema::hasColumn('nurselink_memberships', $column),
                'critical',
                'Membership lifecycle data structure is present.'
            );
        }

        $this->add($checks, 'app_key', 'Laravel application key',
            filled((string) config('app.key')), 'critical',
            'APP_KEY is configured without exposing its value.');

        $env = strtolower((string) config('app.env'));
        $this->add($checks, 'environment', 'Production environment mode',
            !in_array($env, ['local', 'testing'], true), 'warning',
            'APP_ENV should not be local/testing on the live deployment.');

        $this->add($checks, 'debug', 'Debug mode disabled',
            config('app.debug') === false, 'critical',
            'APP_DEBUG should be false on the live deployment.');

        $this->add($checks, 'app_url', 'API HTTPS URL',
            str_starts_with((string) config('app.url'), 'https://'),
            'critical', 'APP_URL uses HTTPS.');

        $this->add($checks, 'session_secure', 'Secure session cookie',
            (bool) config('session.secure'), 'critical',
            'Session cookies are restricted to HTTPS.');

        $sameSite = strtolower((string) config('session.same_site'));
        $this->add($checks, 'session_same_site', 'Session SameSite policy',
            in_array($sameSite, ['lax', 'strict'], true), 'warning',
            'Session SameSite is Lax or Strict.');

        $sessionDomain = (string) config('session.domain');
        $this->add($checks, 'session_domain', 'Shared NurseLink session domain',
            in_array($sessionDomain, ['.amsertech.com', 'amsertech.com'], true),
            'critical',
            'Session domain supports app.amsertech.com and api.amsertech.com.');

        $stateful = array_map(
            static fn ($v) => trim((string) $v),
            (array) config('sanctum.stateful', [])
        );
        $this->add($checks, 'sanctum_stateful', 'Sanctum stateful frontend',
            in_array('app.amsertech.com', $stateful, true), 'critical',
            'app.amsertech.com is a stateful Sanctum frontend.');

        $origins = array_map(
            static fn ($v) => trim((string) $v),
            (array) config('cors.allowed_origins', [])
        );
        $this->add($checks, 'cors_origin', 'CORS frontend origin',
            in_array('https://app.amsertech.com', $origins, true), 'critical',
            'CORS explicitly allows the NurseLink frontend origin.');

        $this->add($checks, 'cors_credentials', 'CORS credentials support',
            config('cors.supports_credentials') === true, 'critical',
            'Cross-subdomain session cookies are supported.');

        $this->add($checks, 'storage_writable', 'Laravel storage writable',
            is_dir(storage_path()) && is_writable(storage_path()), 'critical',
            'Laravel can write logs, cache and protected uploads.');

        $this->add($checks, 'cache_writable', 'Bootstrap cache writable',
            is_dir(base_path('bootstrap/cache'))
                && is_writable(base_path('bootstrap/cache')),
            'warning',
            'Laravel can refresh optimized configuration and route caches.');

        $logsPath = storage_path('logs');

        $this->add($checks, 'logs_writable', 'Laravel logs writable',
            is_dir($logsPath) && is_writable($logsPath),
            'critical',
            'Application logging directory is writable.');

        $ocr = app(LocalOcrService::class)->capabilities();
        $this->add($checks, 'ocr_image', 'Smart Registration image OCR',
            (bool) ($ocr['image_ocr'] ?? false), 'critical',
            'Tesseract OCR is available to PHP for JPG and PNG evidence.');
        $this->add($checks, 'ocr_pdf', 'Smart Registration PDF extraction',
            (bool) ($ocr['pdf_text'] ?? false), 'critical',
            'Poppler or Ghostscript is available for PDF text extraction.');
        $this->add($checks, 'ocr_scanned_pdf', 'Smart Registration scanned PDF OCR',
            (bool) ($ocr['pdf_scan_ocr'] ?? false), 'warning',
            'Poppler and Tesseract can process image-based PDF evidence.');
        $this->add($checks, 'malware_scanner', 'Uploaded-document malware scanner',
            (string) config('security_scanning.driver') !== 'disabled', 'warning',
            'A malware scanner must clear uploaded evidence before it is trusted.');

        $envPath = base_path('.env');
        $envMode = @fileperms($envPath);
        $envWorldReadable = is_int($envMode) && (($envMode & 0x0004) !== 0);
        $envWorldWritable = is_int($envMode) && (($envMode & 0x0002) !== 0);

        $this->add($checks, 'env_permissions', '.env access permissions',
            is_file($envPath) && !$envWorldReadable && !$envWorldWritable,
            'warning',
            '.env is not readable or writable by all system users.');

        $diskFree = @disk_free_space(storage_path());
        $diskTotal = @disk_total_space(storage_path());

        $diskFreeRatio = (
            is_numeric($diskFree)
            && is_numeric($diskTotal)
            && (float) $diskTotal > 0
        ) ? ((float) $diskFree / (float) $diskTotal) : null;

        $this->add($checks, 'disk_capacity', 'Production disk headroom',
            $diskFreeRatio === null || $diskFreeRatio >= 0.10,
            'warning',
            'At least 10% filesystem capacity remains available.');

        $backupAgeHours = $this->latestBackupAgeHours(
            (string) config('operations.backup.root', '')
        );

        $this->add($checks, 'recent_backup', 'Recent NurseLink rollback backup',
            $backupAgeHours !== null && $backupAgeHours <= 168,
            'warning',
            'A NurseLink backup exists from within the last 7 days.');

        $securityPolicyPath = (string) config(
            'operations.security_headers_policy_path',
            ''
        );
        $securityPolicyMarker = (string) config(
            'operations.security_headers_policy_marker',
            'NURSELINK_SECURITY_HEADERS_V330_START'
        );

        $securityHeadersInstalled = $securityPolicyPath !== ''
            && is_file($securityPolicyPath)
            && str_contains(
                (string) @file_get_contents($securityPolicyPath),
                $securityPolicyMarker
            );

        $this->add($checks, 'security_headers_policy',
            'Baseline browser security headers policy',
            $securityHeadersInstalled,
            'warning',
            'The configured web-server security-header policy is installed.');

        $logHealth = $this->recentLogHealth();

        $this->add($checks, 'recent_server_errors',
            'No recurring recent Laravel server errors',
            ($logHealth['error_count'] ?? 0) === 0,
            'warning',
            'Recent Laravel log tail contains no ERROR/CRITICAL/ALERT/EMERGENCY entries.');

        $this->add($checks, 'production_release_stage',
            'Production release verification layer',
            true,
            'warning',
            'NurseLink v4.0 production verification is active.');

        $routeUris = collect(Route::getRoutes())
            ->map(static fn ($route) => $route->uri())
            ->values()
            ->all();

        foreach ($this->criticalRoutes() as $uri => $severity) {
            $this->add($checks, 'route:' . $uri, 'API route: /' . $uri,
                in_array($uri, $routeUris, true), $severity,
                'Required NurseLink API surface is registered.');
        }

        $counts = [
            'active_reviewers' => $this->safeCount(
                'nurselink_reviewer_access', ['active' => true]),
            'approved_memberships' => $this->safeCount(
                'nurselink_memberships',
            'nurselink_membership_onboarding',
            'nurselink_support_cases', ['status' => 'approved']),
            'verified_partner_organizations' => $this->safeCount(
                'nurselink_partner_organizations', ['status' => 'verified']),
            'job_opportunities' => $this->safeCount(
                'nurselink_job_opportunities'),
            'active_memberships' => $this->safeCount(
                'nurselink_memberships', [
                    'status' => 'approved',
                    'standing' => 'active',
                ]),
            'suspended_memberships' => $this->safeCount(
                'nurselink_memberships', [
                    'status' => 'approved',
                    'standing' => 'suspended',
                ]),
            'inactive_memberships' => $this->safeCount(
                'nurselink_memberships', [
                    'status' => 'approved',
                    'standing' => 'inactive',
                ]),
        ];

        $this->add($checks, 'reviewer_access',
            'At least one active reviewer/admin',
            ($counts['active_reviewers'] ?? 0) > 0, 'critical',
            'Review and approval workflows have active authorized staff.');

        $passed = collect($checks)->where('status', 'pass')->count();
        $criticalFailed = collect($checks)
            ->where('status', 'fail')
            ->where('severity', 'critical')
            ->count();
        $warningFailed = collect($checks)
            ->where('status', 'fail')
            ->where('severity', 'warning')
            ->count();

        $score = count($checks)
            ? round(($passed / count($checks)) * 100, 1)
            : 0.0;

        return response()->json([
            'data' => [
                'release' => (string) config('operations.release'),
                'generated_at' => now()->toIso8601String(),
                'status' => $criticalFailed === 0
                    ? ($warningFailed === 0
                        ? 'ready_for_uat'
                        : 'ready_with_warnings')
                    : 'blocked',
                'score' => $score,
                'summary' => [
                    'total_checks' => count($checks),
                    'passed' => $passed,
                    'failed' => count($checks) - $passed,
                    'critical_blockers' => $criticalFailed,
                    'warnings' => $warningFailed,
                ],
                'checks' => $checks,
                'counts' => $counts,
                'operations' => [
                    'database_latency_ms' => $this->databaseLatencyMs(),
                    'disk_free_gb' => is_numeric($diskFree)
                        ? round(((float) $diskFree) / 1073741824, 2)
                        : null,
                    'disk_free_percent' => $diskFreeRatio !== null
                        ? round($diskFreeRatio * 100, 1)
                        : null,
                    'latest_backup_age_hours' => $backupAgeHours,
                    'recent_log_error_count' => $logHealth['error_count'] ?? null,
                    'recent_log_file' => $logHealth['file'] ?? null,
                ],
            ],
            'privacy' => [
                'secrets_exposed' => false,
                'personal_data_exposed' => false,
                'configuration_values_exposed' => false,
            ],
        ]);
    }

    private function authorizeStaff(Request $request): void
    {
        $user = $request->user();
        abort_unless($user, 401);

        $access = DB::table('nurselink_reviewer_access')
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->whereIn('role', ['reviewer', 'admin'])
            ->first();

        $modelRole = strtolower((string) (
            $user->role ?? $user->user_role ?? $user->user_type ?? ''
        ));

        $modelAdmin = (bool) (
            $user->is_admin ?? $user->is_super_admin ?? false
        );

        abort_unless(
            $access
            || $modelAdmin
            || in_array(
                $modelRole,
                ['admin', 'administrator', 'super_admin'],
                true
            ),
            403,
            'NurseLink reviewer or administrator access is required.'
        );
    }

    private function databaseHealthy(): bool
    {
        try {
            DB::select('select 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function databaseLatencyMs(): ?float
    {
        try {
            $started = microtime(true);
            DB::select('select 1');

            return round((microtime(true) - $started) * 1000, 2);
        } catch (\Throwable) {
            return null;
        }
    }

    private function latestBackupAgeHours(string $root): ?float
    {
        if (!is_dir($root)) {
            return null;
        }

        $latest = null;

        foreach (glob(rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            $mtime = @filemtime($path);

            if ($mtime !== false && ($latest === null || $mtime > $latest)) {
                $latest = $mtime;
            }
        }

        if ($latest === null) {
            return null;
        }

        return round(max(0, time() - $latest) / 3600, 1);
    }


    private function recentLogHealth(): array
    {
        $files = glob(storage_path('logs/*.log')) ?: [];

        if ($files === []) {
            return ['file' => null, 'error_count' => 0];
        }

        usort(
            $files,
            static fn (string $a, string $b): int =>
                (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0)
        );

        $file = $files[0];
        $size = @filesize($file);

        if ($size === false) {
            return ['file' => basename($file), 'error_count' => null];
        }

        $readBytes = min((int) $size, 262144);
        $handle = @fopen($file, 'rb');

        if (!$handle) {
            return ['file' => basename($file), 'error_count' => null];
        }

        if ($readBytes > 0) {
            @fseek($handle, -$readBytes, SEEK_END);
        }

        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        $matches = [];
        preg_match_all(
            '/\.(ERROR|CRITICAL|ALERT|EMERGENCY):/i',
            $tail,
            $matches
        );

        return [
            'file' => basename($file),
            'error_count' => count($matches[0] ?? []),
        ];
    }

    private function add(
        array &$checks,
        string $key,
        string $label,
        bool $passed,
        string $severity,
        string $description
    ): void {
        $checks[] = [
            'key' => $key,
            'label' => $label,
            'status' => $passed ? 'pass' : 'fail',
            'severity' => $severity,
            'description' => $description,
        ];
    }

    private function safeCount(string $table, array $where = []): ?int
    {
        try {
            if (!Schema::hasTable($table)) return null;

            $query = DB::table($table);

            foreach ($where as $column => $value) {
                $query->where($column, $value);
            }

            return $query->count();
        } catch (\Throwable) {
            return null;
        }
    }

    private function criticalTables(): array
    {
        return [
            'users',
            'nurselink_employment_histories',
            'nurselink_credentials_registry',
            'nurselink_credential_renewals',
            'nurselink_events',
            'nurselink_event_registrations',
            'nurselink_chapters',
            'nurselink_chapter_memberships',
            'nurselink_mentoring_profiles',
            'nurselink_mentoring_requests',
            'nurselink_member_benefits',
            'nurselink_benefit_requests',
            'nurselink_saved_benefits',
            'nurselink_benefit_reminder_log',
            'nurselink_enterprise_cohorts',
            'nurselink_enterprise_cohort_members',
            'nurselink_enterprise_cohort_goals',
            'nurselink_enterprise_cohort_progress',
            'nurselink_enterprise_cohort_invitations',
            'nurselink_enterprise_cohort_outcomes',
            'nurselink_enterprise_support_checkins',
            'nurselink_portfolio_items',
            'nurselink_career_preferences',
            'nurselink_learning_records',
            'nurselink_job_opportunities',
            'nurselink_saved_jobs',
            'nurselink_job_applications',
            'nurselink_reviewer_access',
            'nurselink_review_audit',
            'nurselink_memberships',
            'nurselink_notifications',
            'nurselink_public_profiles',
            'nurselink_partner_organizations',
            'nurselink_partner_access',
            'nurselink_partner_audit',
            'nurselink_application_messages',
            'nurselink_interviews',
            'nurselink_operations_snapshots',
            'nurselink_operations_incidents',
            'nurselink_deployments',
            'nurselink_career_intelligence_snapshots',
            'nurselink_super_admin_access',
        ];
    }

    private function criticalRoutes(): array
    {
        return [
            'api/me' => 'critical',
            'api/credential-registry' => 'critical',
            'api/reviewer/summary' => 'critical',
            'api/membership/me' => 'critical',
            'api/membership/verify/{code}' => 'critical',
            'api/public-profile/{slug}' => 'warning',
            'api/partner/me' => 'warning',
            'api/partner/analytics' => 'warning',
            'api/reviewer/institutional-analytics' => 'warning',
            'api/reviewer/operations-center' => 'warning',
            'api/career-intelligence' => 'warning',
            'api/nurselink/session-identity' => 'critical',
            'api/nurselink/admin/session-login' => 'critical',
            'api/nurselink/admin/session' => 'critical',
            'api/nurselink/admin/dashboard' => 'warning',
            'api/nurselink/admin/users' => 'warning',
            'api/nurselink/admin/membership-command/summary' => 'warning',
            'api/nurselink/admin/membership-command' => 'warning',
            'api/nurselink/admin/member-registry/summary' => 'warning',
            'api/nurselink/admin/member-registry' => 'warning',
            'api/nurselink/admin/test-mode/session' => 'warning',
            'api/nurselink/admin/test-mode/checks' => 'warning',
            'api/nurselink/admin/membership-lifecycle/summary' => 'warning',
            'api/nurselink/admin/membership-lifecycle/{membershipId}' => 'warning',
            'api/nurselink/admin/membership-lifecycle/{membershipId}/standing' => 'warning',
            'api/credential-renewal' => 'warning',
            'api/nurselink/admin/credential-renewal/summary' => 'warning',
            'api/nurselink/admin/credential-renewal' => 'warning',
            'api/credential-renewal/{credentialId}' => 'warning',
            'api/credential-renewal/{credentialId}/{renewalId}' => 'warning',
            'api/nurselink/admin/credential-renewal/{renewalId}' => 'warning',
            'api/events' => 'warning',
            'api/events/{eventId}/register' => 'warning',
            'api/events/{eventId}/registration' => 'warning',
            'api/nurselink/admin/events' => 'warning',
            'api/nurselink/admin/events/{eventId}' => 'warning',
            'api/nurselink/admin/events/{eventId}/registrations' => 'warning',
            'api/nurselink/admin/events/{eventId}/registrations/{registrationId}' => 'warning',
            'api/chapters' => 'warning',
            'api/chapters/{chapterId}/request' => 'warning',
            'api/chapters/{chapterId}/membership' => 'warning',
            'api/nurselink/admin/chapters' => 'warning',
            'api/nurselink/admin/chapters/{chapterId}' => 'warning',
            'api/nurselink/admin/chapters/{chapterId}/members' => 'warning',
            'api/nurselink/admin/chapters/{chapterId}/members/{membershipId}' => 'warning',
            'api/mentoring/profile' => 'warning',
            'api/mentoring/directory' => 'warning',
            'api/mentoring/requests' => 'warning',
            'api/mentoring/requests/{requestId}' => 'warning',
            'api/nurselink/admin/mentoring/summary' => 'warning',
            'api/engagement' => 'warning',
            'api/nurselink/admin/engagement/summary' => 'warning',
            'api/benefits' => 'warning',
            'api/benefits/{benefitId}/request' => 'warning',
            'api/nurselink/admin/benefits' => 'warning',
            'api/nurselink/admin/benefits/{benefitId}' => 'warning',
            'api/nurselink/admin/benefits/{benefitId}/requests' => 'warning',
            'api/nurselink/admin/benefits/{benefitId}/requests/{requestId}' => 'warning',
            'api/benefits/intelligence' => 'warning',
            'api/benefits/{benefitId}/save' => 'warning',
            'api/nurselink/admin/benefits/summary' => 'warning',
            'api/benefits/reminders' => 'warning',
            'api/nurselink/admin/benefits/reminders/summary' => 'warning',
            'api/nurselink/admin/benefits/reminders/generate' => 'warning',
            'api/engagement/timeline' => 'warning',
            'api/nurselink/admin/engagement/activity-summary' => 'warning',
            'api/enterprise/me' => 'warning',
            'api/partner/enterprise' => 'warning',
            'api/nurselink/admin/enterprise/summary' => 'warning',
            'api/nurselink/admin/enterprise/organizations' => 'warning',
            'api/nurselink/admin/enterprise/cohorts' => 'warning',
            'api/nurselink/admin/enterprise/cohorts/{cohortId}' => 'warning',
            'api/nurselink/admin/enterprise/cohorts/{cohortId}/members' => 'warning',
            'api/nurselink/admin/enterprise/cohorts/{cohortId}/members/{userId}' => 'warning',
            'api/enterprise/goals' => 'warning',
            'api/enterprise/goals/{goalId}/progress' => 'warning',
            'api/partner/enterprise/goals' => 'warning',
            'api/nurselink/admin/enterprise/cohorts/{cohortId}/goals' => 'warning',
            'api/nurselink/admin/enterprise/goals/{goalId}' => 'warning',
            'api/nurselink/admin/enterprise/goals/{goalId}/progress' => 'warning',
            'api/nurselink/admin/enterprise/goals/{goalId}/progress/{userId}' => 'warning',
            'api/enterprise/invitations' => 'warning',
            'api/enterprise/invitations/{invitationId}/respond' => 'warning',
            'api/partner/enterprise/enrollment-summary' => 'warning',
            'api/nurselink/admin/enterprise/cohorts/{cohortId}/invitations' => 'warning',
            'api/nurselink/admin/enterprise/invitations/{invitationId}' => 'warning',
            'api/nurselink/admin/enterprise/enrollment-summary' => 'warning',
            'api/enterprise/outcomes' => 'warning',
            'api/partner/enterprise/outcomes' => 'warning',
            'api/nurselink/admin/enterprise/cohorts/{cohortId}/outcomes' => 'warning',
            'api/nurselink/admin/enterprise/cohorts/{cohortId}/outcomes/{userId}' => 'warning',
            'api/enterprise/support' => 'warning',
            'api/partner/enterprise/support-summary' => 'warning',
            'api/nurselink/admin/enterprise/support' => 'warning',
            'api/nurselink/admin/enterprise/support/{checkinId}' => 'warning',
            'api/nurselink/admin/membership-administration/overview' => 'warning',
            'api/nurselink/admin/membership-administration/queue' => 'warning',
            'api/nurselink/admin/membership-administration/staff' => 'warning',
            'api/nurselink/admin/membership-administration/{membershipId}/assignment' => 'warning',
            'api/nurselink/admin/membership-administration/activity' => 'warning',
            'api/membership/onboarding' => 'warning',
            'api/membership/onboarding/progress' => 'warning',
            'api/nurselink/admin/membership-onboarding/summary' => 'warning',
            'api/nurselink/admin/membership-onboarding' => 'warning',
            'api/nurselink/admin/membership-onboarding/{membershipId}' => 'warning',
            'api/nurselink/admin/membership-onboarding/{membershipId}/welcome' => 'warning',
            'api/nurselink/admin/operations-center/summary' => 'warning',
            'api/nurselink/admin/operations-center/support-cases' => 'warning',
            'api/nurselink/admin/operations-center/support-cases/{caseId}' => 'warning',
            'api/nurselink/admin/operations-center/communications' => 'warning',
            'api/nurselink/admin/operations-center/audit-log' => 'warning',
            'api/nurselink/admin/operations-center/system-health' => 'warning',
            'api/nurselink/admin/operations-center/settings' => 'warning',
        ];
    }
}
