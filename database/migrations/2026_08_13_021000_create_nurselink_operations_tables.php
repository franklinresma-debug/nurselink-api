<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nurselink_operations_snapshots')) {
            Schema::create('nurselink_operations_snapshots', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('status', 20)->default('healthy');
                $table->decimal('database_latency_ms', 10, 2)->nullable();
                $table->decimal('disk_free_percent', 6, 2)->nullable();
                $table->decimal('backup_age_hours', 10, 2)->nullable();
                $table->unsignedInteger('recent_log_error_count')->nullable();
                $table->boolean('security_headers_ok')->default(false);
                $table->string('source', 40)->default('manual');
                $table->char('created_by', 36)->nullable();
                $table->timestamp('captured_at')->useCurrent();
                $table->timestamps();
                $table->index(['captured_at', 'status'], 'nl_ops_snapshots_time_status_idx');
            });
        }

        if (!Schema::hasTable('nurselink_operations_incidents')) {
            Schema::create('nurselink_operations_incidents', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('severity', 20)->default('warning');
                $table->string('status', 20)->default('open');
                $table->string('title', 160);
                $table->text('description')->nullable();
                $table->string('source', 80)->nullable();
                $table->char('opened_by', 36)->nullable();
                $table->char('resolved_by', 36)->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'severity'], 'nl_ops_incidents_status_severity_idx');
            });
        }

        if (!Schema::hasTable('nurselink_deployments')) {
            Schema::create('nurselink_deployments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('release', 40);
                $table->string('stage', 30)->default('production');
                $table->string('backup_label', 190)->nullable();
                $table->string('source', 50)->default('installer');
                $table->char('deployed_by', 36)->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('deployed_at')->useCurrent();
                $table->timestamps();
                $table->index(['deployed_at', 'release'], 'nl_deployments_time_release_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_deployments');
        Schema::dropIfExists('nurselink_operations_incidents');
        Schema::dropIfExists('nurselink_operations_snapshots');
    }
};
