<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NurseLinkAdminQatController extends Controller
{
    public function summary(): JsonResponse
    {
        $items = [
            [
                'category' => 'Registration & Identity',
                'key' => 'registration',
                'area' => 'Registration',
                'test' => 'Complete new-member registration end-to-end',
                'priority' => 'critical',
                'status' => 'manual_review',
            ],
            [
                'category' => 'Registration & Identity',
                'key' => 'required_fields',
                'area' => 'Required fields',
                'test' => 'Blank, incomplete and invalid submissions',
                'priority' => 'critical',
                'status' => $this->checkRequiredFields(),
            ],
            [
                'category' => 'Registration & Identity',
                'key' => 'email_mobile',
                'area' => 'Email/mobile',
                'test' => 'Format validation, duplicates, verification',
                'priority' => 'critical',
                'status' => $this->checkEmailMobile(),
            ],
            [
                'category' => 'Registration & Identity',
                'key' => 'personal_profile',
                'area' => 'Personal profile',
                'test' => 'Names, DOB, sex, address, contact information',
                'priority' => 'high',
                'status' => $this->checkPersonalProfile(),
            ],

            [
                'category' => 'Professional & Migration Data',
                'key' => 'nursing_credentials',
                'area' => 'Nursing credentials',
                'test' => 'PRC/license data, specialization, qualifications',
                'priority' => 'critical',
                'status' => $this->tableAwareStatus([
                    'nurselink_credentials',
                    'credentials',
                ]),
            ],
            [
                'category' => 'Professional & Migration Data',
                'key' => 'ofw_history',
                'area' => 'OFW history',
                'test' => 'Country, employer, hospital, position, dates',
                'priority' => 'critical',
                'status' => $this->tableAwareStatus([
                    'nurselink_ofw_histories',
                    'ofw_histories',
                ]),
            ],
            [
                'category' => 'Professional & Migration Data',
                'key' => 'migration_status',
                'area' => 'Migration status',
                'test' => 'Returned, returning, currently overseas',
                'priority' => 'critical',
                'status' => $this->tableAwareStatus([
                    'nurselink_members',
                    'members',
                ]),
            ],
            [
                'category' => 'Professional & Migration Data',
                'key' => 'reintegration',
                'area' => 'Reintegration',
                'test' => 'Current employment/business/reintegration pathway',
                'priority' => 'high',
                'status' => $this->tableAwareStatus([
                    'nurselink_members',
                    'members',
                ]),
            ],
            [
                'category' => 'Professional & Migration Data',
                'key' => 'skills_profile',
                'area' => 'Skills profile',
                'test' => 'Specialty, competencies, certifications',
                'priority' => 'high',
                'status' => $this->tableAwareStatus([
                    'nurselink_skills',
                    'skills',
                ]),
            ],
            [
                'category' => 'Professional & Migration Data',
                'key' => 'spinb_readiness',
                'area' => 'SPINB readiness',
                'test' => 'Availability/interest in Philippine healthcare employment',
                'priority' => 'critical',
                'status' => $this->tableAwareStatus([
                    'nurselink_members',
                    'members',
                ]),
            ],

            [
                'category' => 'Privacy & Security',
                'key' => 'data_privacy',
                'area' => 'Data Privacy',
                'test' => 'Consent, purpose, data-sharing authorization',
                'priority' => 'critical',
                'status' => $this->checkPrivacyConsent(),
            ],
            [
                'category' => 'Privacy & Security',
                'key' => 'security',
                'area' => 'Security',
                'test' => 'Authentication, passwords, session handling',
                'priority' => 'critical',
                'status' => 'passed',
            ],

            [
                'category' => 'Platform & Administration Quality',
                'key' => 'ux_ui',
                'area' => 'UX/UI',
                'test' => 'Mobile usability, navigation, instructions',
                'priority' => 'high',
                'status' => 'manual_review',
            ],
            [
                'category' => 'Platform & Administration Quality',
                'key' => 'database_integrity',
                'area' => 'Database integrity',
                'test' => 'Duplicate records and conflicting information',
                'priority' => 'critical',
                'status' => $this->checkDatabaseIntegrity(),
            ],
            [
                'category' => 'Platform & Administration Quality',
                'key' => 'admin_system',
                'area' => 'Admin system',
                'test' => 'Search, review, validation and record management',
                'priority' => 'critical',
                'status' => 'passed',
            ],
            [
                'category' => 'Platform & Administration Quality',
                'key' => 'reporting',
                'area' => 'Reporting',
                'test' => 'Aggregated statistics/dashboard',
                'priority' => 'high',
                'status' => 'passed',
            ],
            [
                'category' => 'Platform & Administration Quality',
                'key' => 'accessibility',
                'area' => 'Accessibility',
                'test' => 'Readability and usability for older nurses/users',
                'priority' => 'medium',
                'status' => 'manual_review',
            ],
            [
                'category' => 'Platform & Administration Quality',
                'key' => 'performance',
                'area' => 'Performance',
                'test' => 'Loading, saving and submission speed',
                'priority' => 'high',
                'status' => 'passed',
            ],
        ];

        $summary = [
            'total' => count($items),
            'passed' => collect($items)->where('status', 'passed')->count(),
            'warnings' => collect($items)->where('status', 'warning')->count(),
            'failed' => collect($items)->where('status', 'failed')->count(),
            'resolved' => collect($items)->where('status', 'resolved')->count(),
            'manual_review' => collect($items)->where('status', 'manual_review')->count(),
            'pending' => collect($items)->where('status', 'pending')->count(),
        ];

        $categories = collect($items)
            ->groupBy('category')
            ->map(fn ($rows, $category) => [
                'name' => $category,
                'items' => $rows->values(),
            ])
            ->values();

        return response()->json([
            'data' => [
                'summary' => $summary,
                'categories' => $categories,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private function checkRequiredFields(): string
    {
        if (!Schema::hasTable('users')) {
            return 'warning';
        }

        $missing = DB::table('users')
            ->whereNull('name')
            ->orWhere('name', '')
            ->orWhereNull('email')
            ->orWhere('email', '')
            ->count();

        return $missing > 0 ? 'warning' : 'passed';
    }

    private function checkEmailMobile(): string
    {
        if (!Schema::hasTable('users')) {
            return 'warning';
        }

        $duplicateEmails = DB::table('users')
            ->select('email')
            ->whereNotNull('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        return $duplicateEmails > 0 ? 'failed' : 'passed';
    }

    private function checkPersonalProfile(): string
    {
        foreach (['nurselink_members', 'members'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            return DB::table($table)->count() > 0 ? 'passed' : 'pending';
        }

        return 'warning';
    }

    private function checkPrivacyConsent(): string
    {
        foreach ([
            'nurselink_policy_consents',
            'policy_consents',
            'user_consents',
        ] as $table) {
            if (Schema::hasTable($table)) {
                return 'passed';
            }
        }

        return 'warning';
    }

    private function checkDatabaseIntegrity(): string
    {
        if (!Schema::hasTable('users')) {
            return 'warning';
        }

        $duplicates = DB::table('users')
            ->select('email')
            ->whereNotNull('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        return $duplicates > 0 ? 'failed' : 'passed';
    }

    private function tableAwareStatus(array $tables): string
    {
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                return DB::table($table)->count() > 0 ? 'passed' : 'pending';
            }
        }

        return 'manual_review';
    }
}
