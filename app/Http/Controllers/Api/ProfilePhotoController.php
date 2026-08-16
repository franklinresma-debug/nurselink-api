<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfilePhotoController extends Controller
{
    private const DISK = 'local';
    private const MAX_KB = 5120;

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->present($request),
        ]);
    }

    public function image(Request $request): BinaryFileResponse
    {
        $path = $request->user()->profile_photo_path;

        abort_unless(
            is_string($path) &&
            $path !== '' &&
            Storage::disk(self::DISK)->exists($path),
            404
        );

        $absolute = Storage::disk(self::DISK)->path($path);
        $mime = Storage::disk(self::DISK)->mimeType($path) ?: 'image/jpeg';

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'photo' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:' . self::MAX_KB,
                'dimensions:min_width=160,min_height=160,max_width=5000,max_height=5000',
            ],
        ]);

        $user = $request->user();
        $photo = $validated['photo'];

        $extension = strtolower((string) ($photo->guessExtension() ?: 'jpg'));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }

        $directory = 'profile-photos/' . $user->getKey();
        $filename = (string) Str::uuid() . '.' . $extension;

        $newPath = Storage::disk(self::DISK)->putFileAs(
            $directory,
            $photo,
            $filename
        );

        abort_unless(is_string($newPath) && $newPath !== '', 500, 'Unable to store profile photo.');

        $oldPath = $user->profile_photo_path;

        $user->forceFill([
            'profile_photo_path' => $newPath,
        ])->save();

        if (
            is_string($oldPath) &&
            $oldPath !== '' &&
            $oldPath !== $newPath &&
            Storage::disk(self::DISK)->exists($oldPath)
        ) {
            Storage::disk(self::DISK)->delete($oldPath);
        }

        return response()->json([
            'message' => 'Profile photo updated.',
            'data' => $this->present($request),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $oldPath = $user->profile_photo_path;

        $user->forceFill([
            'profile_photo_path' => null,
        ])->save();

        if (
            is_string($oldPath) &&
            $oldPath !== '' &&
            Storage::disk(self::DISK)->exists($oldPath)
        ) {
            Storage::disk(self::DISK)->delete($oldPath);
        }

        return response()->json([
            'message' => 'Profile photo removed.',
            'data' => [
                'profile_photo_url' => null,
                'has_profile_photo' => false,
            ],
        ]);
    }

    private function present(Request $request): array
    {
        $user = $request->user();
        $path = $user->profile_photo_path;

        $hasPhoto = is_string($path) &&
            $path !== '' &&
            Storage::disk(self::DISK)->exists($path);

        return [
            'profile_photo_url' => $hasPhoto
                ? url('/api/profile-photo/image?v=' . rawurlencode((string) $user->updated_at?->timestamp))
                : null,
            'has_profile_photo' => $hasPhoto,
            'accepted_formats' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_size_mb' => 5,
        ];
    }
}
