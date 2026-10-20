<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CvMakerController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $member->load([
            'profile',
            'portfolioSummary',
            'education' => fn ($query) => $query->orderBy('completed_on'),
            'employment' => fn ($query) => $query->orderBy('started_on'),
            'competencies',
            'languages',
            'credentials',
        ]);

        return response()->json([
            'data' => [
                'member' => $member,
                'settings' => $this->publicSettings($member),
                'signature' => $this->signature($member),
            ],
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'include_photo' => ['boolean'],
            'template' => ['sometimes', 'string', 'in:classic,executive,modern'],
            'sections' => ['array'],
            'sections.*' => ['string', 'in:summary,experience,education,credentials,competencies,languages'],
        ]);

        $member = $this->member($request);
        $settings = $this->settings($member);
        if (array_key_exists('template', $data)) {
            $settings['template'] = $data['template'];
        }
        $settings['include_photo'] = (bool) ($data['include_photo'] ?? true);
        $settings['sections'] = array_values($data['sections'] ?? []);

        $member->portfolioSummary()->updateOrCreate(
            ['member_id' => $member->id],
            ['cv_maker_settings' => json_encode($settings)]
        );

        $member->load('portfolioSummary');

        return response()->json([
            'message' => 'CV display settings saved.',
            'data' => ['settings' => $this->publicSettings($member)],
        ]);
    }

    public function storeSignature(Request $request): JsonResponse
    {
        $data = $request->validate([
            'signature' => [
                'required', 'file', 'image', 'mimes:png', 'max:2048',
                'dimensions:min_width=80,min_height=40,max_width=4000,max_height=2000',
            ],
        ]);

        $member = $this->member($request);
        $settings = $this->settings($member);
        $previousPath = $settings['signature_path'] ?? null;
        $path = $data['signature']->storeAs(
            'cv-signatures/'.$request->user()->id,
            Str::uuid().'.png',
            'local'
        );

        if (is_string($previousPath) && Storage::disk('local')->exists($previousPath)) {
            Storage::disk('local')->delete($previousPath);
        }

        $settings['signature_path'] = $path;
        $member->portfolioSummary()->updateOrCreate(
            ['member_id' => $member->id],
            ['cv_maker_settings' => json_encode($settings)]
        );
        $member->load('portfolioSummary');

        return response()->json([
            'message' => 'Signature saved.',
            'data' => $this->signature($member),
        ]);
    }

    public function signatureImage(Request $request): BinaryFileResponse
    {
        $member = $this->member($request);
        $path = $this->settings($member)['signature_path'] ?? null;

        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroySignature(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $settings = $this->settings($member);
        $path = $settings['signature_path'] ?? null;

        if (is_string($path) && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }

        unset($settings['signature_path']);
        $member->portfolioSummary()->updateOrCreate(
            ['member_id' => $member->id],
            ['cv_maker_settings' => json_encode($settings)]
        );

        return response()->json([
            'message' => 'Signature removed.',
            'data' => ['has_signature' => false, 'signature_url' => null],
        ]);
    }

    private function member(Request $request): Member
    {
        return Member::where('user_id', $request->user()->id)->firstOrFail();
    }

    private function settings(Member $member): array
    {
        $summary = $member->portfolioSummary()->first();
        $settings = json_decode((string) ($summary?->cv_maker_settings ?? '{}'), true);

        return is_array($settings) ? $settings : [];
    }

    private function publicSettings(Member $member): array
    {
        $settings = $this->settings($member);
        unset($settings['signature_path']);

        return $settings;
    }

    private function signature(Member $member): array
    {
        $settings = $this->settings($member);
        $path = $settings['signature_path'] ?? null;
        $hasSignature = is_string($path) && Storage::disk('local')->exists($path);
        $updatedAt = $member->portfolioSummary()->value('updated_at');

        return [
            'has_signature' => $hasSignature,
            'signature_url' => $hasSignature
                ? rtrim((string) config('app.url'), '/') . '/api/cv-maker/signature/image?v=' . rawurlencode((string) optional($updatedAt)->timestamp)
                : null,
        ];
    }
}
