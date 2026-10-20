<?php

namespace App\Services\Policy;

use App\Models\NurseLinkPolicySetting;

class NurseLinkPolicySettingsService
{
    public function current(): array
    {
        $row = NurseLinkPolicySetting::query()
            ->where('status', 'published')
            ->latest('published_at')
            ->first();

        return [
            'organization_name' => $row?->organization_name ?: 'NurseLink',
            'contact_email' => $row?->contact_email,
            'privacy_email' => $row?->privacy_email,

            'terms_version' =>
                (string) ($row?->terms_version
                    ?: config('registration.terms_version')),

            'privacy_version' =>
                (string) ($row?->privacy_version
                    ?: config('registration.privacy_version')),

            'terms_effective_date' =>
                $row?->terms_effective_date?->toDateString(),

            'privacy_effective_date' =>
                $row?->privacy_effective_date?->toDateString(),

            'terms_content' => $row?->terms_content,
            'privacy_content' => $row?->privacy_content,

            'published_at' =>
                $row?->published_at?->toIso8601String(),
        ];
    }

    public function termsVersion(): string
    {
        return $this->current()['terms_version'];
    }

    public function privacyVersion(): string
    {
        return $this->current()['privacy_version'];
    }
}
