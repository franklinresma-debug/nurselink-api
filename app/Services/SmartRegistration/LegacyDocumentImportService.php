<?php

namespace App\Services\SmartRegistration;

use App\Jobs\ProcessSmartRegistrationDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LegacyDocumentImportService
{
    public function importForUser(string $userId): array
    {
        if (! Schema::hasTable('applications') || ! Schema::hasTable('application_documents')) {
            return ['imported' => 0, 'duplicates' => 0, 'skipped' => 0];
        }

        $sources = DB::table('application_documents as d')
            ->join('applications as a', 'a.id', '=', 'd.application_id')
            ->where('a.user_id', $userId)
            ->where('d.malware_scan_status', 'clean')
            ->select('d.*')
            ->orderBy('d.created_at')
            ->get();

        $result = ['imported' => 0, 'duplicates' => 0, 'skipped' => 0];

        foreach ($sources as $source) {
            if (DB::table('nurselink_smart_registration_documents')
                ->where('user_id', $userId)
                ->where('sha256', $source->sha256)
                ->exists()) {
                $result['duplicates']++;
                continue;
            }

            $sourceDisk = (string) ($source->disk ?: 'private');
            if (! Storage::disk($sourceDisk)->exists($source->path)) {
                $result['skipped']++;
                continue;
            }

            $extension = strtolower(pathinfo((string) $source->stored_name, PATHINFO_EXTENSION));
            $extension = in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], true)
                ? $extension
                : 'bin';
            $target = 'nurselink-smart-registration/imported/'
                . preg_replace('/[^A-Za-z0-9._-]/', '_', $userId)
                . '/' . Str::uuid() . '.' . $extension;

            $read = Storage::disk($sourceDisk)->readStream($source->path);
            if (! is_resource($read)) {
                $result['skipped']++;
                continue;
            }

            try {
                if (! Storage::disk('local')->writeStream($target, $read)) {
                    throw new RuntimeException('Unable to copy legacy evidence into Smart Registration storage.');
                }
            } finally {
                fclose($read);
            }

            $absolute = Storage::disk('local')->path($target);
            if (! is_file($absolute) || ! hash_equals((string) $source->sha256, hash_file('sha256', $absolute))) {
                Storage::disk('local')->delete($target);
                throw new RuntimeException('Imported Smart Registration evidence failed checksum verification.');
            }

            $insert = [
                'user_id' => $userId,
                'original_name' => mb_substr((string) $source->original_name, 0, 255),
                'storage_path' => $target,
                'mime_type' => mb_substr((string) $source->mime_type, 0, 160),
                'file_size' => (int) $source->size_bytes,
                'sha256' => (string) $source->sha256,
                'document_type' => $this->documentType((string) $source->category),
                'extraction_status' => 'queued',
                'extraction_message' => 'Imported from the applicant’s earlier NurseLink application and queued for extraction.',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('nurselink_smart_registration_documents', 'security_status')) {
                $insert['security_status'] = 'clean';
                $insert['security_message'] = 'Previously scanned by NurseLink; checksum verified during import.';
                $insert['security_scanned_at'] = $source->malware_scanned_at ?: now();
            }
            if (Schema::hasColumn('nurselink_smart_registration_documents', 'version')) $insert['version'] = 1;
            if (Schema::hasColumn('nurselink_smart_registration_documents', 'is_current')) $insert['is_current'] = true;

            $id = DB::table('nurselink_smart_registration_documents')->insertGetId($insert);
            ProcessSmartRegistrationDocument::dispatch($id);
            $result['imported']++;
        }

        return $result;
    }

    private function documentType(string $category): string
    {
        return match (strtolower(trim($category))) {
            'cv', 'resume' => 'cv',
            'license', 'prc_license' => 'prc_license',
            'diploma', 'degree' => 'nursing_diploma',
            'passport', 'identity', 'id' => 'identity',
            default => 'other',
        };
    }
}
