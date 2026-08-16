<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('analytics_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope', 80)->default('executive');
            $table->string('metric_version', 20)->default('1.0');
            $table->date('snapshot_date');
            $table->json('metrics');
            $table->json('dimensions')->nullable();
            $table->timestampTz('captured_at');
            $table->timestampsTz();
            $table->unique(['scope','metric_version','snapshot_date']);
            $table->index(['scope','snapshot_date']);
        });

        Schema::create('report_export_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('report_type', 80);
            $table->json('filters')->nullable();
            $table->string('format', 20)->default('csv');
            $table->string('status', 30)->default('queued');
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('storage_disk')->nullable();
            $table->text('storage_path')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampsTz();
            $table->index(['requested_by','created_at']);
            $table->index(['status','created_at']);
        });

        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('request_no', 32)->unique();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('request_type', 40);
            $table->string('status', 30)->default('submitted');
            $table->text('details')->nullable();
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('export_disk')->nullable();
            $table->text('export_path')->nullable();
            $table->timestampTz('export_expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id','created_at']);
            $table->index(['status','request_type']);
        });

        Schema::create('operational_check_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('environment', 60);
            $table->string('release', 60);
            $table->string('status', 20);
            $table->json('checks');
            $table->timestampTz('checked_at');
            $table->timestampsTz();
            $table->index(['environment','checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_check_runs');
        Schema::dropIfExists('privacy_requests');
        Schema::dropIfExists('report_export_jobs');
        Schema::dropIfExists('analytics_snapshots');
    }
};
