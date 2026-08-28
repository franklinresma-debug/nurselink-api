<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nurselink_smart_registration_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('nurselink_smart_registration_profiles', 'employment_countries')) {
                $table->text('employment_countries')->nullable()->after('current_employer');
            }
            if (! Schema::hasColumn('nurselink_smart_registration_profiles', 'healthcare_facility_names')) {
                $table->text('healthcare_facility_names')->nullable()->after('employment_countries');
            }
            if (! Schema::hasColumn('nurselink_smart_registration_profiles', 'employment_positions')) {
                $table->text('employment_positions')->nullable()->after('healthcare_facility_names');
            }
            if (! Schema::hasColumn('nurselink_smart_registration_profiles', 'emergency_contact_name_relationship')) {
                $table->string('emergency_contact_name_relationship', 255)->nullable()->after('employment_positions');
            }
            if (! Schema::hasColumn('nurselink_smart_registration_profiles', 'emergency_contact_phone')) {
                $table->string('emergency_contact_phone', 80)->nullable()->after('emergency_contact_name_relationship');
            }
        });
    }

    public function down(): void
    {
        Schema::table('nurselink_smart_registration_profiles', function (Blueprint $table): void {
            $columns = [
                'employment_countries',
                'healthcare_facility_names',
                'employment_positions',
                'emergency_contact_name_relationship',
                'emergency_contact_phone',
            ];
            $existing = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn('nurselink_smart_registration_profiles', $column)));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
