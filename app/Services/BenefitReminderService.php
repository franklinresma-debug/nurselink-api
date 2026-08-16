<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BenefitReminderService
{
    private const BENEFITS =
        'nurselink_member_benefits';

    private const SAVED =
        'nurselink_saved_benefits';

    private const MEMBERSHIPS =
        'nurselink_memberships';

    private const NOTIFICATIONS =
        'nurselink_notifications';

    private const LOG =
        'nurselink_benefit_reminder_log';

    public function generate(): array
    {
        foreach (
            [
                self::BENEFITS,
                self::SAVED,
                self::MEMBERSHIPS,
                self::NOTIFICATIONS,
                self::LOG,
            ] as $table
        ) {
            if (! Schema::hasTable($table)) {
                return [
                    'eligible' => 0,
                    'sent_30_day' => 0,
                    'sent_7_day' => 0,
                    'skipped_duplicate' => 0,
                    'missing_table' => $table,
                ];
            }
        }

        $now = CarbonImmutable::now();
        $inThirtyDays = $now->addDays(30);

        $rows = DB::table(
            self::SAVED . ' as sb'
        )
            ->join(
                self::BENEFITS . ' as b',
                'b.id',
                '=',
                'sb.benefit_id'
            )
            ->join(
                self::MEMBERSHIPS . ' as m',
                'm.user_id',
                '=',
                'sb.user_id'
            )
            ->where(
                'm.status',
                'approved'
            )
            ->where(
                'm.standing',
                'active'
            )
            ->where(
                'b.status',
                'published'
            )
            ->whereNotNull(
                'b.ends_at'
            )
            ->where(
                'b.ends_at',
                '>=',
                $now
            )
            ->where(
                'b.ends_at',
                '<=',
                $inThirtyDays
            )
            ->select([
                'sb.user_id',
                'b.id as benefit_id',
                'b.title',
                'b.ends_at',
            ])
            ->orderBy('b.ends_at')
            ->limit(5000)
            ->get();

        $result = [
            'eligible' =>
                $rows->count(),
            'sent_30_day' => 0,
            'sent_7_day' => 0,
            'skipped_duplicate' => 0,
        ];

        foreach ($rows as $row) {
            $endsAt =
                CarbonImmutable::parse(
                    $row->ends_at
                );

            $days = max(
                0,
                $now->startOfDay()
                    ->diffInDays(
                        $endsAt->startOfDay(),
                        false
                    )
            );

            $kind = $days <= 7
                ? 'saved_ending_7'
                : 'saved_ending_30';

            $duplicate = DB::table(
                self::LOG
            )
                ->where(
                    'user_id',
                    $row->user_id
                )
                ->where(
                    'benefit_id',
                    $row->benefit_id
                )
                ->where(
                    'reminder_kind',
                    $kind
                )
                ->exists();

            if ($duplicate) {
                $result[
                    'skipped_duplicate'
                ]++;
                continue;
            }

            $title = $days <= 7
                ? 'Saved benefit ending soon'
                : 'Saved benefit availability reminder';

            $message =
                $row->title
                . ' is currently listed through '
                . $endsAt->toDateString()
                . '. Availability and eligibility remain subject to the listed terms and provider requirements.';

            $notificationId = DB::table(
                self::NOTIFICATIONS
            )->insertGetId([
                'user_id' =>
                    $row->user_id,
                'type' =>
                    'benefit.saved.'
                    . $kind,
                'severity' =>
                    $days <= 7
                        ? 'warning'
                        : 'info',
                'title' => $title,
                'message' => $message,
                'action_url' =>
                    '/nurselink-benefits.html',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table(self::LOG)
                ->insert([
                    'user_id' =>
                        $row->user_id,
                    'benefit_id' =>
                        $row->benefit_id,
                    'reminder_kind' =>
                        $kind,
                    'notification_id' =>
                        $notificationId,
                    'sent_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($days <= 7) {
                $result[
                    'sent_7_day'
                ]++;
            } else {
                $result[
                    'sent_30_day'
                ]++;
            }
        }

        return $result;
    }

    public function summary(): array
    {
        if (
            ! Schema::hasTable(self::LOG)
        ) {
            return [
                'sent_30_day' => 0,
                'sent_7_day' => 0,
                'total_sent' => 0,
            ];
        }

        $counts = DB::table(
            self::LOG
        )
            ->select(
                'reminder_kind',
                DB::raw(
                    'COUNT(*) AS aggregate_count'
                )
            )
            ->groupBy('reminder_kind')
            ->get()
            ->mapWithKeys(
                fn ($row): array => [
                    (string)
                        $row->reminder_kind
                        => (int)
                            $row
                                ->aggregate_count,
                ]
            )
            ->all();

        $thirty =
            $counts[
                'saved_ending_30'
            ] ?? 0;

        $seven =
            $counts[
                'saved_ending_7'
            ] ?? 0;

        return [
            'sent_30_day' =>
                $thirty,
            'sent_7_day' =>
                $seven,
            'total_sent' =>
                $thirty + $seven,
        ];
    }
}
