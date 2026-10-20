<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nurselink_bulk_intake_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('encoder_account_id')->nullable()->index();
            $table->uuid('created_by_user_id')->nullable()->index();

            $table->string('name', 190);
            $table->string('status', 40)->default('draft')->index();

            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->json('options')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_completed_at')->nullable();
            $table->timestamps();

            $table->foreign('encoder_account_id')
                ->references('id')
                ->on('temporary_encoder_accounts')
                ->nullOnDelete();
        });

        Schema::create('nurselink_bulk_intake_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id')->index();

            $table->string('original_name', 255);
            $table->string('storage_path', 500);
            $table->string('mime_type', 190);
            $table->unsignedBigInteger('file_size');
            $table->string('sha256', 64)->index();

            $table->string('extension', 20)->nullable();
            $table->string('document_type', 80)->nullable()->index();

            $table->string('security_status', 40)->default('pending')->index();
            $table->text('security_message')->nullable();
            $table->timestamp('security_scanned_at')->nullable();

            $table->string('extraction_status', 40)->default('queued')->index();
            $table->text('extraction_message')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->json('extracted_fields')->nullable();
            $table->timestamp('extracted_at')->nullable();

            $table->string('grouping_key', 190)->nullable()->index();
            $table->unsignedBigInteger('candidate_id')->nullable()->index();

            $table->timestamps();

            $table->foreign('batch_id')
                ->references('id')
                ->on('nurselink_bulk_intake_batches')
                ->cascadeOnDelete();

            $table->unique(['batch_id', 'sha256']);
        });

        Schema::create('nurselink_bulk_intake_candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id')->index();

            $table->string('status', 40)->default('review_required')->index();

            $table->uuid('matched_user_id')->nullable()->index();
            $table->unsignedBigInteger('matched_membership_id')->nullable()->index();

            $table->decimal('confidence', 5, 4)->nullable()->index();
            $table->string('duplicate_status', 40)->default('unknown')->index();
            $table->json('duplicate_matches')->nullable();

            $table->string('display_name', 255)->nullable();
            $table->string('email', 255)->nullable()->index();
            $table->string('phone', 80)->nullable()->index();

            $table->string('first_name', 160)->nullable();
            $table->string('middle_name', 160)->nullable();
            $table->string('last_name', 160)->nullable();
            $table->date('birth_date')->nullable();

            $table->string('sex', 40)->nullable();
            $table->string('nationality', 120)->nullable();

            $table->string('address_line1', 255)->nullable();
            $table->string('city', 160)->nullable();
            $table->string('province', 160)->nullable();
            $table->string('country', 120)->nullable();

            $table->string('professional_title', 190)->nullable();
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->string('current_position', 190)->nullable();
            $table->string('current_employer', 255)->nullable();
            $table->string('specialty', 190)->nullable();

            $table->string('primary_license_number', 190)->nullable()->index();
            $table->string('primary_license_country', 120)->nullable();
            $table->date('primary_license_expiry')->nullable();

            $table->string('highest_nursing_education', 255)->nullable();
            $table->unsignedSmallInteger('graduation_year')->nullable();

            $table->json('field_confidence')->nullable();
            $table->json('source_file_ids')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->foreign('batch_id')
                ->references('id')
                ->on('nurselink_bulk_intake_batches')
                ->cascadeOnDelete();
        });

        Schema::create('nurselink_bulk_intake_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('candidate_id')->index();
            $table->unsignedBigInteger('source_file_id')->nullable()->index();

            $table->string('category', 100)->nullable();
            $table->string('credential_type', 120)->nullable();
            $table->string('title', 255)->nullable();

            $table->text('credential_number')->nullable();
            $table->string('issuing_authority', 255)->nullable();
            $table->string('country', 120)->nullable();

            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();

            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('verification_status', 40)->default('unverified');
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->foreign('candidate_id')
                ->references('id')
                ->on('nurselink_bulk_intake_candidates')
                ->cascadeOnDelete();

            $table->foreign('source_file_id')
                ->references('id')
                ->on('nurselink_bulk_intake_files')
                ->nullOnDelete();
        });

        Schema::create('nurselink_bulk_intake_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->unsignedBigInteger('candidate_id')->nullable()->index();
            $table->unsignedBigInteger('file_id')->nullable()->index();

            $table->unsignedBigInteger('encoder_account_id')->nullable()->index();
            $table->uuid('admin_user_id')->nullable()->index();

            $table->string('action', 120)->index();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();

            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_bulk_intake_audit');
        Schema::dropIfExists('nurselink_bulk_intake_credentials');
        Schema::dropIfExists('nurselink_bulk_intake_candidates');
        Schema::dropIfExists('nurselink_bulk_intake_files');
        Schema::dropIfExists('nurselink_bulk_intake_batches');
    }
};
