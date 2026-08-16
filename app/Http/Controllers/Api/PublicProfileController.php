<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicProfileController extends Controller
{
    private const PHOTO_DISK = 'local';
    private const FRONTEND_URL = 'https://app.amsertech.com';

    public function settings(Request $request): JsonResponse
    {
        $membership = $this->approvedMembership($request->user()->getKey());

        $row = $this->ensureSettings(
            (string) $request->user()->getKey(),
            $membership->member_number
        );

        return response()->json([
            'data' => $this->presentSettings($row),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $membership = $this->approvedMembership($request->user()->getKey());

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'headline' => ['nullable', 'string', 'max:190'],
            'bio' => ['nullable', 'string', 'max:3000'],
            'show_photo' => ['required', 'boolean'],
            'show_member_number' => ['required', 'boolean'],
            'show_credentials' => ['required', 'boolean'],
            'show_employment' => ['required', 'boolean'],
            'show_portfolio' => ['required', 'boolean'],
            'show_learning' => ['required', 'boolean'],
        ]);

        $row = $this->ensureSettings(
            (string) $request->user()->getKey(),
            $membership->member_number
        );

        DB::table('nurselink_public_profiles')
            ->where('id', $row->id)
            ->update([
                ...$data,
                'updated_at' => now(),
            ]);

        $updated = DB::table('nurselink_public_profiles')
            ->where('id', $row->id)
            ->first();

        return response()->json([
            'message' => 'Public profile settings updated.',
            'data' => $this->presentSettings($updated),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $profile = DB::table('nurselink_public_profiles')
            ->where('slug', $slug)
            ->where('enabled', true)
            ->first();

        abort_unless($profile, 404);

        $membership = DB::table('nurselink_memberships')
            ->where('user_id', $profile->user_id)
            ->where('status', 'approved')
            ->first();

        abort_unless($membership, 404);

        $standing = strtolower(trim((string) (
            $membership->standing ?? 'active'
        )));

        abort_unless(
            $standing === '' || $standing === 'active',
            404
        );

        $user = DB::table('users')
            ->where('id', $profile->user_id)
            ->first();

        abort_unless($user, 404);

        $data = [
            'slug' => $profile->slug,
            'member_name' => $this->displayName($user),
            'headline' => $profile->headline,
            'bio' => $profile->bio,
            'membership' => [
                'verified' => true,
                'member_number' => $profile->show_member_number
                    ? $membership->member_number
                    : null,
                'approved_at' => $membership->approved_at,
            ],
            'photo_url' => $profile->show_photo && $this->hasPhoto($user)
                ? url('/api/public-profile/' . rawurlencode($profile->slug) . '/photo')
                : null,
            'credentials' => [],
            'employment' => [],
            'portfolio' => [],
            'learning' => [],
            'disclaimers' => [
                'membership' => 'NurseLink membership verification is not a PRC license or government ID.',
                'learning' => 'CPD units shown are self-reported unless independently verified.',
            ],
        ];

        if ($profile->show_credentials) {
            $data['credentials'] = DB::table('nurselink_credentials_registry')
                ->where('user_id', $profile->user_id)
                ->where('verification_status', 'verified')
                ->orderByDesc('verification_status')
                ->orderByDesc('issue_date')
                ->limit(50)
                ->get()
                ->map(fn ($row) => [
                    'credential_type' => $row->credential_type,
                    'title' => $row->title,
                    'issuing_body' => $row->issuing_body,
                    'country' => $row->country,
                    'issue_date' => $row->issue_date,
                    'expiry_date' => $row->expiry_date,
                    'verification_status' => $row->verification_status,
                ])
                ->values();
        }

        if ($profile->show_employment) {
            $data['employment'] = DB::table('nurselink_employment_histories')
                ->where('user_id', $profile->user_id)
                ->orderByDesc('is_current')
                ->orderByDesc('start_date')
                ->limit(50)
                ->get()
                ->map(fn ($row) => [
                    'employer_name' => $row->employer_name,
                    'country' => $row->country,
                    'city' => $row->city,
                    'position' => $row->position,
                    'specialty' => $row->specialty,
                    'start_date' => $row->start_date,
                    'end_date' => $row->end_date,
                    'is_current' => (bool) $row->is_current,
                    'is_overseas' => (bool) $row->is_overseas,
                ])
                ->values();
        }

        if ($profile->show_portfolio) {
            $data['portfolio'] = DB::table('nurselink_portfolio_items')
                ->where('user_id', $profile->user_id)
                ->where('visibility', 'public')
                ->orderByDesc('is_featured')
                ->orderByDesc('start_date')
                ->limit(50)
                ->get()
                ->map(fn ($row) => [
                    'item_type' => $row->item_type,
                    'title' => $row->title,
                    'organization' => $row->organization,
                    'location' => $row->location,
                    'start_date' => $row->start_date,
                    'end_date' => $row->end_date,
                    'description' => $row->description,
                    'reference_url' => $row->reference_url,
                    'is_featured' => (bool) $row->is_featured,
                ])
                ->values();
        }

        if ($profile->show_learning) {
            $data['learning'] = DB::table('nurselink_learning_records')
                ->where('user_id', $profile->user_id)
                ->where('status', 'completed')
                ->orderByDesc('completed_at')
                ->limit(50)
                ->get()
                ->map(fn ($row) => [
                    'learning_type' => $row->learning_type,
                    'title' => $row->title,
                    'provider' => $row->provider,
                    'topic' => $row->topic,
                    'completed_at' => $row->completed_at,
                    'learning_hours' => $row->learning_hours !== null
                        ? (float) $row->learning_hours
                        : null,
                    'cpd_units' => $row->cpd_units !== null
                        ? (float) $row->cpd_units
                        : null,
                    'certificate_url' => $row->certificate_url,
                ])
                ->values();
        }

        return response()->json(['data' => $data]);
    }

    public function photo(string $slug): BinaryFileResponse
    {
        $profile = DB::table('nurselink_public_profiles')
            ->where('slug', $slug)
            ->where('enabled', true)
            ->where('show_photo', true)
            ->first();

        abort_unless($profile, 404);

        $membership = DB::table('nurselink_memberships')
            ->where('user_id', $profile->user_id)
            ->where('status', 'approved')
            ->first();

        abort_unless($membership, 404);

        $standing = strtolower(trim((string) (
            $membership->standing ?? 'active'
        )));

        abort_unless(
            $standing === '' || $standing === 'active',
            404
        );

        $user = DB::table('users')
            ->where('id', $profile->user_id)
            ->first();

        abort_unless($user && $this->hasPhoto($user), 404);

        $path = $user->profile_photo_path;
        $absolute = Storage::disk(self::PHOTO_DISK)->path($path);
        $mime = Storage::disk(self::PHOTO_DISK)->mimeType($path) ?: 'image/jpeg';

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=1800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function approvedMembership(string $userId): object
    {
        $membership = DB::table('nurselink_memberships')
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->first();

        abort_unless($membership, 403, 'Approved NurseLink membership is required.');

        $standing = strtolower(trim((string) (
            $membership->standing ?? 'active'
        )));

        abort_unless(
            $standing === '' || $standing === 'active',
            403,
            'Active NurseLink membership standing is required.'
        );

        return $membership;
    }

    private function ensureSettings(string $userId, ?string $memberNumber): object
    {
        $existing = DB::table('nurselink_public_profiles')
            ->where('user_id', $userId)
            ->first();

        if ($existing) return $existing;

        $base = $memberNumber
            ? Str::slug(strtolower($memberNumber))
            : 'nurselink-member-' . substr(sha1($userId), 0, 12);

        $slug = $base;
        $counter = 2;

        while (
            DB::table('nurselink_public_profiles')
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        $id = DB::table('nurselink_public_profiles')->insertGetId([
            'user_id' => $userId,
            'slug' => $slug,
            'enabled' => false,
            'show_photo' => true,
            'show_member_number' => true,
            'show_credentials' => true,
            'show_employment' => true,
            'show_portfolio' => true,
            'show_learning' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('nurselink_public_profiles')
            ->where('id', $id)
            ->first();
    }

    private function presentSettings(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'slug' => $row->slug,
            'enabled' => (bool) $row->enabled,
            'headline' => $row->headline,
            'bio' => $row->bio,
            'show_photo' => (bool) $row->show_photo,
            'show_member_number' => (bool) $row->show_member_number,
            'show_credentials' => (bool) $row->show_credentials,
            'show_employment' => (bool) $row->show_employment,
            'show_portfolio' => (bool) $row->show_portfolio,
            'show_learning' => (bool) $row->show_learning,
            'share_url' => self::FRONTEND_URL
                . '/nurselink-public-profile.html?slug='
                . rawurlencode($row->slug),
        ];
    }

    private function displayName(object $user): string
    {
        $name = trim((string) ($user->name ?? ''));

        if ($name !== '') return $name;

        return trim(
            (string) ($user->first_name ?? '')
            . ' '
            . (string) ($user->last_name ?? '')
        );
    }

    private function hasPhoto(object $user): bool
    {
        if (! Schema::hasColumn('users', 'profile_photo_path')) return false;

        $path = $user->profile_photo_path ?? null;

        return is_string($path)
            && $path !== ''
            && Storage::disk(self::PHOTO_DISK)->exists($path);
    }
}
