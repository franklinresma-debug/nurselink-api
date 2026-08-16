<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->uuid('source_application_document_id')->nullable();
            $table->string('document_type');
            $table->string('title');
            $table->string('original_name');
            $table->string('storage_disk')->default('private');
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('security_status')->default('pending');
            $table->string('visibility')->default('private');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamps();
            $table->index(['member_id','document_type']);
            $table->index(['member_id','expires_on']);
        });

        Schema::create('professional_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('category'); // license, certification, registration, training, cpd
            $table->string('credential_type');
            $table->string('title');
            $table->text('credential_number')->nullable(); // encrypted cast in model
            $table->string('credential_number_hash', 64)->nullable();
            $table->string('credential_number_last4', 4)->nullable();
            $table->string('issuing_authority')->nullable();
            $table->string('country', 2)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->boolean('does_not_expire')->default(false);
            $table->string('credential_status')->default('active');
            $table->string('verification_status')->default('unverified');
            $table->foreignUuid('primary_document_id')->nullable()->constrained('member_documents')->nullOnDelete();
            $table->uuid('source_extracted_fact_id')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->date('renewal_due_on')->nullable();
            $table->timestamp('last_status_evaluated_at')->nullable();
            $table->timestamps();
            $table->index(['member_id','credential_status']);
            $table->index(['member_id','expires_on']);
            $table->index(['credential_type','country']);
            $table->index('credential_number_hash');
        });

        Schema::create('credential_documents', function (Blueprint $table) {
            $table->uuid('credential_id');
            $table->uuid('document_id');
            $table->string('purpose')->default('evidence');
            $table->timestamps();
            $table->primary(['credential_id','document_id']);
            $table->foreign('credential_id')->references('id')->on('professional_credentials')->cascadeOnDelete();
            $table->foreign('document_id')->references('id')->on('member_documents')->cascadeOnDelete();
        });

        Schema::create('professional_development_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('record_type')->default('training'); // training, seminar, cpd, workshop, conference
            $table->string('title');
            $table->string('provider')->nullable();
            $table->string('country', 2)->nullable();
            $table->date('completed_on')->nullable();
            $table->decimal('cpd_units', 7, 2)->nullable();
            $table->decimal('hours', 7, 2)->nullable();
            $table->string('status')->default('self_declared');
            $table->foreignUuid('evidence_document_id')->nullable()->constrained('member_documents')->nullOnDelete();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamps();
            $table->index(['member_id','completed_on']);
        });

        Schema::create('credential_reminder_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('credential_id')->constrained('professional_credentials')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('reminder_type'); // 90_day, 60_day, 30_day, expired, custom
            $table->date('trigger_on');
            $table->string('state')->default('pending'); // pending, queued, delivered, suppressed, failed
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
  
            $table->unique(['credential_id', 'reminder_type', 'trigger_on'],
    'cred_reminder_type_trigger_uq'
);


            $table->index(['state','trigger_on']);
        });

        Schema::create('credential_status_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('credential_id')->constrained('professional_credentials')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['credential_id','occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_status_events');
        Schema::dropIfExists('credential_reminder_events');
        Schema::dropIfExists('professional_development_records');
        Schema::dropIfExists('credential_documents');
        Schema::dropIfExists('professional_credentials');
        Schema::dropIfExists('member_documents');
    }
};
