<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('application_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id')->index();
            $table->uuid('uploaded_by_user_id')->nullable()->index();
            $table->string('category', 60)->index();
            $table->string('original_name', 255);
            $table->string('stored_name', 255);
            $table->string('disk', 60)->default('private');
            $table->string('path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64)->index();
            $table->string('upload_status', 30)->default('received')->index();
            $table->string('malware_scan_status', 30)->default('pending')->index();
            $table->timestampTz('malware_scanned_at')->nullable();
            $table->string('extraction_status', 30)->default('not_started')->index();
            $table->timestampTz('extracted_at')->nullable();
            $table->timestampsTz();

            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('document_extraction_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_document_id')->index();
            $table->string('status', 30)->default('queued')->index();
            $table->string('extractor', 80)->default('manual');
            $table->string('extractor_version', 80)->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->json('result_meta')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('application_document_id')->references('id')->on('application_documents')->cascadeOnDelete();
        });

        Schema::create('extracted_facts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id')->index();
            $table->uuid('application_document_id')->nullable()->index();
            $table->uuid('extraction_job_id')->nullable()->index();
            $table->string('field_path', 220)->index();
            $table->text('proposed_value')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->unsignedSmallInteger('source_page')->nullable();
            $table->string('source_label', 180)->nullable();
            $table->string('member_status', 30)->default('proposed')->index();
            $table->text('member_value')->nullable();
            $table->uuid('member_confirmed_by_user_id')->nullable()->index();
            $table->timestampTz('member_confirmed_at')->nullable();
            $table->string('evidence_status', 30)->default('unassessed')->index();
            $table->string('verification_status', 30)->default('unverified')->index();
            $table->uuid('verified_by_user_id')->nullable()->index();
            $table->text('verification_note')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();

            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->foreign('application_document_id')->references('id')->on('application_documents')->nullOnDelete();
            $table->foreign('extraction_job_id')->references('id')->on('document_extraction_jobs')->nullOnDelete();
            $table->foreign('member_confirmed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('verified_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('application_data_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id')->index();
            $table->string('field_path', 220)->index();
            $table->string('state', 30)->default('missing')->index();
            $table->text('message')->nullable();
            $table->json('rule_meta')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['application_id', 'field_path']);
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_data_reviews');
        Schema::dropIfExists('extracted_facts');
        Schema::dropIfExists('document_extraction_jobs');
        Schema::dropIfExists('application_documents');
    }
};
