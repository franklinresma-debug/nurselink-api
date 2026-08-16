<?php

namespace App\Services\SmartRegistration;

use App\Models\ApplicationDocument;
use Illuminate\Support\Facades\Storage;

class LocalOcrDocumentExtractor implements DocumentExtractor
{
    public function __construct(private readonly LocalOcrService $ocr) {}

    public function extract(ApplicationDocument $document): array
    {
        $result = $this->ocr->extract(
            Storage::disk($document->disk)->path($document->path),
            $document->original_name
        );

        return collect($result['fields'])->map(
            fn (array $candidate, string $field): array => [
                'field_path' => $field,
                'value' => is_scalar($candidate['value'] ?? null) ? (string) $candidate['value'] : null,
                'confidence' => $candidate['confidence'] ?? null,
                'source_page' => null,
                'source_label' => $result['document_type'],
            ]
        )->values()->all();
    }

    public function name(): string
    {
        return 'local_ocr';
    }

    public function version(): ?string
    {
        return 'NL-012-ocr.1';
    }
}
