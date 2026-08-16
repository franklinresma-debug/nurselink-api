<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_job_opportunities')) {
            Schema::create('nurselink_job_opportunities', function (Blueprint $table): void {
                $table->id();
                $table->string('reference_code', 120)->unique();
                $table->string('title', 190);
                $table->string('employer_name', 190);
                $table->string('country', 120);
                $table->string('city', 120)->nullable();
                $table->string('work_setting', 80)->nullable();
                $table->string('employment_type', 80)->nullable();
                $table->string('specialty', 150)->nullable();
                $table->string('required_license_type', 80)->nullable();
                $table->decimal('minimum_experience_years', 5, 1)->default(0);
                $table->boolean('overseas_opportunity')->default(false);

                $table->decimal('salary_min', 14, 2)->nullable();
                $table->decimal('salary_max', 14, 2)->nullable();
                $table->string('salary_currency', 8)->nullable();

                $table->text('description')->nullable();
                $table->text('requirements')->nullable();
                $table->string('apply_url', 512)->nullable();
                $table->string('source_label', 190)->nullable();

                $table->string('status', 30)->default('active');
                $table->timestamp('published_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'expires_at']);
                $table->index(['country', 'specialty']);
                $table->index(['work_setting', 'employment_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_job_opportunities');
    }
};
