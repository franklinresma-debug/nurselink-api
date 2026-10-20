<?php
namespace App\Services;

class DocumentOcrConfidence
{
    public function summary(object $document): array
    {
        $fields = json_decode($document->extracted_fields ?? '{}', true) ?: [];
        $details = $fields['_document'] ?? [];
        $active = in_array($document->extraction_status, ['queued', 'processing'], true);
        $fresh = !$active || ($document->extraction_status === 'processing' && ($details['complete'] ?? null) === false);
        $blocked = in_array($document->security_status ?? '', ['quarantined', 'unavailable'], true);
        $qualities = $fresh && !$blocked ? (isset($details['pages']) ? array_column($details['pages'], 'ocr_quality') : [$details['ocr_quality'] ?? null]) : [];
        $scores = [];
        foreach ($qualities as $quality) {
            $value = $quality['mean_confidence'] ?? null;
            if (!(bool)($quality['fallback'] ?? false) && (is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 0 && $value <= 100) $scores[] = $value;
        }
        return ['percent' => $scores ? round(array_sum($scores) / count($scores), 1) : null,
            'scored_pages' => count($scores), 'label' => 'Estimated OCR text confidence'];
    }
}
