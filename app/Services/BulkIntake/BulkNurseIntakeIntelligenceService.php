<?php

namespace App\Services\BulkIntake;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BulkNurseIntakeIntelligenceService
{
    private const PROFILE_FIELDS = [
        'email',
        'phone',
        'first_name',
        'second_name',
        'middle_initial',
        'middle_name',
        'last_name',
        'birth_date',
        'sex',
        'nationality',
        'address_line1',
        'city',
        'province',
        'country',
        'professional_title',
        'years_experience',
        'current_position',
        'current_employer',
        'specialty',
        'primary_license_number',
        'primary_license_country',
        'primary_license_expiry',
        'highest_nursing_education',
        'graduation_year',
    ];

    public function build(int $batchId): array
    {
        $batch = DB::table('nurselink_bulk_intake_batches')
            ->where('id', $batchId)
            ->first();

        abort_unless($batch, 404);

        $files = DB::table('nurselink_bulk_intake_files')
            ->where('batch_id', $batchId)
            ->whereIn('extraction_status', [
                'extracted',
                'needs_input',
            ])
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($batchId, $files): void {
            DB::table('nurselink_bulk_intake_files')
                ->where('batch_id', $batchId)
                ->update([
                    'candidate_id' => null,
                    'grouping_key' => null,
                    'updated_at' => now(),
                ]);

            DB::table('nurselink_bulk_intake_candidates')
                ->where('batch_id', $batchId)
                ->delete();

            /*
             * A normal intake batch belongs to one candidate.  A roster
             * spreadsheet is the intentional exception: each populated
             * data row represents a separate candidate for review.
             */
            $items = [];

            foreach ($files as $file) {
                $fields = app(BulkReviewFieldMapper::class)->enrich($file, $this->decodeFields($file->extracted_fields));
                $file->extracted_fields = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                DB::table('nurselink_bulk_intake_files')->where('id', $file->id)->where('batch_id', $batchId)
                    ->update(['extracted_fields' => $file->extracted_fields]);
                $items[] = [
                    'file' => $file,
                    'fields' => $this->decodeFields(
                        $file->extracted_fields
                    ),
                ];
            }

            $rosterCandidates = $this->rosterCandidates($files);

            if ($rosterCandidates !== []) {
                foreach ($rosterCandidates as $index => $candidateItems) {
                    $this->createCandidate(
                        $batchId,
                        'roster:'.$batchId.':'.($index + 1),
                        $candidateItems,
                        false
                    );
                }
            } elseif ($items !== []) {
                $this->createCandidate(
                    $batchId,
                    'batch:'.$batchId,
                    $items
                );
            }

            $this->refreshBatch($batchId);
        });

        return $this->summary($batchId);
    }

    public function summary(int $batchId): array
    {
        $batch = DB::table('nurselink_bulk_intake_batches')
            ->where('id', $batchId)
            ->first();

        $candidates = DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where('batch_id', $batchId)
            ->orderByDesc('confidence')
            ->orderBy('id')
            ->get();

        $credentials = DB::table(
            'nurselink_bulk_intake_credentials'
        )
            ->whereIn(
                'candidate_id',
                $candidates->pluck('id')->all()
            )
            ->orderBy('id')
            ->get()
            ->groupBy('candidate_id');

        $files = DB::table(
            'nurselink_bulk_intake_files'
        )
            ->where('batch_id', $batchId)
            ->orderBy('id')
            ->get()
            ->groupBy('candidate_id');

        $candidateIds =
            $candidates
                ->pluck('id')
                ->all();

        $education = DB::table(
            'nurselink_bulk_intake_education'
        )
            ->whereIn(
                'candidate_id',
                $candidateIds
            )
            ->orderBy('id')
            ->get()
            ->groupBy('candidate_id');

        $employment = DB::table(
            'nurselink_bulk_intake_employment'
        )
            ->whereIn(
                'candidate_id',
                $candidateIds
            )
            ->orderBy('started_on')
            ->orderBy('id')
            ->orderBy('id')
            ->get()
            ->groupBy('candidate_id');

        $competencies = DB::table(
            'nurselink_bulk_intake_competencies'
        )
            ->whereIn(
                'candidate_id',
                $candidateIds
            )
            ->orderBy('domain')
            ->orderBy('name')
            ->get()
            ->groupBy('candidate_id');

        $languages = DB::table(
            'nurselink_bulk_intake_languages'
        )
            ->whereIn(
                'candidate_id',
                $candidateIds
            )
            ->orderBy('language')
            ->get()
            ->groupBy('candidate_id');

        $references = DB::table(
            'nurselink_bulk_intake_references'
        )
            ->whereIn(
                'candidate_id',
                $candidateIds
            )
            ->orderBy('id')
            ->get()
            ->groupBy('candidate_id');

        $rows = $candidates->map(
            function ($candidate) use (
                $credentials,
                $files,
                $education,
                $employment,
                $competencies,
                $languages,
                $references
            ): array {
                return [
                    'candidate' => $candidate,
                    'credentials' =>
                        ($credentials[$candidate->id] ?? collect())
                            ->values()
                            ->all(),
                    'files' =>
                        ($files[$candidate->id] ?? collect())
                            ->values()
                            ->all(),

                    'education' =>
                        ($education[$candidate->id] ?? collect())
                            ->values()
                            ->all(),

                    'employment' =>
                        ($employment[$candidate->id] ?? collect())
                            ->values()
                            ->all(),

                    'competencies' =>
                        ($competencies[$candidate->id] ?? collect())
                            ->values()
                            ->all(),

                    'languages' =>
                        ($languages[$candidate->id] ?? collect())
                            ->values()
                            ->all(),

                    /*
                     * These remain available only inside the
                     * authenticated Bulk Intake review workflow.
                     */
                    'references' =>
                        ($references[$candidate->id] ?? collect())
                            ->values()
                            ->all(),
                ];
            }
        )->values()->all();

        return [
            'batch' => $batch,
            'candidates' => $rows,
        ];
    }

    private function createCandidate(
        int $batchId,
        string $groupingKey,
        array $items,
        bool $linkSourceFiles = true
    ): void {
        $merged = [];
        $confidence = [];
        $sourceFiles = [];
        $rosterDetails = [];

        // Keep name components together; never splice a passport surname into a CV identity.
        $identitySourceId = null;
        $identityRank = -1;
        $nameKeys = ['first_name', 'second_name', 'middle_initial', 'middle_name', 'last_name'];
        foreach ($items as $identityItem) {
            $identityFields = $this->promoteFullNameParts($identityItem['fields'], $identityItem['file']);
            $first = $identityFields['first_name']['value'] ?? '';
            $last = $identityFields['last_name']['value'] ?? '';
            if ($first === '' || $last === '') continue;
            $rank = min($this->profileFieldScore('first_name', $first, (float)($identityFields['first_name']['confidence'] ?? .5), $identityItem['file']),
                $this->profileFieldScore('last_name', $last, (float)($identityFields['last_name']['confidence'] ?? .5), $identityItem['file']));
            if ($rank <= 0) continue;
            if (($identityItem['file']->document_type ?? '') === 'cv') $rank += 1;
            if ($rank > $identityRank) { $identityRank = $rank; $identitySourceId = (int)$identityItem['file']->id; }
        }

        foreach ($items as $item) {
            $file = $item['file'];
            $fields = $item['fields'];

            $sourceFiles[] = (int) $file->id;

            $details = $fields['__roster_details']['value'] ?? [];
            if (is_array($details)) {
                $rosterDetails = array_merge($rosterDetails, $details);
            }

            $fields = $this->promoteFullNameParts($fields, $file);

            foreach (self::PROFILE_FIELDS as $field) {
                if (in_array($field, $nameKeys, true) && (int)$file->id !== $identitySourceId) continue;
                $candidate = $fields[$field] ?? null;

                if (! is_array($candidate)) {
                    continue;
                }

                $value = $candidate['value'] ?? null;

                if (
                    $value === null
                    || trim((string) $value) === ''
                ) {
                    continue;
                }

                $score = (float) (
                    $candidate['confidence'] ?? 0.50
                );

                $score = $this->profileFieldScore(
                    $field,
                    $value,
                    $score,
                    $file
                );

                if ($score <= 0) {
                    continue;
                }

                if (
                    ! array_key_exists($field, $merged)
                    || $score > ($confidence[$field] ?? 0)
                ) {
                    $merged[$field] = $value;
                    $confidence[$field] = $score;
                }
            }
        }

        $displayName = trim(
            implode(
                ' ',
                array_filter(
                    [
                        $merged['first_name'] ?? null,
                        $merged['second_name'] ?? null,
                        $merged['middle_initial'] ?? null,
                        $merged['last_name'] ?? null,
                    ],
                    fn ($value) =>
                        $value !== null
                        && trim((string) $value) !== ''
                )
            )
        );

        /*
         * Backward compatibility:
         * older sources may only provide middle_name.
         */
        if (
            trim($displayName) === ''
            || (
                empty($merged['second_name'])
                && empty($merged['middle_initial'])
                && ! empty($merged['middle_name'])
            )
        ) {
            $displayName = trim(
                ($merged['first_name'] ?? '')
                . ' '
                . ($merged['middle_name'] ?? '')
                . ' '
                . ($merged['last_name'] ?? '')
            );
        }

        $displayName = preg_replace(
            '/\s+/u',
            ' ',
            $displayName
        ) ?: '';

        if ($displayName === '') {
            $displayName =
                $merged['email']
                ?? $merged['primary_license_number']
                ?? ('Candidate '.($sourceFiles[0] ?? ''));
        }

        $match = $this->findExistingMatches(
            $merged
        );

        $overall = $this->overallConfidence(
            $confidence
        );

        $high = (float) config(
            'smart_registration.confidence.high',
            0.90
        );

        $status =
            $overall >= $high
            && $match['duplicate_status'] !== 'conflict'
                ? 'ready'
                : 'review_required';

        $candidateId = DB::table(
            'nurselink_bulk_intake_candidates'
        )->insertGetId([
            'batch_id' => $batchId,
            'status' => $status,

            'matched_user_id' =>
                $match['matched_user_id'],

            'matched_membership_id' =>
                $match['matched_membership_id'],

            'confidence' =>
                $overall,

            'duplicate_status' =>
                $match['duplicate_status'],

            'duplicate_matches' =>
                $match['matches'] !== []
                    ? json_encode(
                        $match['matches'],
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    )
                    : null,

            'display_name' =>
                $displayName,

            'email' =>
                $merged['email'] ?? null,

            'phone' =>
                $merged['phone'] ?? null,

            'first_name' =>
                $merged['first_name'] ?? null,

            'second_name' =>
                $merged['second_name'] ?? null,

            'middle_initial' =>
                $merged['middle_initial'] ?? null,

            'middle_name' =>
                $merged['middle_name'] ?? null,

            'last_name' =>
                $merged['last_name'] ?? null,

            'birth_date' =>
                $this->dateOrNull(
                    $merged['birth_date'] ?? null
                ),

            'sex' =>
                $merged['sex'] ?? null,

            'nationality' =>
                $merged['nationality'] ?? null,

            'address_line1' =>
                $merged['address_line1'] ?? null,

            'city' =>
                $merged['city'] ?? null,

            'province' =>
                $merged['province'] ?? null,

            'country' =>
                $merged['country'] ?? null,

            'professional_title' =>
                $merged['professional_title'] ?? null,

            'years_experience' =>
                $this->integerOrNull(
                    $merged['years_experience'] ?? null
                ),

            'current_position' =>
                $merged['current_position'] ?? null,

            'current_employer' =>
                $merged['current_employer'] ?? null,

            'specialty' =>
                $merged['specialty'] ?? null,

            'primary_license_number' =>
                $merged['primary_license_number'] ?? null,

            'primary_license_country' =>
                $merged['primary_license_country'] ?? null,

            'primary_license_expiry' =>
                $this->dateOrNull(
                    $merged['primary_license_expiry'] ?? null
                ),

            'highest_nursing_education' =>
                $merged['highest_nursing_education'] ?? null,

            'graduation_year' =>
                $this->integerOrNull(
                    $merged['graduation_year'] ?? null
                ),

            'field_confidence' =>
                $confidence !== []
                    ? json_encode(
                        $confidence,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    )
                    : null,

            'roster_details' => $rosterDetails !== []
                ? json_encode($rosterDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,

            'source_file_ids' =>
                json_encode(
                    $sourceFiles,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                ),

            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($linkSourceFiles) {
            DB::table('nurselink_bulk_intake_files')
                ->whereIn('id', $sourceFiles)
                ->update([
                    'candidate_id' => $candidateId,
                    'grouping_key' => $groupingKey,
                    'updated_at' => now(),
                ]);
        }

        $this->createCredentialCandidates(
            $candidateId,
            $items,
            $merged,
            $confidence
        );

        /*
         * Preserve one-to-many CV information separately from
         * scalar candidate summary fields.
         */
        app(
            BulkCvStructuredParser::class
        )->stageCandidate(
            $candidateId
        );
    }

    private function createCredentialCandidates(
        int $candidateId,
        array $items,
        array $merged,
        array $confidence
    ): void {
        $license = trim((string) (
            $merged['primary_license_number'] ?? ''
        ));

        if ($license !== '') {
            $sourceFileId = null;
            $issuedOn = null;

            foreach ($items as $item) {
                if (
                    ($item['file']->document_type ?? '')
                    === 'prc_license'
                    && (string) ($item['fields']['primary_license_number']['value'] ?? '') === $license
                ) {
                    $sourceFileId =
                        (int) $item['file']->id;
                    $issuedOn = $this->dateOrNull($item['fields']['primary_license_issued_on']['value'] ?? null);
                    break;
                }
            }

            $score = (float) (
                $confidence['primary_license_number']
                ?? 0.75
            );

            DB::table(
                'nurselink_bulk_intake_credentials'
            )->insert([
                'candidate_id' =>
                    $candidateId,

                'source_file_id' =>
                    $sourceFileId,

                'category' =>
                    'professional_license',

                'credential_type' =>
                    'prc_license',

                'title' =>
                    'PRC Registered Nurse License',

                'credential_number' =>
                    $license,

                'issuing_authority' =>
                    'Professional Regulation Commission',

                'country' =>
                    $merged['primary_license_country']
                    ?? 'Philippines',

                'expires_on' =>
                    $this->dateOrNull(
                        $merged['primary_license_expiry']
                        ?? null
                    ),

                'issued_on' => $issuedOn,

                'confidence' =>
                    $score,

                'verification_status' =>
                    'unverified',

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);
        }

        foreach ($items as $item) {
            $file = $item['file'];

            $type = (string) (
                $file->document_type ?? ''
            );

            if (! in_array(
                $type,
                [
                    'international_license',
                    'training_certificate',
                    'nursing_diploma',
                    'employment_certificate',
                ],
                true
            )) {
                continue;
            }

            /*
             * Preserve pertinent documents as candidates without
             * inventing credential numbers or dates that OCR did
             * not actually extract.
             */
            DB::table(
                'nurselink_bulk_intake_credentials'
            )->insert([
                'candidate_id' =>
                    $candidateId,

                'source_file_id' =>
                    (int) $file->id,

                'category' =>
                    match ($type) {
                        'international_license' =>
                            'professional_license',

                        'training_certificate' =>
                            'training',

                        'nursing_diploma' =>
                            'education',

                        'employment_certificate' =>
                            'employment',

                        default =>
                            'other',
                    },

                'credential_type' =>
                    $type,

                'title' =>
                    $this->documentTitle(
                        $type,
                        (string) $file->original_name
                    ),

                'confidence' =>
                    $this->fileConfidence(
                        $item['fields']
                    ),

                'verification_status' =>
                    'unverified',

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);
        }
    }

    private function findExistingMatches(
        array $fields
    ): array {
        $matches = [];

        $license = $this->normalizeToken(
            $fields['primary_license_number'] ?? null
        );

        $email = mb_strtolower(
            trim((string) (
                $fields['email'] ?? ''
            ))
        );

        $phone = $this->normalizePhone(
            $fields['phone'] ?? null
        );

        $first = $this->normalizeName(
            $fields['first_name'] ?? null
        );

        $last = $this->normalizeName(
            $fields['last_name'] ?? null
        );

        $birthDate = $this->dateOrNull(
            $fields['birth_date'] ?? null
        );

        if (
            $email !== ''
            && Schema::hasTable('users')
        ) {
            DB::table('users')
                ->whereRaw(
                    'LOWER(email) = ?',
                    [$email]
                )
                ->get(['id', 'email'])
                ->each(function ($row) use (&$matches): void {
                    $this->addMatch(
                        $matches,
                        (string) $row->id,
                        'email',
                        (string) $row->email
                    );
                });
        }

        if (
            Schema::hasTable(
                'nurselink_smart_registration_profiles'
            )
        ) {
            if ($license !== '') {
                DB::table(
                    'nurselink_smart_registration_profiles'
                )
                    ->whereNotNull(
                        'primary_license_number'
                    )
                    ->get([
                        'user_id',
                        'primary_license_number',
                    ])
                    ->each(
                        function ($row) use (
                            &$matches,
                            $license
                        ): void {
                            if (
                                $this->normalizeToken(
                                    $row->primary_license_number
                                ) === $license
                            ) {
                                $this->addMatch(
                                    $matches,
                                    (string) $row->user_id,
                                    'prc_license',
                                    (string) $row
                                        ->primary_license_number
                                );
                            }
                        }
                    );
            }

            if ($phone !== '') {
                DB::table(
                    'nurselink_smart_registration_profiles'
                )
                    ->whereNotNull('phone')
                    ->get(['user_id', 'phone'])
                    ->each(
                        function ($row) use (
                            &$matches,
                            $phone
                        ): void {
                            if (
                                $this->normalizePhone(
                                    $row->phone
                                ) === $phone
                            ) {
                                $this->addMatch(
                                    $matches,
                                    (string) $row->user_id,
                                    'phone',
                                    (string) $row->phone
                                );
                            }
                        }
                    );
            }

            if (
                $first !== ''
                && $last !== ''
                && $birthDate
            ) {
                DB::table(
                    'nurselink_smart_registration_profiles'
                )
                    ->whereDate(
                        'birth_date',
                        $birthDate
                    )
                    ->get([
                        'user_id',
                        'first_name',
                        'last_name',
                        'birth_date',
                    ])
                    ->each(
                        function ($row) use (
                            &$matches,
                            $first,
                            $last
                        ): void {
                            if (
                                $this->normalizeName(
                                    $row->first_name
                                ) === $first
                                && $this->normalizeName(
                                    $row->last_name
                                ) === $last
                            ) {
                                $this->addMatch(
                                    $matches,
                                    (string) $row->user_id,
                                    'name_birth_date',
                                    trim(
                                        $row->first_name
                                        .' '
                                        .$row->last_name
                                    )
                                );
                            }
                        }
                    );
            }
        }

        $userIds = array_values(
            array_unique(
                array_column(
                    $matches,
                    'user_id'
                )
            )
        );

        $duplicateStatus = match (count($userIds)) {
            0 => 'none',
            1 => 'existing_match',
            default => 'conflict',
        };

        $matchedUserId =
            count($userIds) === 1
                ? $userIds[0]
                : null;

        $membershipId = null;

        if (
            $matchedUserId
            && Schema::hasTable(
                'nurselink_memberships'
            )
        ) {
            $membershipId = DB::table(
                'nurselink_memberships'
            )
                ->where(
                    'user_id',
                    $matchedUserId
                )
                ->value('id');
        }

        return [
            'duplicate_status' =>
                $duplicateStatus,

            'matched_user_id' =>
                $matchedUserId,

            'matched_membership_id' =>
                $membershipId
                    ? (int) $membershipId
                    : null,

            'matches' =>
                $matches,
        ];
    }

    private function addMatch(
        array &$matches,
        string $userId,
        string $signal,
        string $value
    ): void {
        $key = $userId.'|'.$signal;

        foreach ($matches as $existing) {
            if (($existing['_key'] ?? '') === $key) {
                return;
            }
        }

        $matches[] = [
            '_key' => $key,
            'user_id' => $userId,
            'signal' => $signal,
            'value' => $value,
        ];

        $last = array_key_last($matches);

        if ($last !== null) {
            unset($matches[$last]['_key']);
        }
    }

    private function groupingKey(
        object $file,
        array $fields
    ): string {
        $license = $this->normalizeToken(
            $fields['primary_license_number']['value']
            ?? null
        );

        if ($license !== '') {
            return 'license:'.$license;
        }

        $email = mb_strtolower(
            trim((string) (
                $fields['email']['value']
                ?? ''
            ))
        );

        if ($email !== '') {
            return 'email:'.$email;
        }

        $phone = $this->normalizePhone(
            $fields['phone']['value']
            ?? null
        );

        if ($phone !== '') {
            return 'phone:'.$phone;
        }

        $first = $this->normalizeName(
            $fields['first_name']['value']
            ?? null
        );

        $last = $this->normalizeName(
            $fields['last_name']['value']
            ?? null
        );

        $birth = $this->dateOrNull(
            $fields['birth_date']['value']
            ?? null
        );

        if (
            $first !== ''
            && $last !== ''
        ) {
            return 'name:'
                .$first
                .'|'
                .$last
                .'|'
                .($birth ?: 'unknown');
        }

        /*
         * Never combine weakly identified documents merely because
         * they are in the same upload batch.
         */
        return 'file:'.(int) $file->id;
    }

    private function promoteFullNameParts(array $fields, object $file): array
    {
        if (isset($fields['first_name'], $fields['last_name'])) {
            return $fields;
        }

        $candidate = $fields['full_name'] ?? null;
        $full = is_array($candidate) ? trim((string) ($candidate['value'] ?? '')) : '';
        if ($full === '' || preg_match('/\d/u', $full)) {
            return $fields;
        }

        $parts = array_values(array_filter(preg_split('/\s+/u', $full) ?: []));
        if (count($parts) < 2 || count($parts) > 6) {
            return $fields;
        }

        $base = (float) ($candidate['confidence'] ?? 0.50);
        if ($this->profileFieldScore('first_name', $parts[0], $base, $file) <= 0) {
            return $fields;
        }

        $first = array_shift($parts);
        $last = array_pop($parts);
        $fields['first_name'] ??= ['value' => $first, 'confidence' => $base];
        $fields['last_name'] ??= ['value' => $last, 'confidence' => $base];

        if ($parts !== [] && ! isset($fields['middle_name'])) {
            $fields['middle_name'] = ['value' => implode(' ', $parts), 'confidence' => max(0.0, $base - 0.02)];
        }

        return $fields;
    }

    private function profileFieldScore(
        string $field,
        mixed $value,
        float $score,
        object $file
    ): float {
        $score = max(0.0, min(1.0, $score));

        if (! in_array($field, ['first_name', 'second_name', 'middle_initial', 'middle_name', 'last_name'], true)) {
            return $score;
        }

        $text = trim((string) $value);
        if ($text === '' || preg_match('/\d/u', $text)) {
            return 0.0;
        }

        $lower = mb_strtolower($text);
        $generic = [
            'institute', 'institution', 'hospital', 'clinic', 'university',
            'college', 'school', 'manpower', 'agency', 'company', 'name',
            'applicant', 'candidate', 'employee', 'employer', 'nurse',
            'registered', 'republic', 'philippines', 'board', 'rating',
        ];
        foreach ($generic as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/iu', $lower)) {
                return 0.0;
            }
        }

        $type = mb_strtolower((string) ($file->document_type ?? ''));
        $name = mb_strtolower((string) ($file->original_name ?? ''));

        $priority = match ($type) {
            'cv' => 0.20,
            'identity' => 0.18,
            'prc_license' => 0.08,
            'international_license' => 0.06,
            'nursing_diploma' => 0.04,
            default => 0.0,
        };

        if (preg_match('/\b(cv|resume|résumé|passport)\b/iu', $name)) {
            $priority = max($priority, 0.18);
        }

        return min(1.0, $score + $priority);
    }

    private function decodeFields(
        mixed $value
    ): array {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode(
            $value,
            true
        );

        return is_array($decoded)
            ? $decoded
            : [];
    }

    private function overallConfidence(
        array $confidence
    ): float {
        if ($confidence === []) {
            return 0.0;
        }

        $important = [
            'primary_license_number' => 3,
            'email' => 2,
            'phone' => 2,
            'first_name' => 2,
            'last_name' => 2,
            'birth_date' => 2,
        ];

        $weighted = 0.0;
        $weight = 0.0;

        foreach (
            $confidence
            as $field => $score
        ) {
            $w = $important[$field] ?? 1;

            $weighted +=
                max(
                    0.0,
                    min(1.0, (float) $score)
                )
                * $w;

            $weight += $w;
        }

        if ($weight <= 0) {
            return 0.0;
        }

        return round(
            $weighted / $weight,
            4
        );
    }

    private function fileConfidence(
        array $fields
    ): float {
        $scores = [];

        foreach ($fields as $field) {
            if (
                is_array($field)
                && isset($field['confidence'])
            ) {
                $scores[] =
                    (float) $field['confidence'];
            }
        }

        if ($scores === []) {
            return 0.50;
        }

        return round(
            array_sum($scores)
            / count($scores),
            4
        );
    }

    private function normalizeToken(
        mixed $value
    ): string {
        return mb_strtoupper(
            preg_replace(
                '/[^A-Z0-9]/iu',
                '',
                trim((string) $value)
            )
            ?? ''
        );
    }

    private function normalizePhone(
        mixed $value
    ): string {
        $digits = preg_replace(
            '/\D+/',
            '',
            (string) $value
        ) ?? '';

        if (
            str_starts_with($digits, '63')
            && strlen($digits) === 12
        ) {
            return '0'.substr($digits, 2);
        }

        return $digits;
    }

    private function normalizeName(
        mixed $value
    ): string {
        $value = mb_strtolower(
            trim((string) $value)
        );

        return preg_replace(
            '/[^a-z0-9]+/u',
            '',
            $value
        ) ?? '';
    }

    private function dateOrNull(
        mixed $value
    ): ?string {
        if (! $value) {
            return null;
        }

        $time = strtotime(
            (string) $value
        );

        return $time === false
            ? null
            : date('Y-m-d', $time);
    }

    private function integerOrNull(
        mixed $value
    ): ?int {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        return is_numeric($value)
            ? (int) $value
            : null;
    }

    private function documentTitle(
        string $type,
        string $originalName
    ): string {
        return match ($type) {
            'international_license' =>
                'International Nursing License',

            'training_certificate' =>
                'Training Certificate',

            'nursing_diploma' =>
                'Nursing Education Document',

            'employment_certificate' =>
                'Employment Certificate',

            default =>
                pathinfo(
                    $originalName,
                    PATHINFO_FILENAME
                ),
        };
    }

    /**
     * Turn an uploaded roster worksheet into one review candidate per row.
     * The spreadsheet itself stays at batch level for audit because one file
     * can legitimately be the source of many candidates.
     */
    private function rosterCandidates($files): array
    {
        $candidates = [];

        foreach ($files as $file) {
            if (! in_array(strtolower((string) ($file->extension ?? '')), ['xls', 'xlsx'], true)) {
                continue;
            }

            $lines = array_values(array_filter(
                array_map('trim', preg_split('/\n/u', (string) ($file->extracted_text ?? '')) ?: []),
                fn (string $line): bool => $line !== '' && ! str_starts_with($line, 'Worksheet ')
            ));

            if (count($lines) < 2) {
                continue;
            }

            $headers = array_map(
                fn (string $value): string => $this->rosterHeader($value),
                explode("\t", array_shift($lines))
            );

            foreach ($lines as $line) {
                $values = explode("\t", $line);
                $row = [];
                foreach ($headers as $index => $header) {
                    if ($header !== '') {
                        $row[$header] = trim((string) ($values[$index] ?? ''));
                    }
                }

                $fields = $this->rosterFields($row);
                if ($fields === []) {
                    continue;
                }

                $candidates[] = [[
                    'file' => $file,
                    'fields' => $fields,
                ]];
            }
        }

        return $candidates;
    }

    private function rosterHeader(string $header): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $header) ?? $header));
    }

    private function rosterFields(array $row): array
    {
        $value = function (array $needles) use ($row): ?string {
            foreach ($row as $header => $cell) {
                foreach ($needles as $needle) {
                    if (str_contains($header, $needle) && $cell !== '') {
                        return mb_substr($cell, 0, 255);
                    }
                }
            }
            return null;
        };
        $add = function (array &$fields, string $field, ?string $cell, float $confidence = 0.97): void {
            if ($cell !== null && trim($cell) !== '') {
                $limits = [
                    'first_name' => 160,
                    'second_name' => 120,
                    'middle_initial' => 20,
                    'middle_name' => 160,
                    'last_name' => 160,
                    'nationality' => 120,
                    'current_position' => 190,
                    'current_employer' => 255,
                ];
                $fields[$field] = [
                    'value' => mb_substr(trim($cell), 0, $limits[$field] ?? 255),
                    'confidence' => $confidence,
                ];
            }
        };

        $fields = [];
        $add($fields, 'email', $value(['email']), 0.99);
        $phone = $value(['mobile', 'phone', 'contact number']);
        if ($phone !== null && preg_match('/\+?\d[\d\s()-]{6,30}/u', $phone, $phoneMatch)) {
            $phone = preg_replace('/[\s()-]+/u', '', $phoneMatch[0]) ?? $phoneMatch[0];
        }
        $add($fields, 'phone', $phone !== null ? mb_substr($phone, 0, 32) : null, 0.98);
        $birthDate = $value(['date of birth', 'birth date', 'birthday']);
        if ($birthDate !== null && is_numeric($birthDate) && (float) $birthDate > 1000) {
            $birthDate = gmdate('Y-m-d', strtotime('1899-12-30 UTC') + ((int) $birthDate * 86400));
        }
        $add($fields, 'birth_date', $birthDate, 0.98);
        $add($fields, 'nationality', $value(['nationality', 'citizenship']), 0.98);
        $add($fields, 'address_line1', $value(['permanent address', 'address']), 0.95);
        $add($fields, 'current_employer', $value(['hospital', 'healthcare facility', 'employer']), 0.90);
        $add($fields, 'current_position', $value(['position', 'designation', 'job title']), 0.90);

        $fullName = $value(['full name', 'name of applicant', 'applicant name']);
        if ($fullName !== null) {
            $name = $this->rosterName($fullName);
            foreach ($name as $field => $cell) {
                $add($fields, $field, $cell, 0.96);
            }
        }

        $detailLabels = [
            'civil status' => 'Civil status',
            'country/countries of employment' => 'Employment countries',
            'hospital / healthcare facility' => 'Healthcare facilities',
            'position/designation' => 'Position history',
            'emergency contact - name' => 'Emergency contact',
            'emergency contact number' => 'Emergency contact number',
            'work setting' => 'Preferred work settings',
            'business or entrepreneurship' => 'Business / entrepreneurship interest',
        ];
        $details = [];
        foreach ($detailLabels as $needle => $label) {
            $detail = $value([$needle]);
            if ($detail !== null) {
                $details[$label] = mb_substr($detail, 0, 5000);
            }
        }
        if ($details !== []) {
            $fields['__roster_details'] = [
                'value' => $details,
                'confidence' => 0.98,
            ];
        }

        return $fields;
    }

    private function rosterName(string $name): array
    {
        $parts = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: []));
        if (count($parts) < 2) {
            return [];
        }

        $last = array_pop($parts);
        $first = array_shift($parts);
        if (mb_strtolower($first) === 'ma.' && $parts !== []) {
            $first .= ' '.array_shift($parts);
        }

        $result = ['first_name' => $first, 'last_name' => $last];
        if ($parts !== []) {
            $middle = implode(' ', $parts);
            if (preg_match('/^[\p{L}]\.$/u', $middle)) {
                $result['middle_initial'] = $middle;
            } else {
                $result['middle_name'] = $middle;
            }
        }
        return $result;
    }

    private function refreshBatch(
        int $batchId
    ): void {
        $candidateQuery = DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where('batch_id', $batchId);

        $total =
            (clone $candidateQuery)->count();

        $ready =
            (clone $candidateQuery)
                ->where('status', 'ready')
                ->count();

        $review =
            (clone $candidateQuery)
                ->where(
                    'status',
                    'review_required'
                )
                ->count();

        DB::table(
            'nurselink_bulk_intake_batches'
        )
            ->where('id', $batchId)
            ->update([
                'candidate_count' =>
                    $total,

                'ready_count' =>
                    $ready,

                'review_count' =>
                    $review,

                'status' =>
                    $review > 0
                        ? 'review_required'
                        : (
                            $total > 0
                                ? 'ready_for_review'
                                : 'ready_for_grouping'
                        ),

                'updated_at' =>
                    now(),
            ]);
    }
}
