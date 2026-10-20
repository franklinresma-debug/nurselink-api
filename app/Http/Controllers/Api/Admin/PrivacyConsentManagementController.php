<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\NurseLinkPolicySetting;
use App\Models\NurseLinkPolicyVersion;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrivacyConsentManagementController extends Controller
{
    public function show(): JsonResponse
    {
        $published = NurseLinkPolicySetting::query()
            ->where('status', 'published')
            ->latest('published_at')
            ->first();

        $draft = NurseLinkPolicySetting::query()
            ->where('status', 'draft')
            ->latest('updated_at')
            ->first();

        return response()->json([
            'data' => [
                'published' => $published,
                'draft' => $draft,
                'editor' => $draft ?: $published,
            ],
        ]);
    }

    public function update(
        Request $request,
        AuditLogger $audit
    ): JsonResponse {
        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:190'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'privacy_email' => ['nullable', 'email', 'max:190'],

            'terms_version' => ['required', 'string', 'max:40'],
            'terms_effective_date' => ['nullable', 'date'],
            'terms_content' => ['nullable', 'string'],

            'privacy_version' => ['required', 'string', 'max:40'],
            'privacy_effective_date' => ['nullable', 'date'],
            'privacy_content' => ['nullable', 'string'],
        ]);

        $draft = NurseLinkPolicySetting::query()
            ->where('status', 'draft')
            ->latest('updated_at')
            ->first();

        if (! $draft) {
            $draft = new NurseLinkPolicySetting();
        }

        $draft->fill($data);
        $draft->status = 'draft';
        $draft->updated_by = $request->user()->id;
        $draft->published_by = null;
        $draft->published_at = null;
        $draft->save();

        $audit->write(
            'policy_consent.draft_saved',
            $request->user(),
            'nurselink_policy_setting',
            $draft->id,
            [
                'terms_version' => $draft->terms_version,
                'privacy_version' => $draft->privacy_version,
            ],
            $request
        );

        return response()->json([
            'data' => $draft->refresh(),
            'message' => 'Privacy and consent draft saved.',
        ]);
    }

    public function publish(
        Request $request,
        AuditLogger $audit
    ): JsonResponse {
        $data = $request->validate([
            'publish_terms' => ['required', 'boolean'],
            'publish_privacy' => ['required', 'boolean'],
        ]);

        if (! $data['publish_terms'] && ! $data['publish_privacy']) {
            return response()->json([
                'message' => 'Select Terms, Privacy, or both to publish.',
            ], 422);
        }

        $draft = NurseLinkPolicySetting::query()
            ->where('status', 'draft')
            ->latest('updated_at')
            ->first();

        if (! $draft) {
            return response()->json([
                'message' => 'There are no draft policy changes to publish.',
            ], 422);
        }

        $current = NurseLinkPolicySetting::query()
            ->where('status', 'published')
            ->latest('published_at')
            ->first();

        if (
            $data['publish_terms']
            && NurseLinkPolicyVersion::query()
                ->where('policy_type', 'terms')
                ->where('version', $draft->terms_version)
                ->exists()
        ) {
            return response()->json([
                'message' => 'That Terms version has already been published. Use a new version identifier.',
            ], 422);
        }

        if (
            $data['publish_privacy']
            && NurseLinkPolicyVersion::query()
                ->where('policy_type', 'privacy')
                ->where('version', $draft->privacy_version)
                ->exists()
        ) {
            return response()->json([
                'message' => 'That Privacy version has already been published. Use a new version identifier.',
            ], 422);
        }

        $published = DB::transaction(function () use (
            $draft,
            $current,
            $data,
            $request
        ) {
            $now = now();

            $termsVersion = $data['publish_terms']
                ? $draft->terms_version
                : ($current?->terms_version
                    ?: config('registration.terms_version'));

            $termsEffectiveDate = $data['publish_terms']
                ? $draft->terms_effective_date
                : $current?->terms_effective_date;

            $termsContent = $data['publish_terms']
                ? $draft->terms_content
                : $current?->terms_content;

            $privacyVersion = $data['publish_privacy']
                ? $draft->privacy_version
                : ($current?->privacy_version
                    ?: config('registration.privacy_version'));

            $privacyEffectiveDate = $data['publish_privacy']
                ? $draft->privacy_effective_date
                : $current?->privacy_effective_date;

            $privacyContent = $data['publish_privacy']
                ? $draft->privacy_content
                : $current?->privacy_content;

            if ($data['publish_terms']) {
                NurseLinkPolicyVersion::query()->create([
                    'policy_type' => 'terms',
                    'version' => $draft->terms_version,
                    'effective_date' => $draft->terms_effective_date,
                    'content' => $draft->terms_content ?: '',
                    'organization_name' => $draft->organization_name,
                    'contact_email' => $draft->contact_email,
                    'privacy_email' => $draft->privacy_email,
                    'published_by' => $request->user()->id,
                    'published_at' => $now,
                ]);
            }

            if ($data['publish_privacy']) {
                NurseLinkPolicyVersion::query()->create([
                    'policy_type' => 'privacy',
                    'version' => $draft->privacy_version,
                    'effective_date' => $draft->privacy_effective_date,
                    'content' => $draft->privacy_content ?: '',
                    'organization_name' => $draft->organization_name,
                    'contact_email' => $draft->contact_email,
                    'privacy_email' => $draft->privacy_email,
                    'published_by' => $request->user()->id,
                    'published_at' => $now,
                ]);
            }

            $published = NurseLinkPolicySetting::query()->create([
                'organization_name' => $draft->organization_name,
                'contact_email' => $draft->contact_email,
                'privacy_email' => $draft->privacy_email,

                'terms_version' => $termsVersion,
                'terms_effective_date' => $termsEffectiveDate,
                'terms_content' => $termsContent,

                'privacy_version' => $privacyVersion,
                'privacy_effective_date' => $privacyEffectiveDate,
                'privacy_content' => $privacyContent,

                'status' => 'published',
                'updated_by' => $request->user()->id,
                'published_by' => $request->user()->id,
                'published_at' => $now,
            ]);

            $draft->delete();

            return $published;
        });

        $audit->write(
            'policy_consent.settings_published',
            $request->user(),
            'nurselink_policy_setting',
            $published->id,
            [
                'publish_terms' => $data['publish_terms'],
                'publish_privacy' => $data['publish_privacy'],
                'terms_version' => $published->terms_version,
                'privacy_version' => $published->privacy_version,
            ],
            $request
        );

        return response()->json([
            'data' => $published->refresh(),
            'message' => 'Privacy and consent settings published.',
        ]);
    }

    public function history(): JsonResponse
    {
        return response()->json([
            'data' => NurseLinkPolicyVersion::query()
                ->latest('published_at')
                ->limit(100)
                ->get(),
        ]);
    }
}
