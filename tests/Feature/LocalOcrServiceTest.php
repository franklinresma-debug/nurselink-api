<?php

namespace Tests\Feature;

use App\Jobs\ProcessSmartRegistrationDocument;
use App\Services\SmartRegistration\DocumentExtractor;
use App\Services\SmartRegistration\LocalOcrDocumentExtractor;
use App\Services\SmartRegistration\LocalOcrService;
use Tests\TestCase;

class LocalOcrServiceTest extends TestCase
{
    public function test_structured_nursing_text_is_mapped_to_registration_fields(): void
    {
        $fields = app(LocalOcrService::class)->extractFields(<<<'TEXT'
        Curriculum Vitae
        First Name: Maria
        Last Name: Santos
        Date of Birth: March 15, 1990
        Nationality: Filipino
        Email: maria.santos@example.com
        Mobile: +639171234567
        Current Position: Registered Nurse
        Current Employer: General Hospital
        8 years of nursing experience
        Degree: BSN
        Graduation: 2012
        TEXT, 'cv');

        $this->assertSame('Maria', $fields['first_name']['value']);
        $this->assertSame('Santos', $fields['last_name']['value']);
        $this->assertSame('1990-03-15', $fields['birth_date']['value']);
        $this->assertSame('maria.santos@example.com', $fields['email']['value']);
        $this->assertSame('+639171234567', $fields['phone']['value']);
        $this->assertSame(8, $fields['years_experience']['value']);
        $this->assertSame('Bachelor of Science in Nursing', $fields['highest_nursing_education']['value']);
        $this->assertSame(2012, $fields['graduation_year']['value']);
    }

    public function test_local_ocr_is_the_configured_legacy_pipeline_extractor(): void
    {
        $this->assertSame('local_ocr', config('smart_registration.extractor'));
        $this->assertInstanceOf(
            LocalOcrDocumentExtractor::class,
            app(DocumentExtractor::class)
        );
    }

    public function test_tesseract_extracts_fields_from_an_uploaded_image(): void
    {
        $capabilities = app(LocalOcrService::class)->capabilities();
        if (! $capabilities['image_ocr'] || ! function_exists('imagettftext')) {
            $this->markTestSkipped('Tesseract and GD FreeType are required for the OCR integration test.');
        }

        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        if (! is_file($font)) {
            $this->markTestSkipped('OCR fixture font is unavailable.');
        }

        $path = sys_get_temp_dir().'/nurselink-ocr-'.bin2hex(random_bytes(6)).'.png';
        $image = imagecreatetruecolor(1800, 500);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 1799, 499, $white);
        imagettftext($image, 38, 0, 40, 90, $black, $font, 'First Name: Maria');
        imagettftext($image, 38, 0, 40, 180, $black, $font, 'Last Name: Santos');
        imagettftext($image, 38, 0, 40, 270, $black, $font, 'Email: maria.santos@example.com');
        imagettftext($image, 38, 0, 40, 360, $black, $font, 'Professional Title: Registered Nurse');
        imagepng($image, $path);
        imagedestroy($image);

        try {
            $result = app(LocalOcrService::class)->extract($path, 'resume.png');
            $this->assertSame('extracted', $result['status']);
            $this->assertSame('Maria', $result['fields']['first_name']['value']);
            $this->assertSame('Santos', $result['fields']['last_name']['value']);
            $this->assertSame('maria.santos@example.com', $result['fields']['email']['value']);
        } finally {
            @unlink($path);
        }
    }

    public function test_unreadable_document_falls_back_to_missing_information(): void
    {
        $result = app(LocalOcrService::class)->extract(
            '/path/that/does/not/exist.png',
            'unreadable.png'
        );

        $this->assertSame('needs_input', $result['status']);
        $this->assertSame([], $result['fields']);
        $this->assertStringContainsString('missing information', $result['message']);
    }

    public function test_queued_extraction_has_retries_timeout_and_dedicated_queue(): void
    {
        $job = new ProcessSmartRegistrationDocument(123);

        $this->assertSame(3, $job->tries);
        $this->assertSame(180, $job->timeout);
        $this->assertSame([10, 30, 90], $job->backoff());
        $this->assertSame('document-extraction', $job->queue);
    }
}
