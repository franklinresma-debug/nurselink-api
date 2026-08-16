<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_smart_registration_documents')) {
            return;
        }

        Schema::table('nurselink_smart_registration_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('nurselink_smart_registration_documents', 'security_status')) {
                $table->string('security_status', 40)->default('pending')->index()->after('document_type');
            }
            if (! Schema::hasColumn('nurselink_smart_registration_documents', 'security_message')) {
                $table->string('security_message', 1000)->nullable()->after('security_status');
            }
            if (! Schema::hasColumn('nurselink_smart_registration_documents', 'security_scanned_at')) {
                $table->timestamp('security_scanned_at')->nullable()->after('security_message');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nurselink_smart_registration_documents')) {
            return;
        }

        $columns = array_values(array_filter(
            ['security_status', 'security_message', 'security_scanned_at'],
            fn (string $column): bool => Schema::hasColumn('nurselink_smart_registration_documents', $column)
        ));

        if ($columns !== []) {
            Schema::table(
                'nurselink_smart_registration_documents',
                fn (Blueprint $table): mixed => $table->dropColumn($columns)
            );
        }
    }
};
