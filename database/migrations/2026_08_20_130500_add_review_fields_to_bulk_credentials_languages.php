<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'nurselink_bulk_intake_credentials',
            function (Blueprint $table): void {
                $table->string(
                    'review_status',
                    40
                )
                    ->default('review_required')
                    ->after('verification_status');
            }
        );

        Schema::table(
            'nurselink_bulk_intake_languages',
            function (Blueprint $table): void {
                $table->text(
                    'review_notes'
                )
                    ->nullable()
                    ->after('review_status');
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'nurselink_bulk_intake_credentials',
            function (Blueprint $table): void {
                $table->dropColumn(
                    'review_status'
                );
            }
        );

        Schema::table(
            'nurselink_bulk_intake_languages',
            function (Blueprint $table): void {
                $table->dropColumn(
                    'review_notes'
                );
            }
        );
    }
};
