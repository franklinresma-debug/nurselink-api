<?php

namespace App\Console\Commands;

use App\Services\MembershipLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NurseLinkLifecycleDiagnostic extends Command
{
    protected $signature = 'nurselink:lifecycle-diagnostic';
    protected $description = 'Read-only integrity checks for the NurseLink membership lifecycle';

    public function handle(): int
    {
        $this->info('NurseLink Membership Lifecycle Diagnostic');
        $this->line('Database: ' . DB::connection()->getDatabaseName());
        $this->newLine();

        $checks = [];

        $checks['Approved memberships'] =
            DB::table('nurselink_memberships')
                ->where('status', 'approved')
                ->count();

        $checks['Approved missing member number'] =
            DB::table('nurselink_memberships')
                ->where('status', 'approved')
                ->where(function ($q) {
                    $q->whereNull('member_number')
                        ->orWhere('member_number', '');
                })
                ->count();

        $checks['Approved missing onboarding'] =
            DB::table('nurselink_memberships as m')
                ->leftJoin(
                    'nurselink_membership_onboarding as o',
                    'o.membership_id',
                    '=',
                    'm.id'
                )
                ->where('m.status', 'approved')
                ->whereNull('o.id')
                ->count();

        $checks['Duplicate onboarding memberships'] =
            DB::table('nurselink_membership_onboarding')
                ->select('membership_id')
                ->groupBy('membership_id')
                ->havingRaw('COUNT(*) > 1')
                ->count();

        $checks['Approved with invalid standing'] =
            DB::table('nurselink_memberships')
                ->where('status', 'approved')
                ->where(function ($q) {
                    $q->whereNull('standing')
                        ->orWhereNotIn(
                            'standing',
                            MembershipLifecycleService::STANDINGS
                        );
                })
                ->count();

        $checks['Onboarding without membership'] =
            DB::table('nurselink_membership_onboarding as o')
                ->leftJoin(
                    'nurselink_memberships as m',
                    'm.id',
                    '=',
                    'o.membership_id'
                )
                ->whereNull('m.id')
                ->count();

        $checks['Completed onboarding'] =
            DB::table('nurselink_membership_onboarding')
                ->where('status', 'completed')
                ->count();

        $checks['Completed onboarding missing completed_at'] =
            DB::table('nurselink_membership_onboarding')
                ->where('status', 'completed')
                ->whereNull('completed_at')
                ->count();

        if (Schema::hasTable('nurselink_membership_status_history')) {
            $checks['Approved memberships missing approval history'] =
                DB::table('nurselink_memberships as m')
                    ->leftJoin(
                        'nurselink_membership_status_history as h',
                        function ($join) {
                            $join->on('h.membership_id', '=', 'm.id')
                                ->where('h.to_status', '=', 'approved');
                        }
                    )
                    ->where('m.status', 'approved')
                    ->whereNull('h.id')
                    ->count();
        }

        foreach ($checks as $label => $count) {
            $expectedZero = ! in_array($label, [
                'Approved memberships',
                'Completed onboarding',
            ], true);

            if ($expectedZero && $count > 0) {
                $this->error(sprintf('%-48s %d', $label, $count));
            } else {
                $this->line(sprintf('%-48s %d', $label, $count));
            }
        }

        $problems = collect($checks)
            ->except([
                'Approved memberships',
                'Completed onboarding',
            ])
            ->filter(fn ($count) => $count > 0)
            ->count();

        $this->newLine();

        if ($problems === 0) {
            $this->info('PASS: no lifecycle integrity problems detected.');
            return self::SUCCESS;
        }

        $this->error("FAIL: {$problems} lifecycle integrity check(s) require review.");
        return self::FAILURE;
    }
}
