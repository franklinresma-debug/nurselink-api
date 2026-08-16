<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('identifier_counters', function (Blueprint $table) {
            $table->string('type', 40);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->primary(['type', 'year']);
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('application_no', 32)->unique();
            $table->uuid('user_id')->unique();
            $table->string('status', 40)->default('draft')->index();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->json('profile_data')->nullable();
            $table->uuid('current_reviewer_user_id')->nullable()->index();
            $table->text('return_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('review_started_at')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->timestampTz('resubmitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('current_reviewer_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('application_status_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id')->index();
            $table->uuid('actor_user_id')->nullable()->index();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent()->index();
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('member_no', 32)->unique();
            $table->uuid('user_id')->unique();
            $table->uuid('approved_from_application_id')->nullable()->unique();
            $table->string('status', 30)->default('active')->index();
            $table->timestampTz('joined_at')->useCurrent();
            $table->timestampsTz();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('approved_from_application_id')->references('id')->on('applications')->nullOnDelete();
        });

        Schema::create('member_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('member_id')->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('suffix', 30)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 80)->nullable();
            $table->string('mobile_phone', 40)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('country', 120)->nullable();
            $table->string('professional_title', 160)->nullable();
            $table->string('current_position', 180)->nullable();
            $table->string('current_employer', 220)->nullable();
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->json('profile_meta')->nullable();
            $table->timestampsTz();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_profiles');
        Schema::dropIfExists('members');
        Schema::dropIfExists('application_status_events');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('identifier_counters');
    }
};
