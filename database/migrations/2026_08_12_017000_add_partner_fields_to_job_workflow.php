<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nurselink_job_opportunities')
            && ! Schema::hasColumn('nurselink_job_opportunities', 'partner_organization_id')) {
            Schema::table('nurselink_job_opportunities', function (Blueprint $table): void {
                $table->unsignedBigInteger('partner_organization_id')->nullable()->after('id');
                $table->index('partner_organization_id');
            });
        }

        if (Schema::hasTable('nurselink_job_applications')) {
            Schema::table('nurselink_job_applications', function (Blueprint $table): void {
                if (! Schema::hasColumn('nurselink_job_applications', 'partner_notes')) {
                    $table->text('partner_notes')->nullable();
                }

                if (! Schema::hasColumn('nurselink_job_applications', 'partner_reviewed_by')) {
                    $table->string('partner_reviewed_by', 191)->nullable();
                }

                if (! Schema::hasColumn('nurselink_job_applications', 'partner_reviewed_at')) {
                    $table->timestamp('partner_reviewed_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('nurselink_job_applications')) {
            Schema::table('nurselink_job_applications', function (Blueprint $table): void {
                foreach (['partner_notes', 'partner_reviewed_by', 'partner_reviewed_at'] as $column) {
                    if (Schema::hasColumn('nurselink_job_applications', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('nurselink_job_opportunities')
            && Schema::hasColumn('nurselink_job_opportunities', 'partner_organization_id')) {
            Schema::table('nurselink_job_opportunities', function (Blueprint $table): void {
                $table->dropIndex(['partner_organization_id']);
                $table->dropColumn('partner_organization_id');
            });
        }
    }
};
