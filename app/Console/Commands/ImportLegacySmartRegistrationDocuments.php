<?php

namespace App\Console\Commands;

use App\Services\SmartRegistration\LegacyDocumentImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportLegacySmartRegistrationDocuments extends Command
{
    protected $signature = 'nurselink:import-legacy-smart-documents {--user= : Import one exact NurseLink user ID}';

    protected $description = 'Copy clean legacy application evidence into the current Smart Registration document workflow.';

    public function handle(LegacyDocumentImportService $importer): int
    {
        $userIds = $this->option('user')
            ? collect([(string) $this->option('user')])
            : DB::table('applications')->distinct()->pluck('user_id');

        $totals = ['imported' => 0, 'duplicates' => 0, 'skipped' => 0];
        foreach ($userIds as $userId) {
            $result = $importer->importForUser((string) $userId);
            foreach ($totals as $key => $value) $totals[$key] += $result[$key];
        }

        $this->info("Imported {$totals['imported']}; duplicates {$totals['duplicates']}; skipped {$totals['skipped']}.");
        return self::SUCCESS;
    }
}
