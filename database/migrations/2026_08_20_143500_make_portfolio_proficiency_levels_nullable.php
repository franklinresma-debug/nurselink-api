<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * "Not stated" must remain unknown.
         *
         * Bulk Intake intentionally does not infer competency or language
         * proficiency from the mere appearance of a skill/language in a CV.
         */
        DB::statement(
            'ALTER TABLE portfolio_competencies
             MODIFY proficiency VARCHAR(255) NULL DEFAULT NULL'
        );

        DB::statement(
            'ALTER TABLE portfolio_languages
             MODIFY speaking VARCHAR(255) NULL DEFAULT NULL,
             MODIFY reading VARCHAR(255) NULL DEFAULT NULL,
             MODIFY writing VARCHAR(255) NULL DEFAULT NULL'
        );
    }

    public function down(): void
    {
        /*
         * Restore the historic schema safely if rolled back.
         */
        DB::table('portfolio_competencies')
            ->whereNull('proficiency')
            ->update([
                'proficiency' => 'competent',
            ]);

        DB::table('portfolio_languages')
            ->whereNull('speaking')
            ->update([
                'speaking' => 'basic',
            ]);

        DB::table('portfolio_languages')
            ->whereNull('reading')
            ->update([
                'reading' => 'basic',
            ]);

        DB::table('portfolio_languages')
            ->whereNull('writing')
            ->update([
                'writing' => 'basic',
            ]);

        DB::statement(
            "ALTER TABLE portfolio_competencies
             MODIFY proficiency VARCHAR(255) NOT NULL DEFAULT 'competent'"
        );

        DB::statement(
            "ALTER TABLE portfolio_languages
             MODIFY speaking VARCHAR(255) NOT NULL DEFAULT 'basic',
             MODIFY reading VARCHAR(255) NOT NULL DEFAULT 'basic',
             MODIFY writing VARCHAR(255) NOT NULL DEFAULT 'basic'"
        );
    }
};
