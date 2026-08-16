<?php

namespace App\Services\SmartRegistration;

use App\Models\ApplicationDocument;

interface DocumentExtractor
{
    /** @return array<int,array{field_path:string,value:?string,confidence:?float,source_page:?int,source_label:?string}> */
    public function extract(ApplicationDocument $document): array;

    public function name(): string;

    public function version(): ?string;
}
