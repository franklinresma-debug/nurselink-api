<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_memberships')) {
            return;
        }

        Schema::table('nurselink_memberships', function (Blueprint $table): void {
            if (! Schema::hasColumn('nurselink_memberships', 'standing')) {
                $table->string('standing', 24)
                    ->nullable()
                    ->after('status')
                    ->index();
            }

            if (! Schema::hasColumn('nurselink_memberships', 'standing_reason')) {
                $table->text('standing_reason')
                    ->nullable()
                    ->after('standing');
            }

            if (! Schema::hasColumn('nurselink_memberships', 'standing_changed_by')) {
                $table->string('standing_changed_by', 191)
                    ->nullable()
                    ->after('standing_reason');
            }

            if (! Schema::hasColumn('nurselink_memberships', 'standing_changed_at')) {
                $table->timestamp('standing_changed_at')
                    ->nullable()
                    ->after('standing_changed_by');
            }

            if (! Schema::hasColumn('nurselink_memberships', 'suspended_at')) {
                $table->timestamp('suspended_at')
                    ->nullable()
                    ->after('standing_changed_at');
            }

            if (! Schema::hasColumn('nurselink_memberships', 'inactive_at')) {
                $table->timestamp('inactive_at')
                    ->nullable()
                    ->after('suspended_at');
            }

            if (! Schema::hasColumn('nurselink_memberships', 'reactivated_at')) {
                $table->timestamp('reactivated_at')
                    ->nullable()
                    ->after('inactive_at');
            }
        });

        DB::table('nurselink_memberships')
            ->where('status', 'approved')
            ->whereNull('standing')
            ->update([
                'standing' => 'active',
                'standing_changed_at' => DB::raw(
                    'COALESCE(approved_at, updated_at, created_at)'
                ),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('nurselink_memberships')) {
            return;
        }

        $columns = array_values(array_filter([
            'standing',
            'standing_reason',
            'standing_changed_by',
            'standing_changed_at',
            'suspended_at',
            'inactive_at',
            'reactivated_at',
        ], fn (string $column): bool =>
            Schema::hasColumn('nurselink_memberships', $column)
        ));

        if ($columns !== []) {
            Schema::table(
                'nurselink_memberships',
                fn (Blueprint $table): mixed =>
                    $table->dropColumn($columns)
            );
        }
    }
};
