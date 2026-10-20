<?php

namespace App\Services\SmartRegistration;

use Illuminate\Support\Facades\DB;

class EligibilityInsightService
{
    public function forProfile(object $profile, array $documents): array
    {
        $education = strtolower((string) ($profile->highest_nursing_education ?? ''));
        $title = strtolower((string) ($profile->professional_title ?? ''));
        $specialty = trim((string) ($profile->specialty ?? ''));
        $years = (int) ($profile->years_experience ?? 0);
        $hasLicense = trim((string) ($profile->primary_license_number ?? '')) !== '';
        $hasEducationEvidence = collect($documents)->contains(fn (array $doc): bool =>
            in_array((string) ($doc['document_type'] ?? ''), ['nursing_diploma', 'education', 'transcript'], true)
        );

        $pqf = null;
        if (preg_match('/doctor|ph\.?d|dns|dnm/', $education)) {
            $pqf = ['level' => 8, 'basis' => 'Doctoral nursing qualification reported'];
        } elseif (preg_match('/master|m\.a\.|msn|mn\b/', $education)) {
            $pqf = ['level' => 7, 'basis' => 'Master’s nursing qualification reported'];
        } elseif (preg_match('/bachelor|bsn|b\.s\. nursing/', $education)) {
            $pqf = ['level' => 6, 'basis' => 'Bachelor-level nursing qualification reported'];
        }

        if ($pqf) {
            $pqf['status'] = $hasEducationEvidence ? 'document_supported_pending_verification' : 'self_reported_pending_evidence';
            $pqf['license_recorded'] = $hasLicense;
            $pqf['official_decision'] = false;
        }

        $positions = ['Staff Nurse'];
        if ($specialty !== '') $positions[] = $specialty.' Nurse';
        if ($years >= 3) $positions[] = 'Senior Staff Nurse';
        if ($years >= 5) $positions[] = 'Charge Nurse / Team Lead';
        if ($years >= 7) $positions[] = 'Nurse Supervisor';
        if (str_contains($title, 'educator') || str_contains($education, 'master')) $positions[] = 'Clinical Nurse Educator';

        $countries = DB::table('qualification_frameworks')
            ->where('scope', 'destination')
            ->orderBy('jurisdiction')
            ->get(['jurisdiction', 'name', 'governance_status', 'assessment_enabled', 'effective_from', 'effective_to'])
            ->map(fn (object $framework): array => [
                'country_code' => $framework->jurisdiction,
                'framework' => $framework->name,
                'readiness' => $framework->assessment_enabled && $framework->governance_status === 'published'
                    ? 'assessment_available'
                    : 'rules_pending_validation',
                'potential_match' => null,
                'effective_from' => $framework->effective_from,
                'effective_to' => $framework->effective_to,
            ])->values()->all();

        return [
            'saudi_readiness' => app(SaudiReadinessService::class)->forDocuments($documents),
            'pqf' => $pqf,
            'suggested_positions' => array_values(array_unique($positions)),
            'country_readiness' => $countries,
            'evidence' => [
                'documents_uploaded' => count($documents),
                'education_evidence_detected' => $hasEducationEvidence,
                'license_number_recorded' => $hasLicense,
                'years_experience' => $years,
            ],
            'advisory' => 'These are evidence-based job-matching signals, not an official qualification, licensing, immigration, or hiring decision. Country eligibility is shown only when governed assessment rules are published.',
        ];
    }
}
