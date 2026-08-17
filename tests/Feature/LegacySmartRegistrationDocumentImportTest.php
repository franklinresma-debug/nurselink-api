<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\User;
use App\Services\SmartRegistration\LegacyDocumentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacySmartRegistrationDocumentImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_only_clean_legacy_evidence_and_is_idempotent(): void
    {
        Storage::fake('private');
        Storage::fake('local');
        Bus::fake();
        $user = User::factory()->create();
        $application = Application::query()->create([
            'application_no' => 'NLA-2026-999991',
            'user_id' => $user->id,
            'status' => 'in_progress',
            'progress_percent' => 0,
            'profile_data' => [],
        ]);
        $contents = 'clean legacy nursing CV';
        Storage::disk('private')->put('legacy/cv.pdf', $contents);
        ApplicationDocument::query()->create([
            'application_id' => $application->id,
            'uploaded_by_user_id' => $user->id,
            'category' => 'cv',
            'original_name' => 'Nursing CV.pdf',
            'stored_name' => 'cv.pdf',
            'disk' => 'private',
            'path' => 'legacy/cv.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'upload_status' => 'received',
            'malware_scan_status' => 'clean',
        ]);

        $first = app(LegacyDocumentImportService::class)->importForUser((string) $user->id);
        $second = app(LegacyDocumentImportService::class)->importForUser((string) $user->id);

        $this->assertSame(1, $first['imported']);
        $this->assertSame(1, $second['duplicates']);
        $row = \DB::table('nurselink_smart_registration_documents')->first();
        $this->assertSame('cv', $row->document_type);
        $this->assertSame('clean', $row->security_status);
        $this->assertSame(hash('sha256', $contents), $row->sha256);
        Storage::disk('local')->assertExists($row->storage_path);
    }
}
