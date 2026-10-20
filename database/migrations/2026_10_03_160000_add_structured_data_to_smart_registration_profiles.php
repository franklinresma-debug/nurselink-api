<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('nurselink_smart_registration_profiles','structured_data')) {
            Schema::table('nurselink_smart_registration_profiles',function(Blueprint $table) {
                $table->json('structured_data')->nullable();
            });
        }
    }
    public function down(): void
    {
        if (Schema::hasColumn('nurselink_smart_registration_profiles','structured_data')) {
            Schema::table('nurselink_smart_registration_profiles',function(Blueprint $table) {
                $table->dropColumn('structured_data');
            });
        }
    }
};
