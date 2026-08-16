<?php

namespace App\Services\SmartRegistration;

use App\Models\ApplicationDocument;

class ManualReviewExtractor implements DocumentExtractor
{
    public function extract(ApplicationDocument $document): array
    {
        return [];
    }

    public function name(): string
    {
        return 'manual_review';
    }

    public function version(): ?string
    {
        return 'NL-004';
    }
}
