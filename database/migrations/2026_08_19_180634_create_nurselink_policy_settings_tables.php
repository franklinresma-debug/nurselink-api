<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nurselink_policy_settings', function (Blueprint $table) {
            $table->id();

            $table->string('organization_name', 190)
                ->default('NurseLink');

            $table->string('contact_email', 190)
                ->nullable();

            $table->string('privacy_email', 190)
                ->nullable();

            $table->string('terms_version', 40);
            $table->date('terms_effective_date')->nullable();
            $table->longText('terms_content')->nullable();

            $table->string('privacy_version', 40);
            $table->date('privacy_effective_date')->nullable();
            $table->longText('privacy_content')->nullable();

            $table->string('status', 30)
                ->default('draft');

            $table->foreignUuid('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignUuid('published_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'published_at']);
        });

        Schema::create('nurselink_policy_versions', function (Blueprint $table) {
            $table->id();

            $table->string('policy_type', 30);
            $table->string('version', 40);
            $table->date('effective_date')->nullable();

            $table->longText('content');

            $table->string('organization_name', 190);
            $table->string('contact_email', 190)->nullable();
            $table->string('privacy_email', 190)->nullable();

            $table->foreignUuid('published_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('published_at');

            $table->timestamps();

            $table->unique(
                ['policy_type', 'version'],
                'nl_policy_versions_type_version_unique'
            );

            $table->index(
                ['policy_type', 'published_at'],
                'nl_policy_versions_type_published_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_policy_versions');
        Schema::dropIfExists('nurselink_policy_settings');
    }
};
