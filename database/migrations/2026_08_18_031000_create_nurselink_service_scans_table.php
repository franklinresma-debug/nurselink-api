<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nurselink_service_scans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('membership_id');
            $table->uuid('user_id');
            $table->uuid('recorded_by');
            $table->string('purpose', 40)->index();
            $table->string('reference_type', 40)->default('general');
            $table->string('reference_id', 120)->nullable();
            $table->string('reference_label', 190)->nullable();
            $table->string('dedupe_key', 191)->unique();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('scanned_at')->index();
            $table->timestamps();

            $table->foreign('membership_id')->references('id')->on('nurselink_memberships')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['membership_id', 'purpose', 'scanned_at'], 'service_scans_member_purpose_time');
            $table->index(['reference_type', 'reference_id'], 'service_scans_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_service_scans');
    }
};
