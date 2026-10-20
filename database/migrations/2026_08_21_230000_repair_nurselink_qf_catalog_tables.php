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
                $table->string('verification_status', 32)->default('needs_review');
                $table->string('mapping_confidence', 32)->default('needs_review');
                $table->date('effective_date')->nullable();
                $table->string('version_label', 64)->default('current');
                $table->boolean('is_current')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['country_code','framework_code','version_label'], 'nl_qf_framework_version_unique');
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
    }

    public function down(): void
    {
        // Intentionally non-destructive for dependency repair.
    }
};
