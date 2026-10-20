<?php

namespace App\Jobs;

use App\Services\Security\ClamAvScanner;
use App\Services\BulkIntake\BulkNurseIntakeIntelligenceService;
use App\Services\SmartRegistration\LocalOcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessBulkNurseIntakeFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(
        public readonly int $fileId
    ) {
        $this->onQueue(
            (string) config(
                'smart_registration.queue.name',
                'document-extraction'
            )
        );
    }

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(
        ClamAvScanner $scanner,
        LocalOcrService $ocr,
        BulkNurseIntakeIntelligenceService $intelligence
    ): void {
        $file = DB::table('nurselink_bulk_intake_files')
            ->where('id', $this->fileId)
            ->first();

        if (! $file) {
            return;
        }

        DB::table('nurselink_bulk_intake_files')
            ->where('id', $file->id)
            ->update([
                'security_status' => 'scanning',
                'extraction_status' => 'processing',
                'updated_at' => now(),
            ]);

        $scan = $scanner->scan(
            'local',
            $file->storage_path
        );

        if (($scan['status'] ?? '') !== 'clean') {
            DB::table('nurselink_bulk_intake_files')
                ->where('id', $file->id)
                ->update([
                    'security_status' => 'quarantined',
                    'security_message' =>
                        $scan['response'] ?? 'Document blocked.',
                    'security_scanned_at' => now(),
                    'extraction_status' => 'blocked',
                    'extraction_message' =>
                        'Extraction was blocked by security scanning.',
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('nurselink_bulk_intake_files')
            ->where('id', $file->id)
            ->update([
                'security_status' => 'clean',
                'security_message' => 'Document security scan passed.',
                'security_scanned_at' => now(),
                'updated_at' => now(),
            ]);

        $absolute = Storage::disk('local')->path(
            $file->storage_path
        );

        $result = $ocr->extract(
            $absolute,
            $file->original_name,
            true
        );

        $priorDocument = (json_decode((string)$file->extracted_fields, true) ?: [])['_document'] ?? [];
        if (($priorDocument['source_sha256'] ?? null) === $file->sha256) {
            $result['extraction_details'] = array_merge($priorDocument, $result['extraction_details'] ?? []);
        }

        DB::table('nurselink_bulk_intake_files')
            ->where('id', $file->id)
            ->update([
                'document_type' =>
                    $result['document_type'] ?? 'other',

                'extraction_status' =>
                    $result['status'] ?? 'needs_input',

                'extracted_text' =>
                    ! empty($result['text'])
                        ? (string) $result['text']
                        : null,

                'extracted_fields' =>
                    (!empty($result['fields']) || !empty($result['structured_records']) || !empty($result['extraction_details']))
                        ? json_encode(
                            [...$result['fields'], '_structured'=>$result['structured_records'] ?? [], '_document'=>$result['extraction_details'] ?? []],
                            JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                            | JSON_INVALID_UTF8_SUBSTITUTE
                            | JSON_THROW_ON_ERROR
                        )
                        : null,

                'extraction_message' =>
                    $result['message'] ?? null,

                'extracted_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);

        if ($result['pending'] ?? false) {
            self::dispatch($this->fileId)->onConnection('database')->delay(now()->addSeconds(2));
            return;
        }

        $this->refreshBatchCounters(
            (int) $file->batch_id
        );

        /*
         * Reprocessing a roster replaces only the generated intake drafts;
         * submitted/imported member records are not touched here.
         */
        if (in_array(strtolower((string) $file->extension), ['xls', 'xlsx'], true)
            && DB::table('nurselink_bulk_intake_candidates')
                ->where('batch_id', $file->batch_id)
                ->exists()) {
            $intelligence->build((int) $file->batch_id);
        }
    }

    public function failed(
        Throwable $error
    ): void {
        $file = DB::table('nurselink_bulk_intake_files')
            ->where('id', $this->fileId)
            ->first();

        if (! $file) {
            return;
        }

        DB::table('nurselink_bulk_intake_files')
            ->where('id', $file->id)
            ->update([
                'extraction_status' => 'failed',
                'extraction_message' => mb_substr(
                    $error->getMessage(),
                    0,
                    1000
                ),
                'updated_at' => now(),
            ]);

        $this->refreshBatchCounters(
            (int) $file->batch_id
        );
    }

    private function refreshBatchCounters(
        int $batchId
    ): void {
        $files = DB::table(
            'nurselink_bulk_intake_files'
        )
            ->where('batch_id', $batchId);

        $total = (clone $files)->count();

        $processed = (clone $files)
            ->whereIn(
                'extraction_status',
                [
                    'extracted',
                    'needs_input',
                    'blocked',
                    'failed',
                ]
            )
            ->count();

        $failed = (clone $files)
            ->whereIn(
                'extraction_status',
                [
                    'blocked',
                    'failed',
                ]
            )
            ->count();

        $status =
            $total > 0 && $processed >= $total
                ? 'ready_for_grouping'
                : 'processing';

        DB::table(
            'nurselink_bulk_intake_batches'
        )
            ->where('id', $batchId)
            ->update([
                'file_count' => $total,
                'processed_count' => $processed,
                'failed_count' => $failed,
                'status' => $status,
                'processing_completed_at' =>
                    $status === 'ready_for_grouping'
                        ? now()
                        : null,
                'updated_at' => now(),
            ]);
    }
}
