<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CareerIntelligenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();
        $assessment = $this->assessment($userId);

        return response()->json([
            'data' => [
                ...$assessment,
                'history' => $this->history($userId),
            ],
            'advisory' => [
                'internal_indicator_only' => true,
                'official_licensing_decision' => false,
                'employer_hiring_decision' => false,
                'immigration_or_visa_decision' => false,
                'official_cpd_record' => false,
                'message' => 'NurseLink Career Intelligence is an advisory planning tool based on information recorded in NurseLink. It does not replace licensing authorities, employers, immigration authorities, educational institutions or official CPD records.',
            ],
        ]);
    }

    public function snapshot(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();
        $assessment = $this->assessment($userId);

        $scores = $assessment['scores'];

        $id = DB::table('nurselink_career_intelligence_snapshots')->insertGetId([
            'user_id' => $userId,
            'overall_score' => $scores['overall'],
            'career_profile_score' => $scores['career_profile'],
            'credential_score' => $scores['credentials'],
            'experience_score' => $scores['experience'],
            'learning_score' => $scores['learning'],
            'mobility_score' => $scores['mobility'],
            'market_alignment_score' => $scores['market_alignment'],
            'readiness_label' => $assessment['readiness_label'],
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $id,
                'generated_at' => now()->toIso8601String(),
                'scores' => $scores,
                'readiness_label' => $assessment['readiness_label'],
            ],
        ], 201);
    }

    private function assessment($userId): array
    {
        $career = DB::table('nurselink_career_preferences')
            ->where('user_id', $userId)
            ->first();

        $credentials = DB::table('nurselink_credentials_registry')
            ->where('user_id', $userId)
            ->orderBy('expiry_date')
            ->get();

        $employment = DB::table('nurselink_employment_histories')
            ->where('user_id', $userId)
            ->orderByDesc('is_current')
            ->orderByDesc('start_date')
            ->get();

        $learning = DB::table('nurselink_learning_records')
            ->where('user_id', $userId)
            ->orderByDesc('completed_at')
            ->get();

        $experienceYears = $this->experienceYears($employment);
        $careerData = $this->careerData($career);
        $credentialIntel = $this->credentialIntelligence($credentials);
        $learningIntel = $this->learningIntelligence(
            $learning,
            $careerData['specialties'],
            $employment
        );
        $jobMatches = $this->topJobMatches(
            $career,
            $credentials,
            $employment,
            $experienceYears
        );

        $careerScore = $this->careerProfileScore($careerData);
        $credentialScore = $this->credentialScore($credentialIntel);
        $experienceScore = $this->experienceScore($experienceYears);
        $learningScore = $this->learningScore($learningIntel);
        $mobility = $this->mobilityIntelligence(
            $career,
            $careerData,
            $credentials,
            $employment
        );
        $marketScore = $this->marketAlignmentScore($jobMatches);

        $overall = max(0, min(
            100,
            $careerScore
            + $credentialScore
            + $experienceScore
            + $learningScore
            + $mobility['score']
            + $marketScore
        ));

        $label = match (true) {
            $overall >= 85 => 'Career Ready',
            $overall >= 70 => 'Strong Foundation',
            $overall >= 50 => 'Developing',
            default => 'Build Your Foundation',
        };

        $priorityActions = $this->priorityActions(
            $careerData,
            $credentialIntel,
            $experienceYears,
            $learningIntel,
            $mobility,
            $jobMatches
        );

        return [
            'generated_at' => now()->toIso8601String(),
            'readiness_label' => $label,
            'scores' => [
                'overall' => $overall,
                'career_profile' => $careerScore,
                'credentials' => $credentialScore,
                'experience' => $experienceScore,
                'learning' => $learningScore,
                'mobility' => $mobility['score'],
                'market_alignment' => $marketScore,
            ],
            'score_limits' => [
                'overall' => 100,
                'career_profile' => 20,
                'credentials' => 20,
                'experience' => 20,
                'learning' => 15,
                'mobility' => 15,
                'market_alignment' => 10,
            ],
            'career_profile' => [
                ...$careerData,
                'experience_years' => $experienceYears,
            ],
            'credentials' => $credentialIntel,
            'learning' => $learningIntel,
            'mobility' => $mobility,
            'job_fit' => [
                'active_matches' => count($jobMatches),
                'top_matches' => $jobMatches,
            ],
            'priority_actions' => $priorityActions,
        ];
    }

    private function careerData(?object $career): array
    {
        return [
            'career_stage' => $career?->career_stage,
            'desired_roles' => $this->decodeList($career?->desired_roles),
            'specialties' => $this->decodeList($career?->specialties),
            'target_countries' => $this->decodeList($career?->target_countries),
            'work_settings' => $this->decodeList($career?->work_settings),
            'employment_types' => $this->decodeList($career?->employment_types),
            'open_to_overseas' => (bool)($career?->open_to_overseas ?? false),
            'open_to_relocation' => (bool)($career?->open_to_relocation ?? false),
            'open_to_telehealth' => (bool)($career?->open_to_telehealth ?? false),
            'available_from' => $career?->available_from,
            'career_goal' => $career?->career_goal,
        ];
    }

    private function credentialIntelligence(Collection $credentials): array
    {
        $today = now()->startOfDay();
        $verified = 0;
        $expired = 0;
        $current = 0;
        $alerts = [];

        foreach ($credentials as $credential) {
            if ($credential->verification_status === 'verified') {
                $verified++;
            }

            $expiryDate = null;
            $days = null;
            $derived = 'no_expiry_recorded';

            if ($credential->expiry_date) {
                try {
                    $expiryDate = Carbon::parse($credential->expiry_date)->startOfDay();
                    $days = (int)$today->diffInDays($expiryDate, false);

                    if ($days < 0) {
                        $derived = 'expired';
                        $expired++;
                    } elseif ($days <= 90) {
                        $derived = 'expiring_soon';
                        $current++;
                    } elseif ($days <= 180) {
                        $derived = 'renewal_window';
                        $current++;
                    } else {
                        $derived = 'current';
                        $current++;
                    }
                } catch (\Throwable) {
                    $derived = 'date_review_needed';
                }
            } else {
                $current++;
            }

            if (in_array($derived, ['expired', 'expiring_soon', 'renewal_window'], true)) {
                $alerts[] = [
                    'id' => (int)$credential->id,
                    'credential_type' => $credential->credential_type,
                    'title' => $credential->title,
                    'issuing_body' => $credential->issuing_body,
                    'expiry_date' => $credential->expiry_date,
                    'days_to_expiry' => $days,
                    'status' => $derived,
                    'verification_status' => $credential->verification_status,
                ];
            }
        }

        usort($alerts, static function (array $a, array $b): int {
            return ($a['days_to_expiry'] ?? PHP_INT_MAX)
                <=> ($b['days_to_expiry'] ?? PHP_INT_MAX);
        });

        return [
            'total' => $credentials->count(),
            'verified' => $verified,
            'current_or_no_expiry' => $current,
            'expired_by_date' => $expired,
            'expiry_alerts' => array_slice($alerts, 0, 12),
            'forecast_horizon_days' => 180,
            'note' => 'Expiry forecasting uses dates recorded in NurseLink. Confirm renewal requirements directly with the issuing or licensing authority.',
        ];
    }

    private function learningIntelligence(
        Collection $learning,
        array $specialties,
        Collection $employment
    ): array {
        $completed = $learning->filter(
            fn ($row) => $row->status === 'completed'
        );

        $hours = round((float)$completed->sum(
            fn ($row) => (float)($row->learning_hours ?? 0)
        ), 1);

        $units = round((float)$completed->sum(
            fn ($row) => (float)($row->cpd_units ?? 0)
        ), 1);

        $topics = [];

        foreach ($completed as $row) {
            foreach ([$row->topic, $row->title] as $value) {
                $normal = $this->normal((string)$value);
                if ($normal !== '') $topics[] = $normal;
            }
        }

        $specialtySignals = $specialties;

        foreach ($employment as $row) {
            if ($row->specialty) {
                $specialtySignals[] = (string)$row->specialty;
            }
        }

        $recommended = $this->recommendedLearningTopics($specialtySignals);
        $missing = [];

        foreach ($recommended as $recommendation) {
            $matched = false;

            foreach ($topics as $topic) {
                if ($this->textOverlap($topic, $recommendation['keywords'])) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $missing[] = $recommendation;
            }
        }

        return [
            'records' => $learning->count(),
            'completed' => $completed->count(),
            'completed_hours' => $hours,
            'self_reported_cpd_units' => $units,
            'recommended_focus' => array_slice($missing, 0, 6),
            'note' => 'Learning suggestions are developmental recommendations only. They are not official CPD, employer, specialty-board or licensing requirements.',
        ];
    }

    private function mobilityIntelligence(
        ?object $career,
        array $careerData,
        Collection $credentials,
        Collection $employment
    ): array {
        $seekingOverseas = (bool)($career?->open_to_overseas ?? false);

        if (!$seekingOverseas) {
            return [
                'score' => 15,
                'mode' => 'current_goal_aligned',
                'target_countries' => $careerData['target_countries'],
                'checks' => [],
                'actions' => [],
                'note' => 'Overseas mobility is not currently selected as a career preference, so overseas-readiness factors do not reduce your NurseLink career score.',
            ];
        }

        $verifiedCurrent = $credentials->contains(function ($row): bool {
            if ($row->verification_status !== 'verified') return false;
            if (!$row->expiry_date) return true;

            try {
                return Carbon::parse($row->expiry_date)->endOfDay()->isFuture();
            } catch (\Throwable) {
                return false;
            }
        });

        $overseasHistory = $employment->contains(
            fn ($row) => (bool)$row->is_overseas
        );

        $checks = [
            [
                'key' => 'target_destination',
                'label' => 'Target destination recorded',
                'ready' => $careerData['target_countries'] !== [],
                'points' => 4,
            ],
            [
                'key' => 'verified_current_credential',
                'label' => 'Current verified credential recorded',
                'ready' => $verifiedCurrent,
                'points' => 4,
            ],
            [
                'key' => 'overseas_history',
                'label' => 'Overseas employment history recorded',
                'ready' => $overseasHistory,
                'points' => 3,
            ],
            [
                'key' => 'availability',
                'label' => 'Career availability date recorded',
                'ready' => !empty($careerData['available_from']),
                'points' => 2,
            ],
            [
                'key' => 'relocation_preference',
                'label' => 'Relocation preference enabled',
                'ready' => (bool)($career?->open_to_relocation ?? false),
                'points' => 2,
            ],
        ];

        $score = array_sum(array_map(
            fn (array $check) => $check['ready'] ? $check['points'] : 0,
            $checks
        ));

        $actions = [
            'Confirm destination-specific nursing registration and recognition requirements directly with the official regulator.',
            'Confirm employer eligibility, recruitment pathway and contract requirements before accepting an opportunity.',
            'Confirm immigration, visa and deployment requirements with the responsible official authorities.',
        ];

        return [
            'score' => max(0, min(15, $score)),
            'mode' => 'overseas_goal',
            'target_countries' => $careerData['target_countries'],
            'checks' => $checks,
            'actions' => $actions,
            'note' => 'This is a NurseLink planning indicator only. It is not visa, immigration, deployment or foreign licensing clearance.',
        ];
    }

    private function topJobMatches(
        ?object $career,
        Collection $credentials,
        Collection $employment,
        float $experienceYears
    ): array {
        if (!Schema::hasTable('nurselink_job_opportunities')) {
            return [];
        }

        $jobs = DB::table('nurselink_job_opportunities')
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $matches = [];

        foreach ($jobs as $job) {
            [$score, $reasons, $gaps] = $this->jobScore(
                $job,
                $career,
                $credentials,
                $experienceYears
            );

            $matches[] = [
                'id' => (int)$job->id,
                'title' => $job->title,
                'employer_name' => $job->employer_name,
                'country' => $job->country,
                'city' => $job->city,
                'specialty' => $job->specialty,
                'work_setting' => $job->work_setting,
                'overseas_opportunity' => (bool)$job->overseas_opportunity,
                'match_score' => $score,
                'match_reasons' => $reasons,
                'match_gaps' => $gaps,
            ];
        }

        usort(
            $matches,
            static fn (array $a, array $b): int =>
                $b['match_score'] <=> $a['match_score']
        );

        return array_slice($matches, 0, 5);
    }

    private function jobScore(
        object $job,
        ?object $career,
        Collection $credentials,
        float $experienceYears
    ): array {
        $score = 0;
        $reasons = [];
        $gaps = [];

        $roles = $this->decodeList($career?->desired_roles);
        $specialties = $this->decodeList($career?->specialties);
        $countries = $this->decodeList($career?->target_countries);
        $settings = $this->decodeList($career?->work_settings);
        $types = $this->decodeList($career?->employment_types);

        if ($this->matchesAny($job->title, $roles)) {
            $score += 25;
            $reasons[] = 'Desired role match';
        } elseif ($roles !== []) {
            $gaps[] = 'Role is outside your stated preferred roles';
        }

        if (!$job->specialty) {
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

        if (!$job->work_setting) {
            $score += 10;
        } elseif (in_array($job->work_setting, $settings, true)) {
            $score += 10;
            $reasons[] = 'Preferred work setting';
        }

        if (!$job->employment_type) {
            $score += 10;
        } elseif (in_array($job->employment_type, $types, true)) {
            $score += 10;
            $reasons[] = 'Preferred employment type';
        }

        if (!$job->required_license_type) {
            $score += 10;
        } elseif ($credentials->contains(function ($credential) use ($job): bool {
            if ($credential->credential_type !== $job->required_license_type) {
                return false;
            }

            if ($credential->verification_status === 'expired') {
                return false;
            }

            if (!$credential->expiry_date) {
                return true;
            }

            try {
                return Carbon::parse($credential->expiry_date)->endOfDay()->isFuture();
            } catch (\Throwable) {
                return false;
            }
        })) {
            $score += 10;
            $reasons[] = 'Required license recorded';
        } else {
            $gaps[] = 'Required license is not currently recorded';
        }

        if ($experienceYears >= (float)$job->minimum_experience_years) {
            $score += 5;
            if ((float)$job->minimum_experience_years > 0) {
                $reasons[] = 'Experience requirement met';
            }
        } else {
            $gaps[] = 'Experience requirement may not yet be met';
        }

        if (!$job->overseas_opportunity) {
            $score += 5;
        } elseif ((bool)($career?->open_to_overseas ?? false)) {
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

    private function careerProfileScore(array $career): int
    {
        $score = 0;
        if ($career['desired_roles'] !== []) $score += 4;
        if ($career['specialties'] !== []) $score += 4;
        if ($career['target_countries'] !== [] || !$career['open_to_overseas']) $score += 3;
        if ($career['work_settings'] !== []) $score += 3;
        if ($career['employment_types'] !== []) $score += 2;
        if (!empty($career['career_stage'])) $score += 2;
        if (trim((string)($career['career_goal'] ?? '')) !== '') $score += 2;
        return min(20, $score);
    }

    private function credentialScore(array $intel): int
    {
        if (($intel['total'] ?? 0) === 0) return 0;

        $score = 5;
        if (($intel['verified'] ?? 0) > 0) $score += 7;
        if (($intel['expired_by_date'] ?? 0) === 0) $score += 4;

        $urgent = array_filter(
            $intel['expiry_alerts'] ?? [],
            fn (array $row) => in_array($row['status'], ['expired', 'expiring_soon'], true)
        );

        if ($urgent === []) $score += 4;

        return min(20, $score);
    }

    private function experienceScore(float $years): int
    {
        return match (true) {
            $years >= 5 => 20,
            $years >= 3 => 15,
            $years >= 1 => 10,
            $years > 0 => 5,
            default => 0,
        };
    }

    private function learningScore(array $intel): int
    {
        $score = 0;
        if (($intel['completed'] ?? 0) > 0) $score += 5;
        if (($intel['completed_hours'] ?? 0) >= 20) $score += 5;
        if (($intel['completed'] ?? 0) >= 3) $score += 3;
        if (($intel['recommended_focus'] ?? []) === []) $score += 2;
        return min(15, $score);
    }

    private function marketAlignmentScore(array $matches): int
    {
        if ($matches === []) return 5;

        $top = (int)($matches[0]['match_score'] ?? 0);

        return match (true) {
            $top >= 80 => 10,
            $top >= 60 => 8,
            $top >= 40 => 6,
            default => 4,
        };
    }

    private function priorityActions(
        array $career,
        array $credentials,
        float $experienceYears,
        array $learning,
        array $mobility,
        array $matches
    ): array {
        $actions = [];

        if ($career['desired_roles'] === [] || $career['specialties'] === []) {
            $actions[] = [
                'priority' => 'high',
                'category' => 'career_profile',
                'title' => 'Complete your career matching profile',
                'detail' => 'Add desired roles and specialties so NurseLink can produce more useful job-fit and development guidance.',
                'action_url' => '/jobs',
            ];
        }

        foreach (array_slice($credentials['expiry_alerts'] ?? [], 0, 2) as $alert) {
            $actions[] = [
                'priority' => in_array($alert['status'], ['expired', 'expiring_soon'], true) ? 'high' : 'medium',
                'category' => 'credential',
                'title' => $alert['status'] === 'expired'
                    ? 'Review an expired credential'
                    : 'Plan credential renewal',
                'detail' => trim(
                    ($alert['title'] ?: $alert['credential_type'])
                    . ($alert['expiry_date'] ? ' · expiry ' . $alert['expiry_date'] : '')
                ),
                'action_url' => '/credentials',
            ];
        }

        if ($experienceYears < 1) {
            $actions[] = [
                'priority' => 'medium',
                'category' => 'experience',
                'title' => 'Strengthen recorded clinical experience',
                'detail' => 'Keep your employment history current so opportunity matching can evaluate experience requirements accurately.',
                'action_url' => '/profile?nlstep=4',
            ];
        }

        foreach (array_slice($learning['recommended_focus'] ?? [], 0, 2) as $focus) {
            $actions[] = [
                'priority' => 'medium',
                'category' => 'learning',
                'title' => 'Consider a learning focus: ' . $focus['title'],
                'detail' => $focus['reason'],
                'action_url' => '/learning',
            ];
        }

        if (($mobility['mode'] ?? '') === 'overseas_goal') {
            foreach ($mobility['checks'] ?? [] as $check) {
                if (!$check['ready']) {
                    $actions[] = [
                        'priority' => 'medium',
                        'category' => 'mobility',
                        'title' => $check['label'],
                        'detail' => 'This item can strengthen your NurseLink overseas career-planning profile. Official destination requirements must still be confirmed separately.',
                        'action_url' => '/jobs',
                    ];
                    break;
                }
            }
        }

        if ($matches !== [] && ($matches[0]['match_score'] ?? 0) < 60) {
            $actions[] = [
                'priority' => 'low',
                'category' => 'job_fit',
                'title' => 'Review job-match gaps',
                'detail' => 'Your strongest current opportunity match is below 60%. Review the explainable gaps before applying.',
                'action_url' => '/jobs',
            ];
        }

        return array_slice($actions, 0, 7);
    }

    private function recommendedLearningTopics(array $specialties): array
    {
        $topics = [
            [
                'title' => 'Patient Safety & Clinical Risk',
                'keywords' => ['patient safety', 'clinical risk', 'quality', 'safety'],
                'reason' => 'A broad development area relevant across nursing settings.',
            ],
            [
                'title' => 'Infection Prevention & Control',
                'keywords' => ['infection', 'infection control', 'ipc', 'prevention'],
                'reason' => 'A cross-setting clinical development topic.',
            ],
            [
                'title' => 'Clinical Communication & Handover',
                'keywords' => ['communication', 'handover', 'documentation', 'teamwork'],
                'reason' => 'Supports safe continuity of care and multidisciplinary work.',
            ],
        ];

        $joined = $this->normal(implode(' ', $specialties));

        $maps = [
            [
                'needles' => ['critical', 'intensive', 'icu'],
                'title' => 'Critical Care Assessment',
                'keywords' => ['critical care', 'intensive care', 'icu', 'critical assessment'],
                'reason' => 'Aligned with your recorded critical/intensive-care interest or experience.',
            ],
            [
                'needles' => ['emergency', 'er', 'trauma'],
                'title' => 'Emergency Assessment & Triage',
                'keywords' => ['emergency', 'triage', 'trauma'],
                'reason' => 'Aligned with your recorded emergency-care interest or experience.',
            ],
            [
                'needles' => ['pediatric', 'paediatric', 'child'],
                'title' => 'Pediatric Clinical Care',
                'keywords' => ['pediatric', 'paediatric', 'child health'],
                'reason' => 'Aligned with your recorded pediatric-care interest or experience.',
            ],
            [
                'needles' => ['oncology', 'cancer'],
                'title' => 'Oncology Supportive Care',
                'keywords' => ['oncology', 'cancer', 'chemotherapy'],
                'reason' => 'Aligned with your recorded oncology interest or experience.',
            ],
            [
                'needles' => ['mental', 'psychiatric', 'psych'],
                'title' => 'Mental Health Nursing',
                'keywords' => ['mental health', 'psychiatric', 'psych'],
                'reason' => 'Aligned with your recorded mental-health interest or experience.',
            ],
            [
                'needles' => ['geriatric', 'elder', 'aged'],
                'title' => 'Older Adult Care',
                'keywords' => ['geriatric', 'older adult', 'elder care'],
                'reason' => 'Aligned with your recorded older-adult-care interest or experience.',
            ],
            [
                'needles' => ['perioperative', 'operating', 'or nurse', 'theatre'],
                'title' => 'Perioperative Safety',
                'keywords' => ['perioperative', 'operating room', 'theatre', 'surgical'],
                'reason' => 'Aligned with your recorded perioperative interest or experience.',
            ],
            [
                'needles' => ['dialysis', 'renal', 'nephro'],
                'title' => 'Renal & Dialysis Nursing',
                'keywords' => ['renal', 'dialysis', 'nephrology'],
                'reason' => 'Aligned with your recorded renal/dialysis interest or experience.',
            ],
            [
                'needles' => ['community', 'public health'],
                'title' => 'Community & Population Health',
                'keywords' => ['community', 'public health', 'population health'],
                'reason' => 'Aligned with your recorded community/public-health interest or experience.',
            ],
        ];

        foreach ($maps as $map) {
            foreach ($map['needles'] as $needle) {
                if (str_contains($joined, $needle)) {
                    $topics[] = [
                        'title' => $map['title'],
                        'keywords' => $map['keywords'],
                        'reason' => $map['reason'],
                    ];
                    break;
                }
            }
        }

        return $topics;
    }

    private function history($userId): array
    {
        if (!Schema::hasTable('nurselink_career_intelligence_snapshots')) {
            return [];
        }

        return DB::table('nurselink_career_intelligence_snapshots')
            ->where('user_id', $userId)
            ->select([
                'id',
                'overall_score',
                'career_profile_score',
                'credential_score',
                'experience_score',
                'learning_score',
                'mobility_score',
                'market_alignment_score',
                'readiness_label',
                'generated_at',
            ])
            ->orderByDesc('generated_at')
            ->limit(24)
            ->get()
            ->all();
    }

    private function decodeList(?string $value): array
    {
        if (!$value) return [];

        $decoded = json_decode($value, true);

        if (!is_array($decoded)) return [];

        return array_values(array_filter(array_map(
            fn ($item) => trim((string)$item),
            $decoded
        )));
    }

    private function matchesAny(?string $needle, array $values): bool
    {
        $needle = $this->normal($needle);
        if ($needle === '') return false;

        foreach ($values as $value) {
            $candidate = $this->normal((string)$value);

            if ($candidate !== '' && (
                str_contains($needle, $candidate)
                || str_contains($candidate, $needle)
            )) {
                return true;
            }
        }

        return false;
    }

    private function textOverlap(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            $keyword = $this->normal((string)$keyword);
            if ($keyword !== '' && str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function normal(?string $value): string
    {
        return strtolower(trim((string)$value));
    }

    private function experienceYears(Collection $employment): float
    {
        $months = 0;

        foreach ($employment as $row) {
            if (!$row->start_date) continue;

            try {
                $start = Carbon::parse($row->start_date)->startOfDay();
                $end = $row->is_current || !$row->end_date
                    ? now()->startOfDay()
                    : Carbon::parse($row->end_date)->startOfDay();

                if ($end->greaterThanOrEqualTo($start)) {
                    $months += $start->diffInMonths($end);
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return round($months / 12, 1);
    }
}
