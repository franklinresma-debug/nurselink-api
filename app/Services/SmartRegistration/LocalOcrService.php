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
            'pdf' => $this->extractPdfText($path),
            'jpg', 'jpeg', 'png' => $this->extractImageText($path),
            default => '',
        };

        $text = $this->normalizeText($text);
        $documentType = $this->detectDocumentType($originalName."\n".mb_substr($text, 0, 12000));
        $fields = $text === '' ? [] : $this->extractFields($text, $documentType);

        return [
            'document_type' => $documentType,
            'status' => $text === '' ? 'needs_input' : 'extracted',
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

    public function capabilities(): array
    {
        return [
            'pdf_text' => $this->commandAvailable('pdftotext') || $this->commandAvailable('gs'),
            'image_ocr' => $this->commandAvailable('tesseract'),
            'pdf_scan_ocr' => $this->commandAvailable('pdftoppm') && $this->commandAvailable('tesseract'),
            'docx_text' => class_exists(\ZipArchive::class),
            'doc_text' => $this->commandAvailable('antiword') || $this->commandAvailable('catdoc'),
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

    private function extractPdfText(string $path): string
    {
        if ($this->commandAvailable('pdftotext')) {
            $output = $this->runCommand(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
            if (trim($output) !== '') {
                return $output;
            }
        }
        if ($this->commandAvailable('gs')) {
            $output = $this->runCommand(['gs', '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-sDEVICE=txtwrite', '-sOutputFile=-', $path]);
            if (trim($output) !== '') {
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

    private function detectDocumentType(string $text): string
    {
        $value = mb_strtolower($text);
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
            return in_array($extension, ['jpg', 'jpeg', 'png'], true) ? 'OCR completed for the image.' : 'Document text extracted.';
        }

        return 'Document saved securely. Automatic extraction could not read it, so NurseLink will request the missing information.';
    }
}
