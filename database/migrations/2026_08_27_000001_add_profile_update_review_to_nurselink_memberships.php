<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nurselink_memberships', function (Blueprint $table): void {
            $table->string('profile_update_status', 40)->nullable()->after('resubmitted_at');
            $table->timestamp('profile_update_submitted_at')->nullable()->after('profile_update_status');
            $table->timestamp('profile_update_reviewed_at')->nullable()->after('profile_update_submitted_at');
            $table->text('profile_update_reviewer_notes')->nullable()->after('profile_update_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('nurselink_memberships', function (Blueprint $table): void {
            $table->dropColumn([
                'profile_update_status',
                'profile_update_submitted_at',
                'profile_update_reviewed_at',
                'profile_update_reviewer_notes',
            ]);
        });
    }
};
