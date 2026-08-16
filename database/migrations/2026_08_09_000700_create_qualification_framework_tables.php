<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('qualification_frameworks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('scope')->default('national'); // national, regional, destination, internal
            $table->string('jurisdiction', 30)->nullable();
            $table->unsignedTinyInteger('level_count')->nullable();
            $table->string('version_label')->nullable();
            $table->string('governance_status')->default('reference_only'); // draft, reference_only, published, retired
            $table->boolean('assessment_enabled')->default(false);
            $table->text('description')->nullable();
            $table->text('governance_note')->nullable();
            $table->text('source_url')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignUuid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->index(['scope', 'jurisdiction']);
            $table->index(
                ['governance_status', 'assessment_enabled'],
                'qf_governance_assessment_idx'
            );
        });

        Schema::create('qualification_framework_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('framework_id')
                ->constrained('qualification_frameworks')
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('ordinal');
            $table->string('code', 40);
            $table->string('title');
            $table->json('descriptors')->nullable();
            $table->timestamps();

            $table->unique(
                ['framework_id', 'ordinal'],
                'qfl_framework_ordinal_uq'
            );

            $table->unique(
                ['framework_id', 'code'],
                'qfl_framework_code_uq'
            );
        });

        Schema::create('qualification_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('framework_id')
                ->constrained('qualification_frameworks')
                ->cascadeOnDelete();

            $table->foreignUuid('framework_level_id')
                ->nullable()
                ->constrained('qualification_framework_levels')
                ->cascadeOnDelete();

            $table->string('code', 80);
            $table->string('category'); // education, credential, competency, experience, cpd, language, other
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('mandatory')->default(true);
            $table->decimal('weight', 6, 2)->default(1);
            $table->unsignedTinyInteger('minimum_trust_level')->default(1);
            $table->json('evidence_rule');
            $table->string('governance_status')->default('draft'); // draft, published, retired
            $table->text('governance_note')->nullable();
            $table->timestamps();

            $table->unique(
                ['framework_id', 'code'],
                'qreq_framework_code_uq'
            );

            $table->index(
                ['framework_id', 'framework_level_id', 'governance_status'],
                'qreq_framework_level_gov_idx'
            );
        });

        Schema::create('qualification_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('member_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignUuid('framework_id')
                ->constrained('qualification_frameworks')
                ->cascadeOnDelete();

            $table->foreignUuid('target_level_id')
                ->nullable()
                ->constrained('qualification_framework_levels')
                ->nullOnDelete();

            $table->string('status')->default('draft'); // draft, system_ready, submitted, under_review, assessed, returned, superseded
            $table->decimal('readiness_score', 5, 2)->nullable();
            $table->string('readiness_label')->nullable();
            $table->unsignedSmallInteger('requirements_total')->default(0);
            $table->unsignedSmallInteger('requirements_met')->default(0);
            $table->unsignedSmallInteger('requirements_partial')->default(0);
            $table->unsignedSmallInteger('requirements_gap')->default(0);
            $table->string('evidence_snapshot_hash', 64)->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->foreignUuid('assessed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('assessed_at')->nullable();
            $table->text('assessor_note')->nullable();
            $table->text('member_note')->nullable();
            $table->timestamps();

            $table->index(
                ['member_id', 'status'],
                'qassess_member_status_idx'
            );

            $table->index(
                ['framework_id', 'status'],
                'qassess_framework_status_idx'
            );
        });

        Schema::create('qualification_assessment_results', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('assessment_id')
                ->constrained('qualification_assessments')
                ->cascadeOnDelete();

            $table->foreignUuid('requirement_id')
                ->constrained('qualification_requirements')
                ->cascadeOnDelete();

            $table->string('result')->default('unassessed'); // met, partial, gap, unassessed
            $table->decimal('score', 5, 2)->default(0);
            $table->unsignedSmallInteger('evidence_count')->default(0);
            $table->unsignedTinyInteger('highest_trust_level')->default(0);
            $table->decimal('experience_years', 7, 2)->nullable();
            $table->json('evidence_snapshot')->nullable();
            $table->text('rationale')->nullable();
            $table->timestamps();

            $table->unique(
                ['assessment_id', 'requirement_id'],
                'qresult_assessment_requirement_uq'
            );

            $table->index(
                ['assessment_id', 'result'],
                'qresult_assessment_result_idx'
            );
        });

        Schema::create('qualification_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('assessment_id')
                ->constrained('qualification_assessments')
                ->cascadeOnDelete();

            $table->foreignUuid('requirement_id')
                ->nullable()
                ->constrained('qualification_requirements')
                ->nullOnDelete();

            $table->unsignedTinyInteger('priority')->default(3);
            $table->string('category')->default('evidence');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('action_type')->default('review');
            $table->string('status')->default('open');
            $table->timestamps();

            $table->index(
                ['assessment_id', 'priority', 'status'],
                'qrec_assessment_priority_status_idx'
            );
        });

        Schema::create('framework_level_crosswalks', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('source_level_id')
                ->constrained('qualification_framework_levels')
                ->cascadeOnDelete();

            $table->foreignUuid('target_level_id')
                ->constrained('qualification_framework_levels')
                ->cascadeOnDelete();

            $table->string('mapping_type')->default('reference'); // reference, approximate, officially_recognized
            $table->string('governance_status')->default('draft'); // draft, published, retired
            $table->text('basis_note')->nullable();
            $table->text('source_url')->nullable();

            $table->foreignUuid('validated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_level_id', 'target_level_id'],
                'qcross_source_target_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_level_crosswalks');
        Schema::dropIfExists('qualification_recommendations');
        Schema::dropIfExists('qualification_assessment_results');
        Schema::dropIfExists('qualification_assessments');
        Schema::dropIfExists('qualification_requirements');
        Schema::dropIfExists('qualification_framework_levels');
        Schema::dropIfExists('qualification_frameworks');
    }
};