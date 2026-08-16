<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class JobOpportunityController extends Controller
{
    private const JOBS = 'nurselink_job_opportunities';

    public function index(Request $request): JsonResponse
    {
        $jobs = DB::table(self::JOBS)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        $profile = $this->memberProfile($request);

        $matched = $jobs
            ->map(fn ($job) => $this->presentMatch($job, $profile))
            ->sortByDesc('match_score')
            ->values();

        return response()->json(['data' => $matched]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $job = DB::table(self::JOBS)
            ->where('id', $id)
            ->first();

        abort_unless($job, 404);

        return response()->json([
            'data' => $this->presentMatch($job, $this->memberProfile($request)),
        ]);
    }

    private function memberProfile(Request $request): array
    {
        $userId = $request->user()->getKey();

        $career = DB::table('nurselink_career_preferences')
            ->where('user_id', $userId)
            ->first();

        $credentials = DB::table('nurselink_credentials_registry')
            ->where('user_id', $userId)
            ->get();

        $employment = DB::table('nurselink_employment_histories')
            ->where('user_id', $userId)
            ->get();

        return [
            'career' => $career,
            'credentials' => $credentials,
            'employment' => $employment,
            'experience_years' => $this->experienceYears($employment),
        ];
    }

    private function presentMatch(object $job, array $profile): array
    {
        [$score, $reasons, $gaps] = $this->score($job, $profile);

        return [
            'id' => (int) $job->id,
            'reference_code' => $job->reference_code,
            'title' => $job->title,
            'employer_name' => $job->employer_name,
            'country' => $job->country,
            'city' => $job->city,
            'work_setting' => $job->work_setting,
            'employment_type' => $job->employment_type,
            'specialty' => $job->specialty,
            'required_license_type' => $job->required_license_type,
            'minimum_experience_years' => (float) $job->minimum_experience_years,
            'overseas_opportunity' => (bool) $job->overseas_opportunity,
            'salary_min' => $job->salary_min !== null ? (float) $job->salary_min : null,
            'salary_max' => $job->salary_max !== null ? (float) $job->salary_max : null,
            'salary_currency' => $job->salary_currency,
            'description' => $job->description,
            'requirements' => $job->requirements,
            'apply_url' => $job->apply_url,
            'source_label' => $job->source_label,
            'status' => $job->status,
            'published_at' => $job->published_at,
            'expires_at' => $job->expires_at,
            'match_score' => $score,
            'match_reasons' => $reasons,
            'match_gaps' => $gaps,
        ];
    }

    private function score(object $job, array $profile): array
    {
        $career = $profile['career'];
        $credentials = $profile['credentials'];
        $experienceYears = (float) $profile['experience_years'];

        $score = 0;
        $reasons = [];
        $gaps = [];

        $desiredRoles = $this->decodeList($career?->desired_roles ?? null);
        $specialties = $this->decodeList($career?->specialties ?? null);
        $countries = $this->decodeList($career?->target_countries ?? null);
        $workSettings = $this->decodeList($career?->work_settings ?? null);
        $employmentTypes = $this->decodeList($career?->employment_types ?? null);

        if ($this->matchesAny($job->title, $desiredRoles)) {
            $score += 25;
            $reasons[] = 'Desired role match';
        } elseif ($desiredRoles !== []) {
            $gaps[] = 'Role is outside your stated preferred roles';
        }

        if (! $job->specialty) {
            $score += 20;
            $reasons[] = 'No specialty restriction';
        } elseif ($this->matchesAny($job->specialty, $specialties)) {
            $score += 20;
            $reasons[] = 'Specialty match';
        } else {
            $gaps[] = 'Specialty preference does not directly match';
        }

        if ($this->matchesAny($job->country, $countries)) {
            $score += 15;
            $reasons[] = 'Target location match';
        } elseif ($countries !== []) {
            $gaps[] = 'Location is outside your stated targets';
        }

        if (! $job->work_setting) {
            $score += 10;
        } elseif (in_array($job->work_setting, $workSettings, true)) {
            $score += 10;
            $reasons[] = 'Preferred work setting';
        }

        if (! $job->employment_type) {
            $score += 10;
        } elseif (in_array($job->employment_type, $employmentTypes, true)) {
            $score += 10;
            $reasons[] = 'Preferred employment type';
        }

        if (! $job->required_license_type) {
            $score += 10;
        } elseif ($credentials->contains(
            fn ($credential) =>
                $credential->credential_type === $job->required_license_type
                && $credential->verification_status !== 'expired'
        )) {
            $score += 10;
            $reasons[] = 'Required license on profile';
        } else {
            $gaps[] = 'Required license is not currently recorded';
        }

        if ($experienceYears >= (float) $job->minimum_experience_years) {
            $score += 5;
            if ((float) $job->minimum_experience_years > 0) {
                $reasons[] = 'Experience requirement met';
            }
        } else {
            $gaps[] = 'Experience requirement may not yet be met';
        }

        if (! $job->overseas_opportunity) {
            $score += 5;
        } elseif ((bool) ($career?->open_to_overseas ?? false)) {
            $score += 5;
            $reasons[] = 'Open to overseas opportunities';
        } else {
            $gaps[] = 'Opportunity is overseas but overseas preference is off';
        }

        return [
            max(0, min(100, $score)),
            array_values(array_unique($reasons)),
            array_values(array_unique($gaps)),
        ];
    }

    private function decodeList(?string $value): array
    {
        if (! $value) return [];

        $decoded = json_decode($value, true);

        return is_array($decoded)
            ? array_values(array_map(fn ($v) => (string) $v, $decoded))
            : [];
    }

    private function matchesAny(?string $needle, array $values): bool
    {
        $needle = $this->normal($needle);

        if ($needle === '') return false;

        foreach ($values as $value) {
            $candidate = $this->normal((string) $value);

            if ($candidate !== '' && (
                str_contains($needle, $candidate)
                || str_contains($candidate, $needle)
            )) {
                return true;
            }
        }

        return false;
    }

    private function normal(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function experienceYears(Collection $employment): float
    {
        $months = 0;

        foreach ($employment as $row) {
            if (! $row->start_date) continue;

            try {
                $start = Carbon::parse($row->start_date)->startOfDay();
                $end = $row->is_current || ! $row->end_date
                    ? now()->startOfDay()
                    : Carbon::parse($row->end_date)->startOfDay();

                if ($end->greaterThanOrEqualTo($start)) {
                    $months += $start->diffInMonths($end);
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return round($months / 12, 1);
    }
}
