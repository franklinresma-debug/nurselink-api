<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'nurselink_bulk_intake_candidates',
            function (Blueprint $table) {
                if (! Schema::hasColumn(
                    'nurselink_bulk_intake_candidates',
                    'import_status'
                )) {
                    $table->string(
                        'import_status',
                        40
                    )
                    ->nullable()
                    ->after('status');
                }

                if (! Schema::hasColumn(
                    'nurselink_bulk_intake_candidates',
                    'ready_for_import_at'
                )) {
                    $table->timestamp(
                        'ready_for_import_at'
                    )
                    ->nullable();
                }

                if (! Schema::hasColumn(
                    'nurselink_bulk_intake_candidates',
                    'imported_at'
                )) {
                    $table->timestamp(
                        'imported_at'
                    )
                    ->nullable();
                }

                if (! Schema::hasColumn(
                    'nurselink_bulk_intake_candidates',
                    'imported_user_id'
                )) {
                    $table->uuid(
                        'imported_user_id'
                    )
                    ->nullable();
                }

                if (! Schema::hasColumn(
                    'nurselink_bulk_intake_candidates',
                    'imported_application_id'
                )) {
                    $table->uuid(
                        'imported_application_id'
                    )
                    ->nullable();
                }

                if (! Schema::hasColumn(
                    'nurselink_bulk_intake_candidates',
                    'imported_by_user_id'
                )) {
                    $table->uuid(
                        'imported_by_user_id'
                    )
                    ->nullable();
                }

                if (! Schema::hasColumn(
                    'nurselink_bulk_intake_candidates',
                    'import_failure'
                )) {
                    $table->text(
                        'import_failure'
                    )
                    ->nullable();
                }
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'nurselink_bulk_intake_candidates',
            function (Blueprint $table) {
                foreach (
                    [
                        'import_status',
                        'ready_for_import_at',
                        'imported_at',
                        'imported_user_id',
                        'imported_application_id',
                        'imported_by_user_id',
                        'import_failure',
                    ]
                    as $column
                ) {
                    if (
                        Schema::hasColumn(
                            'nurselink_bulk_intake_candidates',
                            $column
                        )
                    ) {
                        $table->dropColumn(
                            $column
                        );
                    }
                }
            }
        );
    }
};
