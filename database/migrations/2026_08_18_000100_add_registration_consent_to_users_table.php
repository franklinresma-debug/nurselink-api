<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('terms_accepted_at')->nullable()->after('mfa_required');
            $table->string('terms_version', 40)->nullable()->after('terms_accepted_at');
            $table->timestampTz('privacy_accepted_at')->nullable()->after('terms_version');
            $table->string('privacy_version', 40)->nullable()->after('privacy_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_at', 'terms_version', 'privacy_accepted_at', 'privacy_version']);
        });
    }
};
