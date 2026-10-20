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
                    'photo_path'
                )) {
                    $table->string(
                        'photo_path',
                        512
                    )->nullable();
                }

                if (! Schema::hasColumn(
                    'nurselink_bulk_intake_candidates',
                    'photo_source_file_id'
                )) {
                    $table->unsignedBigInteger(
                        'photo_source_file_id'
                    )->nullable();
                }

                if (! Schema::hasColumn(
                    'nurselink_bulk_intake_candidates',
                    'photo_status'
                )) {
                    $table->string(
                        'photo_status',
                        40
                    )->nullable();
                }

                if (! Schema::hasColumn(
                    'nurselink_bulk_intake_candidates',
                    'photo_confidence'
                )) {
                    $table->decimal(
                        'photo_confidence',
                        5,
                        4
                    )->nullable();
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
                        'photo_path',
                        'photo_source_file_id',
                        'photo_status',
                        'photo_confidence',
                    ]
                    as $column
                ) {
                    if (
                        Schema::hasColumn(
                            'nurselink_bulk_intake_candidates',
                            $column
                        )
                    ) {
                        $table->dropColumn($column);
                    }
                }
            }
        );
    }
};
