<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NurseLinkDesignSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminAppearanceSettingsController extends Controller
{
    private const SCOPE = 'global';
    private const LOGO_PLACEMENTS = ['login', 'admin', 'sidebar', 'favicon'];

    public function published(): JsonResponse
    {
        $record = NurseLinkDesignSetting::query()
            ->where('scope', self::SCOPE)->where('status', 'published')
            ->orderByDesc('version')->first();
        $payload = $this->sharedSidebarPayload($record?->payload ?? []);

        return response()->json(['data' => [
            'version' => $record?->version,
            'globalDesign' => ['theme' => $payload['globalDesign']['theme'] ?? null],
            'branding' => $payload['branding'] ?? ['logos' => []],
            'heroSliders' => $payload['heroSliders'] ?? [],
        ]])->header('Cache-Control', 'public, max-age=60, must-revalidate');
    }

    public function logoAsset(string $filename): BinaryFileResponse
    {
        abort_unless(preg_match('/^[a-z0-9][a-z0-9._-]+$/i', $filename), 404);

        $path = "nurselink/appearance/{$filename}";
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(
            Storage::disk('local')->path($path),
            ['Cache-Control' => 'public, max-age=31536000, immutable']
        );
    }

    public function current(Request $request): JsonResponse
    {
        $published = NurseLinkDesignSetting::query()
            ->where('scope', self::SCOPE)->where('status', 'published')
            ->orderByDesc('version')->first();

        $draft = NurseLinkDesignSetting::query()
            ->where('scope', self::SCOPE)->where('status', 'draft')
            ->orderByDesc('version')->first();

        return response()->json(['data' => [
            'published' => $this->serialize($published),
            'draft' => $this->serialize($draft),
        ]]);
    }

    public function saveDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payload' => ['required', 'array'],
            'payload.globalDesign' => ['present', 'array'],
            'payload.globalDesign.theme' => ['sometimes', 'array'],
            'payload.globalDesign.theme.enabled' => ['sometimes', 'boolean'],
            'payload.globalDesign.theme.colors' => ['sometimes', 'array'],
            'payload.globalDesign.theme.colors.*' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'payload.branding.logos.favicon' => ['sometimes', 'array'],
            'payload.branding.logos.favicon.enabled' => ['sometimes', 'boolean'],
            'payload.branding.logos.favicon.source' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'payload.branding.logos.favicon.alt' => ['sometimes', 'nullable', 'string', 'max:190'],
            'payload.branding.logos.favicon.icons' => ['sometimes', 'array', 'max:20'],
            'payload.branding.logos.favicon.icons.*.source' => ['required', 'string', 'regex:~^/api/nurselink/appearance-assets/favicon-[a-z0-9-]+\.(png|ico)$~'],
            'payload.branding.logos.favicon.icons.*.sizes' => ['required', 'string', 'max:500', 'regex:/^\d+x\d+( \d+x\d+)*$/'],
            'payload.branding.logos.favicon.icons.*.type' => ['required', 'in:image/png,image/x-icon'],
            'payload.branding.logos.favicon.icons.*.name' => ['required', 'string', 'max:255'],
            'payload.heroSliders' => ['required', 'array'],
            'payload.heroSliders.login' => ['required', 'array'],
            'payload.heroSliders.login.controls' => ['sometimes', 'array'],
            'payload.heroSliders.login.controls.sliderLeftOffset' => ['sometimes', 'integer', 'min:-400', 'max:200'],
            'payload.heroSliders.login.controls.sliderRightPercent' => ['sometimes', 'integer', 'min:10', 'max:55'],
            'payload.heroSliders.login.controls.rightFadeWidth' => ['sometimes', 'integer', 'min:0', 'max:420'],
            'payload.heroSliders.login.controls.imageFit' => ['sometimes', 'in:cover,contain'],
            'payload.heroSliders.login.controls.imageFocusX' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'payload.heroSliders.login.controls.imageFocusY' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'payload.heroSliders.registration' => ['required', 'array'],
            'payload.branding' => ['sometimes', 'array'],
            'payload.branding.logos' => ['sometimes', 'array'],
            'payload.branding.logos.login' => ['sometimes', 'array'],
            'payload.branding.logos.login.enabled' => ['sometimes', 'boolean'],
            'payload.branding.logos.login.source' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'payload.branding.logos.login.alt' => ['sometimes', 'nullable', 'string', 'max:190'],
            'payload.branding.logos.login.sizePercent' => ['sometimes', 'integer', 'min:50', 'max:220'],
            // LBS_LOGO_BOX_SETTINGS_V2
            'payload.branding.logos.login.fitBox' => ['sometimes', 'boolean'],
            'payload.branding.logos.login.sidePadding' => ['sometimes', 'integer', 'min:0', 'max:40'],
            'payload.branding.logos.admin' => ['sometimes', 'array'],
            'payload.branding.logos.admin.enabled' => ['sometimes', 'boolean'],
            'payload.branding.logos.admin.source' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'payload.branding.logos.admin.alt' => ['sometimes', 'nullable', 'string', 'max:190'],
            'payload.branding.logos.admin.sizePercent' => ['sometimes', 'integer', 'min:50', 'max:220'],
            'payload.branding.logos.admin.sidePadding' => ['sometimes', 'integer', 'min:0', 'max:40'],
            'payload.branding.logos.sidebar' => ['sometimes', 'array'],
            'payload.branding.logos.sidebar.enabled' => ['sometimes', 'boolean'],
            'payload.branding.logos.sidebar.source' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'payload.branding.logos.sidebar.alt' => ['sometimes', 'nullable', 'string', 'max:190'],
            'payload.branding.logos.sidebar.sizePercent' => ['sometimes', 'integer', 'min:50', 'max:220'],
            'payload.branding.logos.sidebar.sidePadding' => ['sometimes', 'integer', 'min:0', 'max:40'],
        ]);

        $validated['payload'] = $this->sharedSidebarPayload($validated['payload']);

        $record = DB::transaction(function () use ($request, $validated) {
            $nextVersion = ((int) NurseLinkDesignSetting::query()
                ->where('scope', self::SCOPE)->max('version')) + 1;

            return NurseLinkDesignSetting::create([
                'scope' => self::SCOPE,
                'version' => $nextVersion,
                'status' => 'draft',
                'payload' => $validated['payload'],
                'created_by' => optional($request->user())->id,
            ]);
        });

        return response()->json(['data' => $this->serialize($record)], 201);
    }

    public function uploadLogo(Request $request, string $placement): JsonResponse
    {
        abort_unless(in_array($placement, self::LOGO_PLACEMENTS, true), 404);

        if ($placement === 'favicon') return $this->uploadFavicon($request);

        $validated = $request->validate([
            'logo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $file = $validated['logo'];
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $filename = sprintf('%s-%s.%s', $placement, Str::uuid(), $extension);
        $file->storeAs('nurselink/appearance', $filename, 'local');

        return response()->json(['data' => [
            'placement' => $placement,
            'source' => "/api/nurselink/appearance-assets/{$filename}",
            'filename' => $filename,
        ]], 201);
    }

    private function uploadFavicon(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'file', 'max:2048']]);
        $file = $request->file('logo');
        $extension = strtolower($file->getClientOriginalExtension());
        $bytes = file_get_contents($file->getRealPath());
        $sizes = [];
        if ($extension === 'png') {
            $info = @getimagesize($file->getRealPath());
            abort_unless($info && $info[2] === IMAGETYPE_PNG && $info[0] === $info[1] && $info[0] <= 1024, 422, 'Use a square PNG up to 1024 × 1024 pixels.');
            $sizes[] = $info[0].'x'.$info[1];
        } elseif ($extension === 'ico') {
            abort_unless(strlen($bytes) >= 6 && substr($bytes, 0, 4) === "\x00\x00\x01\x00", 422, 'This is not a valid ICO file.');
            $count = unpack('v', substr($bytes, 4, 2))[1];
            abort_unless($count > 0 && $count <= 32 && strlen($bytes) >= 6 + 16 * $count, 422, 'Invalid ICO directory.');
            for ($i = 0; $i < $count; $i++) {
                $entry = substr($bytes, 6 + 16 * $i, 16);
                $width = ord($entry[0]) ?: 256; $height = ord($entry[1]) ?: 256;
                $part = unpack('Vlength/Voffset', substr($entry, 8, 8));
                abort_unless($width === $height && $part['length'] >= 8 && $part['offset'] >= 6 + 16 * $count && $part['offset'] + $part['length'] <= strlen($bytes), 422, 'Invalid ICO image data.');
                $image = substr($bytes, $part['offset'], $part['length']);
                abort_unless(substr($image, 0, 8) === "\x89PNG\r\n\x1a\n" || (strlen($image) >= 40 && unpack('V', substr($image, 0, 4))[1] === 40), 422, 'Unsupported ICO image data.');
                $sizes[] = $width.'x'.$height;
            }
        } else { abort(422, 'Upload PNG or ICO files.'); }
        $filename = 'favicon-'.Str::uuid().'.'.$extension;
        $file->storeAs('nurselink/appearance', $filename, 'local');
        return response()->json(['data' => [
            'source' => '/api/nurselink/appearance-assets/'.$filename,
            'name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'sizes' => implode(' ', array_unique($sizes)),
            'type' => $extension === 'png' ? 'image/png' : 'image/x-icon',
        ]], 201);
    }

    public function publish(Request $request, int $version): JsonResponse
    {
        $record = NurseLinkDesignSetting::query()
            ->where('scope', self::SCOPE)->where('version', $version)
            ->firstOrFail();

        DB::transaction(function () use ($request, $record) {
            NurseLinkDesignSetting::query()
                ->where('scope', self::SCOPE)->where('status', 'published')
                ->update(['status' => 'archived']);

            $record->update([
                'status' => 'published',
                'published_by' => optional($request->user())->id,
                'published_at' => now(),
            ]);
        });

        return response()->json(['data' => $this->serialize($record->fresh())]);
    }

    public function history(): JsonResponse
    {
        $items = NurseLinkDesignSetting::query()
            ->where('scope', self::SCOPE)->orderByDesc('version')
            ->limit(50)->get()->map(fn ($item) => $this->serialize($item));

        return response()->json(['data' => $items]);
    }

    public function restore(Request $request, int $version): JsonResponse
    {
        $source = NurseLinkDesignSetting::query()
            ->where('scope', self::SCOPE)->where('version', $version)
            ->firstOrFail();

        $record = DB::transaction(function () use ($request, $source) {
            $nextVersion = ((int) NurseLinkDesignSetting::query()
                ->where('scope', self::SCOPE)->max('version')) + 1;

            return NurseLinkDesignSetting::create([
                'scope' => self::SCOPE,
                'version' => $nextVersion,
                'status' => 'draft',
                'payload' => $source->payload,
                'created_by' => optional($request->user())->id,
                'restored_from_version' => $source->version,
            ]);
        });

        return response()->json(['data' => $this->serialize($record)], 201);
    }

    private function sharedSidebarPayload(array $payload): array
    {
        $logos = $payload['branding']['logos'] ?? [];
        // Sidebar is canonical; admin remains a compatibility alias for older clients.
        $shared = $logos['sidebar'] ?? $logos['admin'] ?? null;
        if ($shared !== null) {
            $payload['branding']['logos']['sidebar'] = $shared;
            $payload['branding']['logos']['admin'] = $shared;
        }
        return $payload;
    }

    private function serialize(?NurseLinkDesignSetting $record): ?array
    {
        if (!$record) return null;

        return [
            'id' => $record->id,
            'scope' => $record->scope,
            'version' => $record->version,
            'status' => $record->status,
            'payload' => $this->sharedSidebarPayload($record->payload),
            'created_by' => $record->created_by,
            'published_by' => $record->published_by,
            'published_at' => optional($record->published_at)?->toIso8601String(),
            'restored_from_version' => $record->restored_from_version,
            'created_at' => optional($record->created_at)?->toIso8601String(),
            'updated_at' => optional($record->updated_at)?->toIso8601String(),
        ];
    }
}
