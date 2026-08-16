<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_user_id')->nullable()->index();
            $table->string('action', 160)->index();
            $table->string('object_type', 120)->nullable();
            $table->uuid('object_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->string('ip_hash', 128)->nullable();
            $table->timestampTz('created_at')->useCurrent()->index();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
