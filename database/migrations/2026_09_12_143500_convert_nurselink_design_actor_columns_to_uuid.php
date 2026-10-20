<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('nurselink_design_settings')) {
            return;
        }

        Schema::table('nurselink_design_settings', function (Blueprint $table) {
            $table->uuid('created_by')->nullable()->change();
            $table->uuid('published_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // NurseLink user identifiers are UUIDs. Reverting these columns to
        // integers would corrupt valid design-setting audit attribution.
    }
};
