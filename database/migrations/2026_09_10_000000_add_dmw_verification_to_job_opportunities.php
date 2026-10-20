<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_job_opportunities')) return;

        Schema::table('nurselink_job_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('nurselink_job_opportunities', 'source_authority')) {
                $table->string('source_authority', 40)->nullable()->after('source_label');
            }
            if (! Schema::hasColumn('nurselink_job_opportunities', 'agency_name')) {
                $table->string('agency_name', 190)->nullable()->after('source_authority');
            }
            if (! Schema::hasColumn('nurselink_job_opportunities', 'dmw_job_order_number')) {
                $table->string('dmw_job_order_number', 120)->nullable()->after('agency_name');
            }
            if (! Schema::hasColumn('nurselink_job_opportunities', 'dmw_job_order_url')) {
                $table->string('dmw_job_order_url', 512)->nullable()->after('dmw_job_order_number');
            }
            if (! Schema::hasColumn('nurselink_job_opportunities', 'dmw_agency_checked_at')) {
                $table->timestamp('dmw_agency_checked_at')->nullable()->after('dmw_job_order_url');
            }
            if (! Schema::hasColumn('nurselink_job_opportunities', 'dmw_job_order_checked_at')) {
                $table->timestamp('dmw_job_order_checked_at')->nullable()->after('dmw_agency_checked_at');
            }
            if (! Schema::hasColumn('nurselink_job_opportunities', 'source_verification_status')) {
                $table->string('source_verification_status', 30)->nullable()->after('dmw_job_order_checked_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nurselink_job_opportunities')) return;

        Schema::table('nurselink_job_opportunities', function (Blueprint $table): void {
            $columns = ['source_authority', 'agency_name', 'dmw_job_order_number', 'dmw_job_order_url', 'dmw_agency_checked_at', 'dmw_job_order_checked_at', 'source_verification_status'];
            $existing = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn('nurselink_job_opportunities', $column)));
            if ($existing !== []) $table->dropColumn($existing);
        });
    }
};
