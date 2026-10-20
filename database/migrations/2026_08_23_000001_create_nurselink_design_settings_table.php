<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nurselink_design_settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 64)->default('global');
            $table->unsignedBigInteger('version');
            $table->string('status', 16)->default('draft');
            $table->json('payload');
            $table->uuid('created_by')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('restored_from_version')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'version']);
            $table->index(['scope', 'status', 'version']);
            $table->index(['scope', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_design_settings');
    }
};
