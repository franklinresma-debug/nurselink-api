<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nurselink_credentials_registry')) {
            Schema::table('nurselink_credentials_registry', function (Blueprint $table): void {
                if (! Schema::hasColumn('nurselink_credentials_registry', 'review_notes')) {
                    $table->text('review_notes')->nullable()->after('notes');
                }

                if (! Schema::hasColumn('nurselink_credentials_registry', 'reviewed_by')) {
                    $table->string('reviewed_by', 191)->nullable()->after('review_notes');
                }

                if (! Schema::hasColumn('nurselink_credentials_registry', 'reviewed_at')) {
                    $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
                }
            });
        }

        if (Schema::hasTable('nurselink_job_applications')) {
            Schema::table('nurselink_job_applications', function (Blueprint $table): void {
                if (! Schema::hasColumn('nurselink_job_applications', 'reviewer_notes')) {
                    $table->text('reviewer_notes')->nullable()->after('cover_note');
                }

                if (! Schema::hasColumn('nurselink_job_applications', 'reviewed_by')) {
                    $table->string('reviewed_by', 191)->nullable()->after('reviewer_notes');
                }

                if (! Schema::hasColumn('nurselink_job_applications', 'reviewed_at')) {
                    $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
                }
            });
        }

        if (Schema::hasTable('nurselink_job_opportunities')) {
            Schema::table('nurselink_job_opportunities', function (Blueprint $table): void {
                if (! Schema::hasColumn('nurselink_job_opportunities', 'verified_by')) {
                    $table->string('verified_by', 191)->nullable()->after('source_label');
                }

                if (! Schema::hasColumn('nurselink_job_opportunities', 'verified_at')) {
                    $table->timestamp('verified_at')->nullable()->after('verified_by');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('nurselink_credentials_registry')) {
            Schema::table('nurselink_credentials_registry', function (Blueprint $table): void {
                $drop = [];
                foreach (['review_notes', 'reviewed_by', 'reviewed_at'] as $column) {
                    if (Schema::hasColumn('nurselink_credentials_registry', $column)) {
                        $drop[] = $column;
                    }
                }
                if ($drop !== []) $table->dropColumn($drop);
            });
        }

        if (Schema::hasTable('nurselink_job_applications')) {
            Schema::table('nurselink_job_applications', function (Blueprint $table): void {
                $drop = [];
                foreach (['reviewer_notes', 'reviewed_by', 'reviewed_at'] as $column) {
                    if (Schema::hasColumn('nurselink_job_applications', $column)) {
                        $drop[] = $column;
                    }
                }
                if ($drop !== []) $table->dropColumn($drop);
            });
        }

        if (Schema::hasTable('nurselink_job_opportunities')) {
            Schema::table('nurselink_job_opportunities', function (Blueprint $table): void {
                $drop = [];
                foreach (['verified_by', 'verified_at'] as $column) {
                    if (Schema::hasColumn('nurselink_job_opportunities', $column)) {
                        $drop[] = $column;
                    }
                }
                if ($drop !== []) $table->dropColumn($drop);
            });
        }
    }
};
