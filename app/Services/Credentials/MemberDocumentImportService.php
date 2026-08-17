<?php

namespace App\Services\Credentials;

use App\Models\Application;
use App\Models\Member;
use App\Models\MemberDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MemberDocumentImportService
{
    public function fromApprovedApplication(Member $member, Application $application): int
    {
        $count = 0;

        foreach ($application->documents()->get() as $source) {
            MemberDocument::query()->firstOrCreate([
                'member_id' => $member->id,
                'source_application_document_id' => $source->id,
            ], [
                'document_type' => $source->category,
                'title' => $source->original_name,
                'original_name' => $source->original_name,
                'storage_disk' => $source->disk,
                'storage_path' => $source->path,
                'mime_type' => $source->mime_type,
                'size_bytes' => $source->size_bytes,
                'sha256' => $source->sha256,
                'security_status' => $source->malware_scan_status === 'clean'
                    ? 'clean'
                    : $source->malware_scan_status,
                'visibility' => 'private',
            ]);
            $count++;
        }

        $count += $this->fromSmartRegistrationEvidence($member, $application);

        return $count;
    }

    private function fromSmartRegistrationEvidence(Member $member, Application $application): int
    {
        $table = 'nurselink_smart_registration_documents';
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table)
            ->where('user_id', $application->user_id);

        if (Schema::hasColumn($table, 'is_current')) {
            $query->where('is_current', true);
        }

        $count = 0;
        foreach ($query->get() as $source) {
            MemberDocument::query()->firstOrCreate([
                'member_id' => $member->id,
                'sha256' => $source->sha256,
            ], [
                'source_application_document_id' => null,
                'document_type' => $source->document_type ?: 'other',
                'title' => $source->original_name,
                'original_name' => $source->original_name,
                'storage_disk' => 'local',
                'storage_path' => $source->storage_path,
                'mime_type' => $source->mime_type,
                'size_bytes' => $source->file_size,
                'security_status' => $source->security_status ?: 'pending',
                'visibility' => 'private',
            ]);
            $count++;
        }

        return $count;
    }
}
