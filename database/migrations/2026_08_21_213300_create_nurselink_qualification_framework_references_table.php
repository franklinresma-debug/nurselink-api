<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('nurselink_qualification_framework_references')) {
            return;
        }

        Schema::create('nurselink_qualification_framework_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('member_id')->nullable()->index();
            $table->uuid('membership_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('member_number', 64)->index();

            $table->string('qualification_title', 255);
            $table->string('field_of_study', 255)->nullable();
            $table->string('country_code', 8)->nullable();
            $table->string('country_name', 128)->nullable();

            $table->string('framework_code', 32)->nullable();
            $table->string('framework_name', 255)->nullable();
            $table->string('framework_level', 64)->nullable();
            $table->string('qualification_type', 128)->nullable();

            $table->string('nurselink_reference_band', 128)->nullable();
            $table->string('mapping_method', 64)->default('framework_reference');
            $table->string('mapping_confidence', 32)->default('needs_review');
            $table->string('verification_status', 32)->default('self_declared');

            $table->string('professional_role', 255)->nullable();
            $table->string('career_level', 128)->nullable();

            $table->decimal('qualification_score', 5, 2)->nullable();
            $table->decimal('license_score', 5, 2)->nullable();
            $table->decimal('experience_score', 5, 2)->nullable();
            $table->decimal('certification_score', 5, 2)->nullable();
            $table->decimal('cpd_score', 5, 2)->nullable();
            $table->decimal('verification_score', 5, 2)->nullable();
            $table->decimal('professional_readiness_score', 5, 2)->nullable();

            $table->boolean('is_primary')->default(false);
            $table->boolean('is_demo')->default(false);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['member_number', 'is_primary'], 'nl_qf_refs_member_primary_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_qualification_framework_references');
    }
};
