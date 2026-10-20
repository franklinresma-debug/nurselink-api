<?php

namespace App\Services\SmartRegistration;

use App\Services\Security\ClamAvScanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SmartRegistrationDocumentProcessor
{
    public function __construct(
        private readonly LocalOcrService $ocr,
        private readonly ClamAvScanner $scanner
    ) {}

    public function process(int $documentId): object
    {
        $document = DB::table('nurselink_smart_registration_documents')
            ->where('id', $documentId)
            ->first();

        if (! $document) {
            throw new RuntimeException('Smart Registration document not found.');
        }

        // A queued job for an old version must not extract superseded evidence.
        if(isset($document->is_current) && !$document->is_current) return $document;

        if (! $this->passesSecurityScan($document)) {
            return DB::table('nurselink_smart_registration_documents')
                ->where('id', $documentId)
                ->first();
        }

        DB::table('nurselink_smart_registration_documents')
            ->where('id', $documentId)
            ->update([
                'extraction_status' => 'processing',
                'extraction_message' => 'NurseLink is extracting information from this document.',
                'updated_at' => now(),
            ]);

        $result = $this->ocr->extract(
            Storage::disk('local')->path($document->storage_path),
            $document->original_name,
            false,
            function (int $pass, int $completed, array $details) use ($documentId): void {
                $details['pipeline']=['version'=>1,'pass'=>$pass,'completed_passes'=>$completed,'total_passes'=>3,'status'=>'processing'];
                $labels=[1=>'Reading document text',2=>'Mapping text to fields',3=>'Checking evidence and field formats'];
                DB::table('nurselink_smart_registration_documents')->where('id',$documentId)->update([
                    'extracted_fields'=>json_encode(['_document'=>$details],JSON_INVALID_UTF8_SUBSTITUTE),
                    'extraction_message'=>'Pass '.$pass.' of 3: '.$labels[$pass].'.',
                    'updated_at'=>now(),
                ]);
            }
        );

        $encodedFields = json_encode(
            [...$result['fields'], '_structured' => $result['structured_records'] ?? [], '_document' => $result['extraction_details'] ?? []],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($encodedFields === false) {
            throw new RuntimeException('Unable to encode extracted Smart Registration fields.');
        }

        DB::table('nurselink_smart_registration_documents')
            ->where('id', $documentId)
            ->update([
                'document_type' => $result['document_type'],
                'extraction_status' => $result['status'],
                'extracted_fields' => $encodedFields,
                'extraction_message' => $result['message'],
                'extracted_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('nurselink_smart_registration_profiles')
            ->where('user_id', $document->user_id)
            ->update(['last_extracted_at' => now(), 'updated_at' => now()]);

        if ($result['pending'] ?? false) {
            \App\Jobs\ProcessSmartRegistrationDocument::dispatch($documentId)->onConnection('database')->delay(now()->addSeconds(2));
        }

        return DB::table('nurselink_smart_registration_documents')
            ->where('id', $documentId)
            ->first();
    }

    public function markFailed(int $documentId, string $message): void
    {
        DB::table('nurselink_smart_registration_documents')
            ->where('id', $documentId)
            ->update([
                'extraction_status' => 'failed',
                'extraction_message' => mb_substr($message, 0, 1000),
                'updated_at' => now(),
            ]);
    }

    private function passesSecurityScan(object $document): bool
    {
        if (! Schema::hasColumn('nurselink_smart_registration_documents', 'security_status')) {
            throw new RuntimeException('Smart Registration security migration has not been applied.');
        }

        $driver = (string) config('security_scanning.driver', 'disabled');
        if ($driver === 'disabled') {
            DB::table('nurselink_smart_registration_documents')
                ->where('id', $document->id)
                ->update([
                    'security_status' => 'unavailable',
                    'security_message' => 'Automatic security scanning is unavailable. Enter required information manually or contact NurseLink Support.',
                    'extraction_status' => 'needs_input',
                    'extraction_message' => 'OCR was not run because document security scanning is unavailable.',
                    'updated_at' => now(),
                ]);

            return false;
        }

        DB::table('nurselink_smart_registration_documents')
            ->where('id', $document->id)
            ->update([
                'security_status' => 'scanning',
                'security_message' => 'NurseLink is checking this document before extraction.',
                'updated_at' => now(),
            ]);

        try {
            $result = $this->scanner->scan('local', $document->storage_path);
        } catch (\Throwable $error) {
            DB::table('nurselink_smart_registration_documents')
                ->where('id', $document->id)
                ->update([
                    'security_status' => 'scan_failed',
                    'security_message' => 'Security scanning is temporarily unavailable and will retry.',
                    'updated_at' => now(),
                ]);
            throw $error;
        }

        if ($result['status'] !== 'clean') {
            $quarantinePath = 'nurselink-quarantine/'.basename((string) $document->storage_path);
            if (! Storage::disk('local')->move($document->storage_path, $quarantinePath)) {
                throw new RuntimeException('Unable to quarantine an unsafe Smart Registration document.');
            }
            DB::table('nurselink_smart_registration_documents')
                ->where('id', $document->id)
                ->update([
                    'storage_path' => $quarantinePath,
                    'security_status' => 'quarantined',
                    'security_message' => 'The uploaded document was blocked by the security scanner.',
                    'security_scanned_at' => now(),
                    'extraction_status' => 'blocked',
                    'extraction_message' => 'OCR was not run on a quarantined document.',
                    'extracted_fields' => null,
                    'updated_at' => now(),
                ]);

            return false;
        }

        DB::table('nurselink_smart_registration_documents')
            ->where('id', $document->id)
            ->update([
                'security_status' => 'clean',
                'security_message' => 'Document security scan passed.',
                'security_scanned_at' => now(),
                'updated_at' => now(),
            ]);

        return true;
    }
}
