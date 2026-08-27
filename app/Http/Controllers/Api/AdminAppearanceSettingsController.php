<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NurseLinkDesignSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAppearanceSettingsController extends Controller
{
    private const SCOPE = 'global';

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
            'payload.globalDesign' => ['required', 'array'],
            'payload.heroSliders' => ['required', 'array'],
            'payload.heroSliders.login' => ['required', 'array'],
            'payload.heroSliders.registration' => ['required', 'array'],
        ]);

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

    private function serialize(?NurseLinkDesignSetting $record): ?array
    {
        if (!$record) return null;

        return [
            'id' => $record->id,
            'scope' => $record->scope,
            'version' => $record->version,
            'status' => $record->status,
            'payload' => $record->payload,
            'created_by' => $record->created_by,
            'published_by' => $record->published_by,
            'published_at' => optional($record->published_at)?->toIso8601String(),
            'restored_from_version' => $record->restored_from_version,
            'created_at' => optional($record->created_at)?->toIso8601String(),
            'updated_at' => optional($record->updated_at)?->toIso8601String(),
        ];
    }
}
