<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nurselink_bulk_intake_candidates', function (Blueprint $table) {
            if (! Schema::hasColumn('nurselink_bulk_intake_candidates', 'second_name')) {
                $table->string('second_name', 120)->nullable()->after('first_name');
            }

            if (! Schema::hasColumn('nurselink_bulk_intake_candidates', 'middle_initial')) {
                $table->string('middle_initial', 20)->nullable()->after('second_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('nurselink_bulk_intake_candidates', function (Blueprint $table) {
            if (Schema::hasColumn('nurselink_bulk_intake_candidates', 'middle_initial')) {
                $table->dropColumn('middle_initial');
            }

            if (Schema::hasColumn('nurselink_bulk_intake_candidates', 'second_name')) {
                $table->dropColumn('second_name');
            }
        });
    }
};
