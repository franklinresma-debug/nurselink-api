<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_review_audit')) {
            Schema::create('nurselink_review_audit', function (Blueprint $table): void {
                $table->id();
                $table->string('reviewer_user_id', 191);
                $table->string('action', 100);
                $table->string('target_type', 80);
                $table->string('target_id', 191);
                $table->json('before_state')->nullable();
                $table->json('after_state')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['target_type', 'target_id']);
                $table->index(['reviewer_user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_review_audit');
    }
};
