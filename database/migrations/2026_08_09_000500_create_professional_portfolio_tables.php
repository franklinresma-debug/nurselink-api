<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('portfolio_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('professional_headline')->nullable();
            $table->text('professional_summary')->nullable();
            $table->string('primary_specialty')->nullable();
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->string('current_country', 2)->nullable();
            $table->boolean('available_for_opportunities')->default(false);
            $table->unsignedTinyInteger('completion_percent')->default(0);
            $table->timestamps();
        });

        Schema::create('portfolio_education', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('qualification');
            $table->string('field_of_study')->nullable();
            $table->string('institution');
            $table->string('country', 2)->nullable();
            $table->date('started_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->string('status')->default('self_declared');
            $table->uuid('source_document_id')->nullable();
            $table->uuid('source_extracted_fact_id')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamps();
            $table->index(['member_id','completed_on']);
        });

        Schema::create('portfolio_employment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('position_title');
            $table->string('employer');
            $table->string('facility_type')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('city')->nullable();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->boolean('is_current')->default(false);
            $table->text('responsibilities')->nullable();
            $table->string('status')->default('self_declared');
            $table->uuid('source_document_id')->nullable();
            $table->uuid('source_extracted_fact_id')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamps();
            $table->index(['member_id','is_current']);
        });

        Schema::create('portfolio_specialties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('level')->default('experienced');
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->string('status')->default('self_declared');
            $table->uuid('source_document_id')->nullable();
            $table->uuid('source_extracted_fact_id')->nullable();
            $table->timestamps();
            $table->unique(['member_id','name']);
        });

        Schema::create('portfolio_competencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('name');
            $table->string('proficiency')->default('competent');
            $table->string('evidence_state')->default('self_declared');
            $table->uuid('source_document_id')->nullable();
            $table->uuid('source_extracted_fact_id')->nullable();
            $table->foreignUuid('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['member_id','domain','name']);
        });

        Schema::create('portfolio_technology_skills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('name');
            $table->string('proficiency')->default('working');
            $table->string('status')->default('self_declared');
            $table->timestamps();
            $table->unique(['member_id','category','name']);
        });

        Schema::create('portfolio_languages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('language');
            $table->string('speaking')->default('basic');
            $table->string('reading')->default('basic');
            $table->string('writing')->default('basic');
            $table->string('status')->default('self_declared');
            $table->timestamps();
            $table->unique(['member_id','language']);
        });

        Schema::create('portfolio_timeline_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('occurred_on')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->timestamps();
            $table->index(['member_id','occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_timeline_events');
        Schema::dropIfExists('portfolio_languages');
        Schema::dropIfExists('portfolio_technology_skills');
        Schema::dropIfExists('portfolio_competencies');
        Schema::dropIfExists('portfolio_specialties');
        Schema::dropIfExists('portfolio_employment');
        Schema::dropIfExists('portfolio_education');
        Schema::dropIfExists('portfolio_summaries');
    }
};
