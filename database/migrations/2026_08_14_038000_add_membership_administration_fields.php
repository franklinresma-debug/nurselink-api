<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_memberships')) {
            return;
        }

        Schema::table(
            'nurselink_memberships',
            function (Blueprint $table): void {
                if (! Schema::hasColumn(
                    'nurselink_memberships',
                    'assigned_reviewer_user_id'
                )) {
                    $table->string(
                        'assigned_reviewer_user_id',
                        191
                    )->nullable()->index();
                }

                if (! Schema::hasColumn(
                    'nurselink_memberships',
                    'review_priority'
                )) {
                    $table->string(
                        'review_priority',
                        20
                    )->default('normal')->index();
                }

                if (! Schema::hasColumn(
                    'nurselink_memberships',
                    'review_due_at'
                )) {
                    $table->timestamp(
                        'review_due_at'
                    )->nullable()->index();
                }

                if (! Schema::hasColumn(
                    'nurselink_memberships',
                    'review_started_at'
                )) {
                    $table->timestamp(
                        'review_started_at'
                    )->nullable();
                }

                if (! Schema::hasColumn(
                    'nurselink_memberships',
                    'last_admin_action_at'
                )) {
                    $table->timestamp(
                        'last_admin_action_at'
                    )->nullable()->index();
                }
            }
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('nurselink_memberships')) {
            return;
        }

        Schema::table(
            'nurselink_memberships',
            function (Blueprint $table): void {
                foreach ([
                    'assigned_reviewer_user_id',
                    'review_priority',
                    'review_due_at',
                    'review_started_at',
                    'last_admin_action_at',
                ] as $column) {
                    if (Schema::hasColumn(
                        'nurselink_memberships',
                        $column
                    )) {
                        $table->dropColumn($column);
                    }
                }
            }
        );
    }
};
