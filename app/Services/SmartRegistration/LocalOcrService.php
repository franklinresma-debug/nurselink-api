<?php

namespace App\Services\SmartRegistration;

use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class LocalOcrService
{
    private const MAX_EXTRACTED_TEXT = 120000;

    public function extract(string $path, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $text = match ($extension) {
            'docx' => $this->extractDocxText($path),
            'doc' => $this->extractLegacyDocText($path),
            'xlsx' => $this->extractXlsxText($path),
            'xls' => $this->extractLegacySpreadsheetText($path),
            'pdf' => $this->extractPdfText($path),
            'jpg', 'jpeg', 'png' => $this->extractImageText($path),
            default => '',
        };

        $text = in_array($extension, ['xls', 'xlsx'], true)
            ? $this->normalizeSpreadsheetText($text)
            : $this->normalizeText($text);
        $documentType = in_array($extension, ['xls', 'xlsx'], true)
            ? 'spreadsheet'
            : $this->detectDocumentType($originalName."\n".mb_substr($text, 0, 12000));
        $fields = $text === '' ? [] : $this->extractFields($text, $documentType);

        return [
            'document_type' => $documentType,
            'status' => $text === '' ? 'needs_input' : 'extracted',
            /* Stored internally for structured CV section extraction. */
            'text' => $text,
            'fields' => $fields,
            'message' => $this->message($extension, $text !== ''),
        ];
    }

    public function extractFields(string $text, string $documentType = 'other'): array
    {
        $fields = [];
        $lines = array_values(array_filter(array_map('trim', preg_split('/\n/u', $text) ?: [])));
        $compact = preg_replace('/\s+/u', ' ', $text) ?? $text;

        $this->addRegexField($fields, 'email', $compact, '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', 0.94);
        $this->addRegexField($fields, 'phone', $compact, '/(?<!\d)(?:\+?63|0)?9\d{9}(?!\d)/u', 0.88);

        $patterns = [
            'first_name' => ['first name'],
            'middle_name' => ['middle name'],
            'last_name' => ['last name', 'surname', 'family name'],
            'birth_date' => ['date of birth', 'birth date', 'birthday'],
            'nationality' => ['nationality', 'citizenship'],
            'address_line1' => ['address', 'residential address', 'home address'],
            'current_employer' => ['current employer', 'employer', 'hospital', 'facility', 'institution', 'company'],
            'current_position' => ['current position', 'position', 'job title', 'designation', 'role'],
            'specialty' => ['specialty', 'specialisation', 'specialization'],
            'primary_license_number' => ['prc license no', 'prc license number', 'registration no', 'registration number', 'license no', 'license number', 'licence no', 'licence number'],
            'primary_license_expiry' => ['expiry date', 'expiration date', 'valid until'],
            'highest_nursing_education' => ['degree', 'qualification', 'education', 'course', 'program', 'programme'],
        ];

        foreach ($patterns as $field => $labels) {
            $candidate = $this->valueAfterLabel($lines, $labels);
            if ($candidate === null) {
                continue;
            }
            if (in_array($field, ['birth_date', 'primary_license_expiry'], true)) {
                $candidate = $this->normalizeDate($candidate);
                if ($candidate === null) {
                    continue;
                }
            }
            if ($field === 'highest_nursing_education') {
                $candidate = $this->normalizeEducation($candidate);
            }
            $fields[$field] = [
                'value' => $candidate,
                'confidence' => in_array($field, ['primary_license_number', 'birth_date'], true) ? 0.86 : 0.78,
            ];
        }

        if ($documentType === 'cv') {
            $this->extractCvHeadingFields($fields, $lines);
        }

        if (! isset($fields['professional_title']) && preg_match('/\b(registered nurse|staff nurse|charge nurse|head nurse|nurse manager|clinical nurse|nursing officer|nurse educator|nurse practitioner)\b/iu', $compact, $match)) {
            $fields['professional_title'] = ['value' => ucwords(mb_strtolower($match[1])), 'confidence' => $documentType === 'cv' ? 0.86 : 0.72];
        }

        if (preg_match('/\b(\d{1,2})\+?\s+years?\s+(?:of\s+)?(?:nursing\s+)?experience\b/iu', $compact, $match)) {
            $fields['years_experience'] = ['value' => (int) $match[1], 'confidence' => 0.88];
        }

        if (! isset($fields['highest_nursing_education']) && preg_match('/\b(bachelor(?:\'s)? of science in nursing|bsn|bsc nursing|master(?:\'s)? of science in nursing|msn|doctor of nursing practice|dnp|diploma in nursing)\b/iu', $compact, $match)) {
            $fields['highest_nursing_education'] = ['value' => $this->normalizeEducation($match[1]), 'confidence' => 0.9];
        }

        if (preg_match('/\b(?:graduated|graduation|year graduated|class of)\D{0,12}(19\d{2}|20\d{2})\b/iu', $compact, $match)) {
            $fields['graduation_year'] = ['value' => (int) $match[1], 'confidence' => 0.78];
        }

        if ($documentType === 'prc_license') {
            $fields['primary_license_country'] = ['value' => 'Philippines', 'confidence' => 0.98];
        }

        return array_filter($fields, fn (array $candidate): bool => ($candidate['value'] ?? null) !== null && $candidate['value'] !== '');
    }

    private function extractCvHeadingFields(array &$fields, array $lines): void
    {
        $heading = array_slice($lines, 0, 18);

        if (! isset($fields['first_name']) || ! isset($fields['last_name'])) {
            foreach ($heading as $index => $line) {
                if (! preg_match('/\b(curriculum vitae|resume|résumé)\b/iu', $line)) {
                    continue;
                }

                foreach (array_slice($heading, $index + 1, 4) as $nameLine) {
                    $name = $this->parseCvName($nameLine);
                    if ($name === []) {
                        continue;
                    }

                    foreach ($name as $field => $value) {
                        $fields[$field] ??= [
                            'value' => $value,
                            'confidence' => 0.76,
                        ];
                    }

                    break 2;
                }
            }
        }

        if (! isset($fields['address_line1'])) {
            foreach ($heading as $line) {
                if (! preg_match('/^\d{1,5}\s+.+|\b(street|st\.|road|rd\.|avenue|ave\.|barangay|village|subdivision)\b/iu', $line)) {
                    continue;
                }

                $fields['address_line1'] = [
                    'value' => mb_substr($line, 0, 255),
                    'confidence' => 0.72,
                ];

                if (! isset($fields['city']) && preg_match('/\b([\p{L}-]+\s+city)\b/iu', $line, $city)) {
                    $fields['city'] = [
                        'value' => $city[1],
                        'confidence' => 0.74,
                    ];
                }

                if (! isset($fields['country']) && preg_match('/\b(philippines|phil\.)\b/iu', $line)) {
                    $fields['country'] = [
                        'value' => 'Philippines',
                        'confidence' => 0.84,
                    ];
                }

                break;
            }
        }

        if (! isset($fields['current_position'])) {
            foreach ($lines as $line) {
                if (preg_match('/^(.{3,100}?):.{0,100}\b(?:present|current)\b/iu', $line, $match)) {
                    $fields['current_position'] = [
                        'value' => trim($match[1]),
                        'confidence' => 0.70,
                    ];

                    break;
                }
            }
        }
    }

    private function parseCvName(string $line): array
    {
        $clean = trim(preg_replace('/\([^)]*\)/u', '', $line) ?? '');
        $clean = preg_replace('/^[^\p{L}]+/u', '', $clean) ?? $clean;

        if (! preg_match('/^[\p{L} .\'-]{5,120}$/u', $clean)) {
            return [];
        }

        $parts = array_values(array_filter(
            preg_split('/\s+/u', $clean) ?: [],
            fn (string $part): bool => preg_match('/^[\p{L}.\'-]+$/u', $part) === 1
        ));

        if (count($parts) < 2 || count($parts) > 6) {
            return [];
        }

        $last = array_pop($parts);
        $first = $parts[0];

        if (mb_strtolower($first) === 'ma.' && count($parts) > 1) {
            $first .= ' '.$parts[1];
            array_splice($parts, 1, 1);
        }

        $result = [
            'first_name' => $first,
            'last_name' => $last,
        ];

        if (count($parts) > 1) {
            $result['middle_name'] = implode(' ', array_slice($parts, 1));
        }

        return $result;
    }

    public function capabilities(): array
    {
        return [
            'pdf_text' => $this->commandAvailable('pdftotext') || $this->commandAvailable('gs'),
            'image_ocr' => $this->commandAvailable('tesseract'),
            'pdf_scan_ocr' => $this->commandAvailable('pdftoppm') && $this->commandAvailable('tesseract'),
            'docx_text' => class_exists(\ZipArchive::class),
            'doc_text' => $this->commandAvailable('antiword') || $this->commandAvailable('catdoc'),
            'xlsx_text' => class_exists(\ZipArchive::class),
            'extractor' => 'local_ocr',
            'ready' => $this->commandAvailable('tesseract') && $this->commandAvailable('pdftotext'),
        ];
    }

    private function extractDocxText(string $path): string
    {
        if (! class_exists(\ZipArchive::class)) {
            return '';
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();
        $xml = preg_replace('/<w:tab[^>]*\/>/i', "\t", $xml) ?? $xml;
        $xml = preg_replace('/<w:br[^>]*\/>/i', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:p>/i', "\n", $xml) ?? $xml;

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function extractLegacyDocText(string $path): string
    {
        foreach (['antiword', 'catdoc'] as $command) {
            if ($this->commandAvailable($command)) {
                $output = $this->runCommand([$command, $path]);
                if (trim($output) !== '') {
                    return $output;
                }
            }
        }

        return '';
    }

    private function extractXlsxText(string $path): string
    {
        if (! class_exists(\ZipArchive::class)) {
            return '';
        }

        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }

        try {
            $sharedStrings = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml') ?: '';

            if ($sharedXml !== '') {
                preg_match_all('/<si\\b[^>]*>(.*?)<\\/si>/si', $sharedXml, $items);
                foreach ($items[1] ?? [] as $item) {
                    $text = preg_replace('/<t\\b[^>]*>/i', '', $item) ?? $item;
                    $text = preg_replace('/<\\/t>/i', '', $text) ?? $text;
                    $sharedStrings[] = html_entity_decode(trim(strip_tags($text)), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }

            $sheets = [];
            for ($index = 1; $index <= 50; $index++) {
                $xml = $zip->getFromName('xl/worksheets/sheet'.$index.'.xml');
                if ($xml === false) {
                    continue;
                }

                $rows = [];
                preg_match_all('/<row\\b[^>]*>(.*?)<\\/row>/si', $xml, $matches);
                foreach ($matches[1] ?? [] as $rowXml) {
                    $cells = [];
                    preg_match_all('/<c\\b([^>]*)>(.*?)<\\/c>/si', $rowXml, $cellMatches, PREG_SET_ORDER);

                    foreach ($cellMatches as $cellIndex => $cell) {
                        $attributes = $cell[1] ?? '';
                        $body = $cell[2] ?? '';
                        $value = '';
                        $column = $cellIndex;

                        if (preg_match('/\br="([A-Z]+)\d+"/i', $attributes, $reference)) {
                            $column = $this->xlsxColumnIndex($reference[1]);
                        }

                        if (preg_match('/t="s"/i', $attributes) && preg_match('/<v>(.*?)<\\/v>/si', $body, $valueMatch)) {
                            $value = $sharedStrings[(int) trim($valueMatch[1])] ?? '';
                        } elseif (preg_match('/t="inlineStr"/i', $attributes)) {
                            $value = html_entity_decode(trim(strip_tags($body)), ENT_QUOTES | ENT_XML1, 'UTF-8');
                        } elseif (preg_match('/<v>(.*?)<\\/v>/si', $body, $valueMatch)) {
                            $value = html_entity_decode(trim(strip_tags($valueMatch[1])), ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }

                        $cells[$column] = trim($value);
                    }

                    if ($cells !== []) {
                        ksort($cells);
                        $cells = array_map(
                            fn (int $index): string => $cells[$index] ?? '',
                            range(0, max(array_keys($cells)))
                        );
                    }

                    $line = trim(implode("\t", $cells));
                    if ($line !== '') {
                        $rows[] = $line;
                    }
                }

                if ($rows !== []) {
                    $sheets[] = 'Worksheet '.$index."\n".implode("\n", $rows);
                }
            }

            return implode("\n\n", $sheets);
        } finally {
            $zip->close();
        }
    }

    private function extractLegacySpreadsheetText(string $path): string
    {
        foreach (['xls2csv', 'ssconvert'] as $command) {
            if (! $this->commandAvailable($command)) {
                continue;
            }

            $output = $command === 'ssconvert'
                ? $this->runCommand([$command, '--export-type=Gnumeric_stf:stf_csv', $path, 'fd://1'])
                : $this->runCommand([$command, $path]);

            if (trim($output) !== '') {
                return $output;
            }
        }

        return '';
    }

    private function xlsxColumnIndex(string $column): int
    {
        $index = 0;
        foreach (str_split(strtoupper($column)) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function extractPdfText(string $path): string
    {
        if ($this->commandAvailable('pdftotext')) {
            $output = $this->runCommand(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
            if ($this->hasMeaningfulText($output)) {
                return $output;
            }
        }
        if ($this->commandAvailable('gs')) {
            $output = $this->runCommand(['gs', '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-sDEVICE=txtwrite', '-sOutputFile=-', $path]);
            if ($this->hasMeaningfulText($output)) {
                return $output;
            }
        }
        if ($this->commandAvailable('pdftoppm') && $this->commandAvailable('tesseract')) {
            $tmp = storage_path('app/nurselink-ocr-'.Str::random(16));
            $maxPages = max(1, min(20, (int) config('smart_registration.ocr.max_pdf_pages', 5)));
            $this->runCommand(['pdftoppm', '-f', '1', '-l', (string) $maxPages, '-jpeg', '-r', '200', $path, $tmp]);
            $images = glob($tmp.'-*.jpg') ?: [];
            natsort($images);
            $pages = [];
            try {
                foreach ($images as $image) {
                    $page = trim($this->extractImageText($image));
                    if ($page !== '') {
                        $pages[] = $page;
                    }
                }
            } finally {
                foreach ($images as $image) {
                    @unlink($image);
                }
            }

            return implode("\n\n", $pages);
        }

        return '';
    }

    private function hasMeaningfulText(string $text): bool
    {
        /*
         * Image-only PDFs can yield only form-feed/page-break characters
         * from pdftotext. Those are not usable text and must fall through
         * to the PDF-to-image OCR path.
         */
        return preg_match('/[\p{L}\p{N}]/u', $text) === 1;
    }

    private function extractImageText(string $path): string
    {
        return $this->commandAvailable('tesseract')
            ? $this->runCommand([
                'tesseract',
                $path,
                'stdout',
                '--psm',
                '6',
                '-l',
                (string) config('smart_registration.ocr.language', 'eng'),
            ])
            : '';
    }

    private function runCommand(array $command): string
    {
        try {
            $process = new Process($command);
            $process->setTimeout(max(5, (int) config('smart_registration.ocr.process_timeout', 45)));
            $process->setIdleTimeout(max(5, (int) config('smart_registration.ocr.idle_timeout', 20)));
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function commandAvailable(string $command): bool
    {
        return (new ExecutableFinder)->find($command) !== null;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim(mb_substr($text, 0, self::MAX_EXTRACTED_TEXT));
    }

    /** Keep TSV column boundaries intact for row-by-row roster import. */
    private function normalizeSpreadsheetText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ' '], $text);

        return trim(mb_substr($text, 0, self::MAX_EXTRACTED_TEXT));
    }

    private function detectDocumentType(string $text): string
    {
        $value = mb_strtolower($text);

        /*
         * A CV often mentions professional registration or licence details.
         * Prefer an explicit heading or a CV filename over those incidental
         * keywords so CV-specific name and address extraction can run.
         */
        if (preg_match('/(?:^|\n)[^\p{L}\n]{0,8}(?:curriculum vitae|resume|résumé)\b/iu', $text)
            || preg_match('/^\s*cv\.(?:pdf|docx?|png|jpe?g)\b/iu', $text)) {
            return 'cv';
        }

        if (preg_match('/\b(prc|professional regulation commission|registration no|license no|licence no)\b/u', $value)) {
            return 'prc_license';
        }
        if (preg_match('/\b(passport|national id|identification card|driver.?s license)\b/u', $value)) {
            return 'identity';
        }
        if (preg_match('/\b(curriculum vitae|\bcv\b|resume|résumé|work experience|employment history)\b/u', $value)) {
            return 'cv';
        }
        if (preg_match('/\b(diploma|bachelor of science in nursing|bsn|bsc nursing|nursing degree|transcript)\b/u', $value)) {
            return 'nursing_diploma';
        }
        if (preg_match('/\b(employment certificate|certificate of employment|employment verification)\b/u', $value)) {
            return 'employment_certificate';
        }
        if (preg_match('/\b(training certificate|certificate of completion|bls|acls|pals|iv therapy)\b/u', $value)) {
            return 'training_certificate';
        }
        if (preg_match('/\b(nursing council|registered nurse license|registered nurse licence|board of nursing)\b/u', $value)) {
            return 'international_license';
        }

        return 'other';
    }

    private function valueAfterLabel(array $lines, array $labels): ?string
    {
        foreach ($lines as $index => $line) {
            foreach ($labels as $label) {
                if (! preg_match('/^\s*'.preg_quote($label, '/').'\s*[:#-]?\s*(.*)$/iu', $line, $match)) {
                    continue;
                }
                $value = trim((string) ($match[1] ?? ''));
                if ($value === '' && isset($lines[$index + 1])) {
                    $value = trim((string) $lines[$index + 1]);
                }
                if ($value !== '' && mb_strlen($value) <= 255) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function addRegexField(array &$fields, string $field, string $text, string $pattern, float $confidence): void
    {
        if (preg_match($pattern, $text, $match)) {
            $fields[$field] = ['value' => trim((string) $match[0]), 'confidence' => $confidence];
        }
    }

    private function normalizeDate(string $value): ?string
    {
        $time = strtotime(trim($value));
        if ($time === false) {
            return null;
        }
        $year = (int) date('Y', $time);

        return $year >= 1900 && $year <= ((int) date('Y') + 30) ? date('Y-m-d', $time) : null;
    }

    private function normalizeEducation(string $value): string
    {
        return match (mb_strtolower(trim($value))) {
            'bsn', 'bsc nursing' => 'Bachelor of Science in Nursing',
            'msn' => 'Master of Science in Nursing',
            'dnp' => 'Doctor of Nursing Practice',
            default => ucwords(mb_strtolower(trim($value))),
        };
    }

    private function message(string $extension, bool $success): string
    {
        if ($success) {
            return in_array($extension, ['jpg', 'jpeg', 'png'], true)
                ? 'OCR completed for the image.'
                : (in_array($extension, ['xlsx', 'xls'], true)
                    ? 'Excel worksheet text extracted.'
                    : 'Document text extracted.');
        }

        return 'Document saved securely. Automatic extraction could not read it, so NurseLink will request the missing information.';
    }
}
