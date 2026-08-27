<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('nurselink_qf_catalog_frameworks')) {
            Schema::create('nurselink_qf_catalog_frameworks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('country_code', 32)->index();
                $table->string('country_name', 128);
                $table->string('framework_code', 32)->index();
                $table->string('framework_name', 255);
                $table->unsignedInteger('level_count')->nullable();
                $table->string('source_authority', 255)->nullable();
                $table->text('source_url')->nullable();
                $table->string('source_document_title', 255)->nullable();
                $table->date('source_publication_date')->nullable();
                $table->date('effective_date')->nullable();
                $table->date('as_of_date')->nullable();
                $table->date('last_verified_date')->nullable();
                $table->string('verification_status', 32)->default('needs_review');
                $table->string('mapping_confidence', 32)->default('needs_review');
                $table->string('policy_status', 32)->default('current');
                $table->string('version_label', 64)->default('current');
                $table->boolean('is_current')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['country_code','framework_code','version_label'], 'nl_qf_framework_version_unique');
            });
        } else {
            Schema::table('nurselink_qf_catalog_frameworks', function (Blueprint $table) {
                if (!Schema::hasColumn('nurselink_qf_catalog_frameworks','source_document_title'))
                    $table->string('source_document_title',255)->nullable()->after('source_url');
                if (!Schema::hasColumn('nurselink_qf_catalog_frameworks','source_publication_date'))
                    $table->date('source_publication_date')->nullable()->after('source_document_title');
                if (!Schema::hasColumn('nurselink_qf_catalog_frameworks','as_of_date'))
                    $table->date('as_of_date')->nullable()->after('effective_date');
                if (!Schema::hasColumn('nurselink_qf_catalog_frameworks','last_verified_date'))
                    $table->date('last_verified_date')->nullable()->after('as_of_date');
                if (!Schema::hasColumn('nurselink_qf_catalog_frameworks','policy_status'))
                    $table->string('policy_status',32)->default('current')->after('mapping_confidence');
            });
        }

        if (!Schema::hasTable('nurselink_qf_catalog_levels')) {
            Schema::create('nurselink_qf_catalog_levels', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('country_code', 32)->index();
                $table->string('framework_code', 32)->index();
                $table->string('framework_level', 64);
                $table->string('qualification_type', 255)->nullable();
                $table->string('nurselink_reference_band', 160)->nullable();
                $table->string('regional_reference', 160)->nullable();
                $table->string('verification_status', 32)->default('needs_review');
                $table->string('version_label', 64)->default('current');
                $table->timestamps();
                $table->unique(
                    ['country_code','framework_code','framework_level','version_label'],
                    'nl_qf_level_version_unique'
                );
            });
        }

        if (!Schema::hasTable('nurselink_qf_catalog_imports')) {
            Schema::create('nurselink_qf_catalog_imports', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('file_name',255)->nullable();
                $table->string('strategy',32)->nullable();
                $table->unsignedInteger('framework_count')->default(0);
                $table->unsignedInteger('level_count')->default(0);
                $table->longText('before_snapshot')->nullable();
                $table->longText('after_summary')->nullable();
                $table->string('status',32)->default('completed');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('nurselink_qualification_framework_references')) {
            Schema::create('nurselink_qualification_framework_references', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('member_id')->nullable()->index();
                $table->uuid('membership_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('member_number',64)->index();
                $table->string('qualification_title',255);
                $table->string('field_of_study',255)->nullable();
                $table->string('country_code',32)->nullable();
                $table->string('country_name',128)->nullable();
                $table->string('framework_code',32)->nullable();
                $table->string('framework_name',255)->nullable();
                $table->string('framework_level',64)->nullable();
                $table->string('qualification_type',128)->nullable();
                $table->string('nurselink_reference_band',128)->nullable();
                $table->string('mapping_method',64)->default('framework_reference');
                $table->string('mapping_confidence',32)->default('needs_review');
                $table->string('verification_status',32)->default('self_declared');
                $table->string('professional_role',255)->nullable();
                $table->string('career_level',128)->nullable();
                $table->decimal('professional_readiness_score',5,2)->nullable();
                $table->boolean('is_primary')->default(false);
                $table->boolean('is_demo')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['member_number','is_primary']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: this migration is a compatibility/self-healing layer.
    }
};
