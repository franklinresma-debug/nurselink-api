<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'temporary_encoder_accounts',
            function (Blueprint $table): void {
                $table
                    ->string('email', 255)
                    ->nullable()
                    ->unique()
                    ->after('username');
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'temporary_encoder_accounts',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'temporary_encoder_accounts_email_unique'
                );

                $table->dropColumn('email');
            }
        );
    }
};
