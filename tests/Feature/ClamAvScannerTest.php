<?php

namespace Tests\Feature;

use App\Services\Security\ClamAvScanner;
use RuntimeException;
use Tests\TestCase;

class ClamAvScannerTest extends TestCase
{
    public function test_clean_clamav_response_allows_document_processing(): void
    {
        $result = app(ClamAvScanner::class)->classifyResponse('stream: OK');

        $this->assertSame('clean', $result['status']);
        $this->assertSame('stream: OK', $result['response']);
    }

    public function test_infected_clamav_response_is_classified_for_quarantine(): void
    {
        $result = app(ClamAvScanner::class)->classifyResponse(
            "stream: Eicar-Test-Signature FOUND\0"
        );

        $this->assertSame('infected', $result['status']);
        $this->assertStringContainsString('FOUND', $result['response']);
    }

    public function test_unexpected_clamav_response_fails_closed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected ClamAV response');

        app(ClamAvScanner::class)->classifyResponse('UNKNOWN');
    }
}
