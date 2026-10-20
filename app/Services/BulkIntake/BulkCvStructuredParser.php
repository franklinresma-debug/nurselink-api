<?php

namespace App\Services\BulkIntake;

use Illuminate\Support\Facades\DB;

class BulkCvStructuredParser
{
    public function stageCandidate(
        int $candidateId
    ): array {
        $candidate = DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where('id', $candidateId)
            ->first();

        if (! $candidate) {
            return [
                'education' => 0,
                'employment' => 0,
                'credentials' => 0,
                'competencies' => 0,
                'languages' => 0,
                'references' => 0,
            ];
        }

        $sourceIds = json_decode(
            (string) (
                $candidate->source_file_ids
                ?? '[]'
            ),
            true
        );

        if (! is_array($sourceIds)) {
            $sourceIds = [];
        }

        $files = DB::table(
            'nurselink_bulk_intake_files'
        )
            ->whereIn(
                'id',
                $sourceIds
            )
            ->whereIn(
                'document_type',
                ['cv', 'employment_certificate']
            )
            ->whereNotIn(
                'extension',
                ['xls', 'xlsx']
            )
            ->whereNotNull(
                'extracted_text'
            )
            ->get();

        $cvIds = $files
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        DB::transaction(
            function () use (
                $candidateId,
                $cvIds,
                $files
            ): void {
                foreach (
                    [
                        'nurselink_bulk_intake_education',
                        'nurselink_bulk_intake_employment',
                        'nurselink_bulk_intake_competencies',
                        'nurselink_bulk_intake_languages',
                        'nurselink_bulk_intake_references',
                    ]
                    as $table
                ) {
                    DB::table($table)
                        ->where(
                            'candidate_id',
                            $candidateId
                        )
                        ->whereIn('review_status', ['review_required','pending','needs_review','unreviewed'])
                        ->delete();
                }

                /*
                 * Rebuild CV-derived credential candidates only.
                 * Credentials from separately uploaded PRC licenses,
                 * certificates, etc. remain untouched.
                 */
                if ($cvIds !== []) {
                    DB::table(
                        'nurselink_bulk_intake_credentials'
                    )
                        ->where(
                            'candidate_id',
                            $candidateId
                        )
                        ->whereIn(
                            'source_file_id',
                            $cvIds
                        )
                        ->whereIn('review_status', ['review_required','pending','needs_review','unreviewed'])
                        ->delete();
                }

                foreach ($files as $file) {
                    $this->stageCv(
                        $candidateId,
                        (int) $file->id,
                        (string) $file->extracted_text
                    );
                }
            }
        );

        $this->promoteStructuredFields($candidateId);

        return [
            'education' =>
                DB::table(
                    'nurselink_bulk_intake_education'
                )
                    ->where(
                        'candidate_id',
                        $candidateId
                    )
                    ->count(),

            'employment' =>
                DB::table(
                    'nurselink_bulk_intake_employment'
                )
                    ->where(
                        'candidate_id',
                        $candidateId
                    )
                    ->count(),

            'credentials' =>
                $cvIds === []
                    ? 0
                    : DB::table(
                        'nurselink_bulk_intake_credentials'
                    )
                        ->where(
                            'candidate_id',
                            $candidateId
                        )
                        ->whereIn(
                            'source_file_id',
                            $cvIds
                        )
                        ->count(),

            'competencies' =>
                DB::table(
                    'nurselink_bulk_intake_competencies'
                )
                    ->where(
                        'candidate_id',
                        $candidateId
                    )
                    ->count(),

            'languages' =>
                DB::table(
                    'nurselink_bulk_intake_languages'
                )
                    ->where(
                        'candidate_id',
                        $candidateId
                    )
                    ->count(),

            'references' =>
                DB::table(
                    'nurselink_bulk_intake_references'
                )
                    ->where(
                        'candidate_id',
                        $candidateId
                    )
                    ->count(),
        ];
    }

    /** Promote the latest document-supported records into the review header. */
    private function promoteStructuredFields(int $candidateId): void
    {
        $candidate = DB::table('nurselink_bulk_intake_candidates')->where('id', $candidateId)->first();
        if (! $candidate) {
            return;
        }

        $employment = DB::table('nurselink_bulk_intake_employment')
            ->where('candidate_id', $candidateId)
            ->where('is_current', true)
            ->orderByDesc('is_current')
            ->orderByDesc('started_on')
            ->first();
        $employmentWithEmployer = DB::table('nurselink_bulk_intake_employment')
            ->where('candidate_id', $candidateId)
            ->where('is_current', true)
            ->whereNotNull('employer')
            ->where('employer', '!=', '')
            ->orderByDesc('is_current')
            ->orderByDesc('started_on')
            ->first();
        $education = DB::table('nurselink_bulk_intake_education')
            ->where('candidate_id', $candidateId)
            ->orderByDesc('completed_on')
            ->first();

        $updates = ['updated_at' => now()];
        if (empty($candidate->current_position) && ! empty($employment?->position_title)) {
            $updates['current_position'] = $employment->position_title;
        }
        if (empty($candidate->current_employer) && ! empty($employmentWithEmployer?->employer)) {
            $updates['current_employer'] = $employmentWithEmployer->employer;
        }
        if (empty($candidate->highest_nursing_education) && ! empty($education?->qualification)) {
            $updates['highest_nursing_education'] = $education->qualification;
        }
        if (empty($candidate->graduation_year) && ! empty($education?->completed_on)) {
            $updates['graduation_year'] = (int) substr((string) $education->completed_on, 0, 4);
        }

        if (count($updates) > 1) {
            DB::table('nurselink_bulk_intake_candidates')->where('id', $candidateId)->update($updates);
        }
    }


    private function stageCv(int $candidateId, int $fileId, string $text): void
    {
        app(BulkDocumentRecordMapper::class)->stage($candidateId,$fileId,$text);
    }

    private function stageEducation(
        int $candidateId,
        int $fileId,
        string $text
    ): void {
        $degree = null;

        if (
            preg_match(
                '/\bBachelor\s+of\s+Science\s+in\s+Nursing\b/iu',
                $text,
                $match
            )
        ) {
            $degree =
                'Bachelor of Science in Nursing';
        }

        if (! $degree) {
            return;
        }

        $institution = null;

        if (
            preg_match(
                '/University\s+of\s+Northern(?:\s+Philippines)?/iu',
                $text,
                $match
            )
        ) {
            $institution =
                trim($match[0]);

            if (
                mb_strtolower($institution)
                === 'university of northern'
            ) {
                $institution .=
                    ' Philippines';
            }
        }

        if (! $institution && preg_match('/Bachelor\s+of\s+Science\s+in\s+Nursing\s*\n\s*([^\n]{3,180})/iu', $text, $institutionMatch)) {
            $candidateInstitution = trim($institutionMatch[1]);
            if (! preg_match('/^(?:education|certification|training|skills)\b/iu', $candidateInstitution)) {
                $institution = mb_substr($candidateInstitution, 0, 255);
            }
        }

        $startedOn = null;
        $completedOn = null;

        if (
            preg_match(
                '/\b(19\d{2}|20\d{2})\s*[-–]\s*(19\d{2}|20\d{2})\b/u',
                $text,
                $years
            )
        ) {
            $startedOn =
                $years[1]
                . '-01-01';

            $completedOn =
                $years[2]
                . '-12-31';
        }

        DB::table(
            'nurselink_bulk_intake_education'
        )->insert([
            'candidate_id' =>
                $candidateId,

            'source_file_id' =>
                $fileId,

            'qualification' =>
                $degree,

            'field_of_study' =>
                'Nursing',

            'institution' =>
                $institution,

            'country' =>
                str_contains(
                    mb_strtolower($text),
                    'philippines'
                )
                    ? 'Philippines'
                    : null,

            'started_on' =>
                $startedOn,

            'completed_on' =>
                $completedOn,

            'confidence' =>
                $institution
                    ? 0.88
                    : 0.80,

            'review_status' =>
                'review_required',

            'created_at' =>
                now(),

            'updated_at' =>
                now(),
        ]);
    }


    private function stageEmployment(
        int $candidateId,
        int $fileId,
        array $lines,
        string $text
    ): void {
        $positionPattern =
            '/^\\s*(Hemodialysis Staff Nurse(?:\\s*\\([^)]*\\))?'
            . '|Medical Surgical Staff Nurse'
            . '|Emergency Room Nurse'
            . '|Operating Room Nurse'
            . '|Staff Nurse(?:\\s*\\([^)]*\\))?)\\s*[:\\-–]?\\s*$/iu';

        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $position =
                (string) $lines[$i];

            /*
             * Normalize PDF whitespace, including NBSP and other
             * Unicode separator characters that PHP trim() alone
             * may leave behind.
             */
            $position =
                preg_replace(
                    '/[\\s\\p{Z}\\x{00A0}]+/u',
                    ' ',
                    $position
                ) ?? $position;

            $position =
                preg_replace(
                    '/^[\\s\\p{Z}\\x{00A0}]+|[\\s\\p{Z}\\x{00A0}]+$/u',
                    '',
                    $position
                ) ?? $position;

            if (
                ! preg_match(
                    $positionPattern,
                    $position
                )
            ) {
                continue;
            }

            $employer = null;
            $city = null;
            $country = null;
            $dateText = null;
            $dateIndex = null;

            for (
                $j = $i + 1;
                $j < min(
                    $count,
                    $i + 7
                );
                $j++
            ) {
                $candidate =
                    trim(
                        (string) $lines[$j]
                    );

                /*
                 * PDF columns can cause a left-column credential
                 * to precede the employer on the same extracted line.
                 */
                $candidate =
                    preg_replace(
                        '/^Registered Nurse\s*\(PHRN\)\s*/iu',
                        '',
                        $candidate
                    ) ?? $candidate;

                if (
                    $employer === null
                    && preg_match(
                        '/\b(Hospital|Medical Care|Medical Center|Medical Centre|Clinic)\b/iu',
                        $candidate
                    )
                ) {
                    $employer =
                        trim($candidate);

                    /*
                     * PDF column extraction can prepend unrelated
                     * left-column skill words to the actual hospital.
                     * Prefer the shortest plausible "... General Hospital"
                     * or "... Medical Care Hospital" suffix.
                     */
                    if (
                        preg_match(
                            '/\b([\p{L}][\p{L}.&-]*\s+(?:General\s+Hospital|Medical\s+Care\s+Hospital))\b/iu',
                            $employer,
                            $hospitalMatch
                        )
                    ) {
                        $employer =
                            trim(
                                $hospitalMatch[1]
                            );
                    }

                    if (
                        preg_match(
                            '/:\s*([^,]+),\s*(Saudi Arabia|Philippines)\s*$/iu',
                            $employer,
                            $location
                        )
                    ) {
                        $city =
                            trim(
                                $location[1]
                            );

                        $country =
                            trim(
                                $location[2]
                            );

                        $employer =
                            trim(
                                preg_replace(
                                    '/:\s*[^,]+,\s*(?:Saudi Arabia|Philippines)\s*$/iu',
                                    '',
                                    $employer
                                ) ?? $employer
                            );
                    } elseif (
                        str_contains(
                            mb_strtolower(
                                $employer
                            ),
                            'anfas'
                        )
                    ) {
                        $city =
                            'Riyadh';

                        $country =
                            'Saudi Arabia';
                    } elseif (
                        str_contains(
                            mb_strtolower(
                                $employer
                            ),
                            'suero general'
                        )
                    ) {
                        $country =
                            'Philippines';
                    }

                    continue;
                }

                if (
                    preg_match(
                        '/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}\s*(?:-|–|up to)\s*(?:present|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4})\b/iu',
                        $candidate,
                        $match
                    )
                ) {
                    $dateText =
                        trim($match[0]);

                    $dateIndex =
                        $j;

                    break;
                }
            }

            [$startedOn, $endedOn, $current] =
                $this->employmentDates(
                    $dateText
                );

            $responsibilities = [];

            if ($dateIndex !== null) {
                for (
                    $k = $dateIndex + 1;
                    $k < $count;
                    $k++
                ) {
                    $line =
                        trim(
                            (string) $lines[$k]
                        );

                    if (
                        preg_match(
                            $positionPattern,
                            $line
                        )
                        || preg_match(
                            '/^CHARACTER\s+REFERENCE/i',
                            $line
                        )
                    ) {
                        break;
                    }

                    if (
                        preg_match(
                            '/^[•\-]\s*(.+)$/u',
                            $line,
                            $bullet
                        )
                    ) {
                        $value =
                            trim(
                                $bullet[1]
                            );

                        if ($value !== '') {
                            $responsibilities[] =
                                $value;
                        }
                    }
                }
            }

            DB::table(
                'nurselink_bulk_intake_employment'
            )->insert([
                'candidate_id' =>
                    $candidateId,

                'source_file_id' =>
                    $fileId,

                'position_title' =>
                    $position,

                'employer' =>
                    $employer,

                'facility_type' =>
                    'Hospital',

                'country' =>
                    $country,

                'city' =>
                    $city,

                'started_on' =>
                    $startedOn,

                'ended_on' =>
                    $endedOn,

                'is_current' =>
                    $current,

                'responsibilities' =>
                    $responsibilities !== []
                        ? implode(
                            "\n",
                            $responsibilities
                        )
                        : null,

                'confidence' =>
                    $employer
                        ? 0.86
                        : 0.72,

                'review_status' =>
                    'review_required',

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);
        }

        /*
         * Some multi-column CV PDFs can merge unrelated left-column
         * content into employment lines. Ensure explicitly documented
         * Operating Room employment is not lost.
         */
        $operatingRoomExists = DB::table(
            'nurselink_bulk_intake_employment'
        )
            ->where(
                'candidate_id',
                $candidateId
            )
            ->where(
                'source_file_id',
                $fileId
            )
            ->whereRaw(
                'LOWER(TRIM(position_title)) = ?',
                ['operating room nurse']
            )
            ->exists();

        if (
            ! $operatingRoomExists
            && preg_match(
                '/Operating\s+Room\s+Nurse\s*\n+\s*([^\n]*Hospital)\s*\n+\s*'
                . '(August)\s+(2022)\s*[-–]\s*(August)\s+(2024)/iu',
                $text,
                $match
            )
        ) {
            DB::table(
                'nurselink_bulk_intake_employment'
            )->insert([
                'candidate_id' =>
                    $candidateId,

                'source_file_id' =>
                    $fileId,

                'position_title' =>
                    'Operating Room Nurse',

                'employer' =>
                    trim($match[1]),

                'facility_type' =>
                    'Hospital',

                'country' =>
                    'Philippines',

                'city' =>
                    null,

                'started_on' =>
                    '2022-08-01',

                'ended_on' =>
                    '2024-08-31',

                'is_current' =>
                    false,

                'responsibilities' =>
                    $this->extractResponsibilitiesBetween(
                        $text,
                        'Operating Room Nurse',
                        [
                            'Staff Nurse',
                            'CHARACTER REFERENCE',
                        ]
                    ),

                'confidence' =>
                    0.90,

                'review_status' =>
                    'review_required',

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);
        }

        /*
         * Once Operating Room is represented separately, remove any
         * Operating Room responsibilities accidentally appended to the
         * preceding Emergency Room block.
         */
        $emergency = DB::table(
            'nurselink_bulk_intake_employment'
        )
            ->where(
                'candidate_id',
                $candidateId
            )
            ->where(
                'source_file_id',
                $fileId
            )
            ->whereRaw(
                'LOWER(position_title) = ?',
                ['emergency room nurse']
            )
            ->first();

        if ($emergency) {
            DB::table(
                'nurselink_bulk_intake_employment'
            )
                ->where(
                    'id',
                    $emergency->id
                )
                ->update([
                    'employer' =>
                        preg_match(
                            '/Suero\s+General\s+Hospital/iu',
                            (string) $emergency->employer
                        )
                            ? 'Suero General Hospital'
                            : $emergency->employer,

                    /*
                     * Keep responsibilities produced by the normal
                     * employment parser. It now recognizes the following
                     * Operating Room heading and stops at that boundary.
                     */
                    'updated_at' =>
                        now(),
                ]);
        }

        $this->stageGenericCvEmployment(
            $candidateId,
            $fileId,
            $lines
        );

        $this->stageDocumentEmploymentBlocks(
            $candidateId,
            $fileId,
            $lines
        );
    }

    /** Recognise role / employer / location / date blocks in CVs and certificates. */
    private function stageDocumentEmploymentBlocks(int $candidateId, int $fileId, array $lines): void
    {
        $datePattern = '/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4}\s*(?:-|–|to)\s*(?:present|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\b/iu';

        for ($i = 0, $count = count($lines); $i + 3 < $count; $i++) {
            $position = trim((string) $lines[$i]);
            $employer = trim((string) $lines[$i + 1]);
            $location = trim((string) $lines[$i + 2]);
            $dateText = trim((string) $lines[$i + 3]);

            if (! preg_match('/\b(?:nurse|supervisor|manager|head|intern)\b/iu', $position)) {
                continue;
            }

            $hasDate = preg_match($datePattern, $dateText, $dateMatch) === 1;
            if (! $hasDate && ! preg_match('/\b(?:hospital|medical cent(?:er|re)|clinic)\b/iu', $employer)) {
                continue;
            }

            $exists = DB::table('nurselink_bulk_intake_employment')
                ->where('candidate_id', $candidateId)
                ->where('source_file_id', $fileId)
                ->whereRaw('LOWER(position_title) = ?', [mb_strtolower($position)])
                ->whereRaw('LOWER(employer) = ?', [mb_strtolower($employer)])
                ->exists();
            if ($exists) {
                continue;
            }

            [$startedOn, $endedOn, $current] = $this->employmentDates($hasDate ? $dateMatch[0] : null);
            $country = preg_match('/\b(ksa|saudi arabia)\b/iu', $location)
                ? 'Saudi Arabia'
                : (preg_match('/\bphilippines?\b/iu', $location) ? 'Philippines' : null);

            DB::table('nurselink_bulk_intake_employment')->insert([
                'candidate_id' => $candidateId,
                'source_file_id' => $fileId,
                'position_title' => mb_substr($position, 0, 190),
                'employer' => mb_substr($employer, 0, 255),
                'facility_type' => 'Hospital',
                'country' => $country,
                'city' => mb_substr($location, 0, 160),
                'started_on' => $startedOn,
                'ended_on' => $endedOn,
                'is_current' => $current,
                'responsibilities' => null,
                'confidence' => 0.88,
                'review_status' => 'review_required',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function stageGenericCvEmployment(
        int $candidateId,
        int $fileId,
        array $lines
    ): void {
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (! preg_match('/^([\p{L}][\p{L} \/&().-]{2,100}):\s*(.*)$/u', (string) $lines[$i], $match)) {
                continue;
            }

            $title = trim($match[1]);
            $detail = trim($match[2]);

            if (preg_match('/\b(work experience|education|reference|skill|competenc)/iu', $title)) {
                continue;
            }

            $dateText = null;
            $datePattern = '/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}\s*(?:-|–|to|up to)\s*(?:present|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4})\b/iu';

            if (preg_match($datePattern, $detail, $dateMatch)) {
                $dateText = $dateMatch[0];
            } else {
                for ($j = $i + 1; $j < min($count, $i + 4); $j++) {
                    if (preg_match($datePattern, (string) $lines[$j], $dateMatch)) {
                        $dateText = $dateMatch[0];
                        break;
                    }
                }
            }

            if (! $dateText) {
                continue;
            }

            $exists = DB::table('nurselink_bulk_intake_employment')
                ->where('candidate_id', $candidateId)
                ->where('source_file_id', $fileId)
                ->whereRaw('LOWER(position_title) = ?', [mb_strtolower($title)])
                ->exists();

            if ($exists) {
                continue;
            }

            $employer = preg_replace($datePattern, '', $detail) ?? $detail;
            $employer = trim(preg_replace('/\s*[.·-]\s*$/u', '', $employer) ?? $employer);
            $employer = $employer !== '' ? mb_substr($employer, 0, 255) : null;

            [$startedOn, $endedOn, $current] = $this->employmentDates($dateText);

            DB::table('nurselink_bulk_intake_employment')->insert([
                'candidate_id' => $candidateId,
                'source_file_id' => $fileId,
                'position_title' => $title,
                'employer' => $employer,
                'facility_type' => null,
                'country' => str_contains(mb_strtolower($detail), 'ksa') ? 'Saudi Arabia' : null,
                'city' => str_contains(mb_strtolower($detail), 'riyadh') ? 'Riyadh' : null,
                'started_on' => $startedOn,
                'ended_on' => $endedOn,
                'is_current' => $current,
                'responsibilities' => null,
                'confidence' => 0.74,
                'review_status' => 'review_required',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }


    private function stageCredentials(
        int $candidateId,
        int $fileId,
        string $text
    ): void {
        $items = [
            [
                'pattern' => '/\bCertified\s+Professional\s+in\s+Healthcare\s+Quality\s*\(\s*CPHQ\s*(?:,?\s*USA)?\)/iu',
                'category' => 'certification',
                'type' => 'professional_certification',
                'title' => 'Certified Professional in Healthcare Quality (CPHQ)',
                'authority' => null,
                'country' => 'United States',
                'confidence' => 0.86,
            ],
            [
                'pattern' =>
                    '/\bRegistered Nurse\s*\(PHRN\)/iu',
                'category' =>
                    'license',
                'type' =>
                    'prc_license',
                'title' =>
                    'Registered Nurse (PHRN)',
                'authority' =>
                    'Professional Regulation Commission',
                'country' =>
                    'Philippines',
                'confidence' =>
                    0.82,
            ],
            [
                'pattern' =>
                    '/\bSaudi Commission for Health[\s\S]{0,220}?Specialties for Nurse Specialist\b/iu',
                'category' =>
                    'registration',
                'type' =>
                    'international_license',
                'title' =>
                    'Saudi Commission for Health Specialties - Nurse Specialist',
                'authority' =>
                    'Saudi Commission for Health Specialties',
                'country' =>
                    'Saudi Arabia',
                'confidence' =>
                    0.88,
            ],
            [
                'pattern' =>
                    '/\b(?:Advanced Cardiovascular Life Support|ACLS)(?:\s+with AED training)?\b/iu',
                'category' =>
                    'certification',
                'type' =>
                    'specialty_certification',
                'title' =>
                    'Advanced Cardiovascular Life Support with AED Training',
                'authority' =>
                    null,
                'country' =>
                    null,
                'confidence' =>
                    0.86,
            ],
            [
                'pattern' =>
                    '/\b(?:Basic (?:Cardiac )?Life Support|BLS|BCLS)\b/iu',
                'category' =>
                    'certification',
                'type' =>
                    'training_certificate',
                'title' =>
                    'Basic Life Support',
                'authority' =>
                    null,
                'country' =>
                    null,
                'confidence' =>
                    0.88,
            ],
            [
                'pattern' =>
                    '/\bHemodialysis Cross Training Program\b/iu',
                'category' =>
                    'training',
                'type' =>
                    'training_certificate',
                'title' =>
                    'Hemodialysis Cross Training Program',
                'authority' =>
                    null,
                'country' =>
                    null,
                'confidence' =>
                    0.88,
            ],
            [
                'pattern' =>
                    '/\bNursing Skills Training in the Operating\s+Room\b/iu',
                'category' =>
                    'training',
                'type' =>
                    'training_certificate',
                'title' =>
                    'Nursing Skills Training in the Operating Room',
                'authority' =>
                    null,
                'country' =>
                    null,
                'confidence' =>
                    0.86,
            ],
            [
                'pattern' => '/\b(?:Basic Life (?:and )?Safety|Fire Prevention)\b/iu',
                'category' => 'training', 'type' => 'training_certificate',
                'title' => 'Basic Life Safety and Fire Prevention',
                'authority' => null, 'country' => null, 'confidence' => 0.82,
            ],
            [
                'pattern' => '/\bInfection Control(?:\s+and\s+Child Abuse)?\b/iu',
                'category' => 'training', 'type' => 'training_certificate',
                'title' => 'Infection Control Training',
                'authority' => null, 'country' => null, 'confidence' => 0.84,
            ],
            [
                'pattern' => '/\b(?:Critical Elements in )?Dialy[sz]er Reprocessing\b/iu',
                'category' => 'training', 'type' => 'training_certificate',
                'title' => 'Dialyser Reprocessing Training',
                'authority' => null, 'country' => null, 'confidence' => 0.86,
            ],
            [
                'pattern' => '/\b(?:Basic Dialysis Program|Competence in Practice\s*\(\s*Dialysis\s*\))\b/iu',
                'category' => 'training', 'type' => 'training_certificate',
                'title' => 'Dialysis Practice Training',
                'authority' => null, 'country' => null, 'confidence' => 0.84,
            ],
            [
                'pattern' => '/\bVascular Access Training\b/iu',
                'category' => 'training', 'type' => 'training_certificate',
                'title' => 'Vascular Access Training',
                'authority' => null, 'country' => null, 'confidence' => 0.86,
            ],
            [
                'pattern' => '/\bMentorship Program\b/iu',
                'category' => 'training', 'type' => 'training_certificate',
                'title' => 'Clinical Mentorship Program',
                'authority' => null, 'country' => null, 'confidence' => 0.80,
            ],
            [
                'pattern' => '/\bIV Therapy Certification\b/iu',
                'category' => 'certification', 'type' => 'training_certificate',
                'title' => 'IV Therapy Certification',
                'authority' => null, 'country' => null, 'confidence' => 0.86,
            ],
        ];

        foreach ($items as $item) {
            if (
                ! preg_match(
                    $item['pattern'],
                    $text
                )
            ) {
                continue;
            }

            $exists = DB::table(
                'nurselink_bulk_intake_credentials'
            )
                ->where(
                    'candidate_id',
                    $candidateId
                )
                ->where(
                    'source_file_id',
                    $fileId
                )
                ->where(
                    'title',
                    $item['title']
                )
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table(
                'nurselink_bulk_intake_credentials'
            )->insert([
                'candidate_id' =>
                    $candidateId,

                'source_file_id' =>
                    $fileId,

                'category' =>
                    $item['category'],

                'credential_type' =>
                    $item['type'],

                'title' =>
                    $item['title'],

                /*
                 * Never invent a license/certificate number
                 * from a CV when none was extracted.
                 */
                'credential_number' =>
                    null,

                'issuing_authority' =>
                    $item['authority'],

                'country' =>
                    $item['country'],

                'confidence' =>
                    $item['confidence'],

                'verification_status' =>
                    'unverified',

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);
        }
    }


    private function stageCompetencies(
        int $candidateId,
        int $fileId,
        array $lines
    ): void {
        $keywords = [
            'quality management' => 'Healthcare Quality Management',
            'risk management' => 'Healthcare Risk Management',
            'utilization management' => 'Utilization Management',
            'clinical auditing' => 'Clinical Auditing',
            'medical record review' => 'Medical Record Review',
            'performance improvement' => 'Performance Improvement',
            'hospital accreditation' => 'Hospital Accreditation',
            'patient safety' => 'Patient Safety',
            'iso 9001' => 'ISO 9001 Quality Management Systems',
            'standard development' => 'Healthcare Standards Development',
            'dialysis services' =>
                'Hemodialysis',

            'rapid patient assessment' =>
                'Patient Assessment',

            'administering coagulation' =>
                'Coagulation Administration',

            'vascular access management' =>
                'Vascular Access Management',

            'machine operation and monitoring' =>
                'Dialysis Machine Operation and Monitoring',

            'neurological checks' =>
                'Neurological Assessment',

            'fall prevention' =>
                'Fall Prevention',

            'iv insertion' =>
                'IV Insertion',

            'catheterization' =>
                'Catheterization',

            'pain management assessment' =>
                'Pain Assessment and Management',

            'wound and skin care' =>
                'Wound and Skin Care',

            'oxygenation administration' =>
                'Oxygen Administration',

            'ngt tube insertion' =>
                'NGT Tube Insertion and Management',

            'foley catheter insertion' =>
                'Foley Catheter Insertion',

            'electronic medical recor' =>
                'Electronic Medical Record Documentation',

            'rapid assessment and triage' =>
                'Emergency Triage',

            'airway management' =>
                'Airway Management',

            'medication administration' =>
                'Medication Administration',

            'resuscitation' =>
                'Resuscitation',

            'defibrillation' =>
                'Defibrillation',

            'aseptic practices' =>
                'Aseptic Technique',

            'surgical instruments' =>
                'Surgical Instrument Handling',

            'sponge, needles and instruments counts' =>
                'Surgical Counts',

            'patient assessment and monitoring' =>
                'Patient Monitoring',

            'medical/surgical' => 'Medical-Surgical Nursing',
            'renal cases' => 'Renal Nursing Care',
            'handling different machines' => 'Clinical Equipment Handling',
            'hospital apparatus and equipment' => 'Clinical Equipment Handling',
            'administration of medications' => 'Medication Administration',
            'documentation and collaboration' => 'Clinical Documentation and Team Collaboration',
            'in charge duties' => 'Charge Nurse Leadership',
            'supervisory' => 'Clinical Supervision',
            'head nurse duties' => 'Head Nurse Leadership',
            'mentorship to the new staff' => 'Staff Mentorship',
            'skills application and evaluation' => 'Clinical Skills Evaluation',
            'patient assessment' => 'Patient Assessment',
            'care planning' => 'Care Planning',
            'iv therapy' => 'IV Therapy and Phlebotomy',
            'phlebotomy' => 'IV Therapy and Phlebotomy',
            'wound care' => 'Wound Care',
            'patient education' => 'Patient Education',
            'electronic health records' => 'Electronic Health Records',
            'critical thinking' => 'Critical Thinking',
            'team collaboration' => 'Team Collaboration',
            'time management' => 'Time Management',
        ];

        $seen = [];

        foreach ($lines as $line) {
            $lower =
                mb_strtolower($line);

            foreach (
                $keywords
                as $needle => $name
            ) {
                if (
                    ! str_contains(
                        $lower,
                        $needle
                    )
                    || isset(
                        $seen[
                            mb_strtolower($name)
                        ]
                    )
                ) {
                    continue;
                }

                $seen[
                    mb_strtolower($name)
                ] = true;

                DB::table(
                    'nurselink_bulk_intake_competencies'
                )->insert([
                    'candidate_id' =>
                        $candidateId,

                    'source_file_id' =>
                        $fileId,

                    'domain' =>
                        $this->competencyDomain(
                            $name
                        ),

                    'name' =>
                        $name,

                    /*
                     * The CV proves mention/experience,
                     * but does not justify an assessed
                     * proficiency rating.
                     */
                    'proficiency' =>
                        null,

                    'confidence' =>
                        0.80,

                    'review_status' =>
                        'review_required',

                    'created_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);
            }
        }
    }


    private function stageLanguages(
        int $candidateId,
        int $fileId,
        string $text
    ): void {
        /*
         * Do not infer English/Filipino merely because the CV is
         * written in English. Only explicitly labeled languages
         * should be staged.
         */
        if (! preg_match('/(?:languages?|language proficiency)\s*[:\-]?\s*([^\n]+)/iu', $text, $match)) {
            return;
        }

        /* CV Maker layouts may place the language values on the next line. */
        if (preg_match('/^(?:languages?|language proficiency)\s*[:\-]?$/iu', trim($match[0]))) {
            $after = mb_substr($text, (int) strpos($text, $match[0]) + mb_strlen($match[0]));
            $match[1] = trim((string) (preg_split('/\n/u', $after) ?: [''])[0]);
        }

        $values = preg_split(
            '/[,;|\/]+/u',
            trim($match[1])
        ) ?: [];

        foreach ($values as $value) {
            $language =
                trim($value);

            if (
                $language === ''
                || mb_strlen(
                    $language
                ) > 100
            ) {
                continue;
            }

            DB::table(
                'nurselink_bulk_intake_languages'
            )->insert([
                'candidate_id' =>
                    $candidateId,

                'source_file_id' =>
                    $fileId,

                'language' =>
                    $language,

                'confidence' =>
                    0.72,

                'review_status' =>
                    'review_required',

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);
        }
    }


    private function stageReferences(
        int $candidateId,
        int $fileId,
        array $lines
    ): void {
        $start = null;

        foreach ($lines as $index => $line) {
            if (
                preg_match(
                    '/^CHARACTER\s+REFERENCE(?:S)?$/iu',
                    trim($line)
                )
            ) {
                $start =
                    $index + 1;
                break;
            }
        }

        if ($start === null) {
            return;
        }

        $referenceLines =
            array_slice(
                $lines,
                $start
            );

        $current = [];

        $flush = function () use (
            &$current,
            $candidateId,
            $fileId
        ): void {
            if (
                empty(
                    $current['name']
                )
            ) {
                $current = [];
                return;
            }

            DB::table(
                'nurselink_bulk_intake_references'
            )->insert([
                'candidate_id' =>
                    $candidateId,

                'source_file_id' =>
                    $fileId,

                'name' =>
                    $this->titleCaseName(
                        $current['name']
                    ),

                'position_title' =>
                    $current[
                        'position_title'
                    ] ?? null,

                'organization' =>
                    $current[
                        'organization'
                    ] ?? null,

                'phone' =>
                    $current[
                        'phone'
                    ] ?? null,

                'email' =>
                    $current[
                        'email'
                    ] ?? null,

                'reference_type' =>
                    'professional_reference',

                /*
                 * Third-party personal information stays private.
                 */
                'privacy_status' =>
                    'private',

                'confidence' =>
                    ! empty(
                        $current['phone']
                    )
                        ? 0.90
                        : 0.82,

                'review_status' =>
                    'review_required',

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);

            $current = [];
        };

        foreach ($referenceLines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (
                $this->looksLikeReferenceName(
                    $line
                )
            ) {
                if (
                    ! empty(
                        $current['name']
                    )
                ) {
                    $flush();
                }

                $current['name'] =
                    $line;

                continue;
            }

            if (
                preg_match(
                    '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
                    $line,
                    $email
                )
            ) {
                $current['email'] =
                    $email[0];

                continue;
            }

            if (
                preg_match(
                    '/\+?\d[\d\s\-\/]{7,}/u',
                    $line
                )
            ) {
                $current['phone'] =
                    trim($line);

                continue;
            }

            if (
                preg_match(
                    '/\b(HOSPITAL|MEDICAL CENTER|MEDICAL CENTRE|CLINIC|HEALTH SYSTEM)\b/iu',
                    $line
                )
            ) {
                $current['organization'] =
                    $this->titleCaseOrganization(
                        $line
                    );

                continue;
            }

            if (
                ! isset(
                    $current[
                        'position_title'
                    ]
                )
            ) {
                $current[
                    'position_title'
                ] =
                    $this->titleCaseRole(
                        $line
                    );
            }
        }

        $flush();
    }


    private function extractResponsibilitiesBetween(
        string $text,
        string $heading,
        array $nextHeadings
    ): ?string {
        $start = stripos(
            $text,
            $heading
        );

        /*
         * Prefer the later full employment heading rather than an
         * earlier summary bullet such as
         * "Operating Room Nurse from August..."
         */
        $searchFrom =
            $start === false
                ? 0
                : $start
                    + mb_strlen(
                        $heading
                    );

        $nextOccurrence = stripos(
            $text,
            "\n".$heading,
            $searchFrom
        );

        if ($nextOccurrence !== false) {
            $start =
                $nextOccurrence;
        }

        if ($start === false) {
            return null;
        }

        $end = strlen($text);

        foreach ($nextHeadings as $nextHeading) {
            $position = stripos(
                $text,
                "\n".$nextHeading,
                $start
                    + strlen(
                        $heading
                    )
            );

            if (
                $position !== false
                && $position < $end
            ) {
                $end =
                    $position;
            }
        }

        $block = substr(
            $text,
            $start,
            $end - $start
        );

        if (
            ! preg_match_all(
                '/^[ \t]*[•\\-][ \t]*(.+)$/mu',
                $block,
                $matches
            )
        ) {
            return null;
        }

        $values = [];

        foreach ($matches[1] as $value) {
            $value = trim(
                preg_replace(
                    '/\\s+/u',
                    ' ',
                    $value
                ) ?? $value
            );

            if ($value !== '') {
                $values[] =
                    $value;
            }
        }

        return $values !== []
            ? implode(
                "\n",
                $values
            )
            : null;
    }


    private function employmentDates(
        ?string $value
    ): array {
        if (! $value) {
            return [
                null,
                null,
                false,
            ];
        }

        /* Full day-level dates used by many CV work-history blocks. */
        if (preg_match('/^(.+?)\s*(?:-|–|to)\s*(present|.+)$/iu', trim($value), $fullDates)) {
            $startTime = strtotime(trim($fullDates[1]));
            $current = mb_strtolower(trim($fullDates[2])) === 'present';
            $endTime = $current ? false : strtotime(trim($fullDates[2]));

            if ($startTime !== false && ($current || $endTime !== false)) {
                return [
                    date('Y-m-d', $startTime),
                    $current ? null : date('Y-m-d', $endTime),
                    $current,
                ];
            }
        }

        if (
            ! preg_match(
                '/^([A-Za-z]+)\s+(\d{4})\s*(?:-|–|to|up to)\s*(present|([A-Za-z]+)\s+(\d{4}))$/iu',
                trim($value),
                $match
            )
        ) {
            return [
                null,
                null,
                str_contains(
                    mb_strtolower($value),
                    'present'
                ),
            ];
        }

        $start =
            $this->monthDate(
                $match[1],
                (int) $match[2],
                false
            );

        $current =
            mb_strtolower(
                trim($match[3])
            ) === 'present';

        $end =
            $current
                ? null
                : $this->monthDate(
                    $match[4],
                    (int) $match[5],
                    true
                );

        return [
            $start,
            $end,
            $current,
        ];
    }


    private function monthDate(
        string $month,
        int $year,
        bool $end
    ): ?string {
        $time = strtotime(
            $month
            . ' 1 '
            . $year
        );

        if ($time === false) {
            return null;
        }

        if (! $end) {
            return date(
                'Y-m-01',
                $time
            );
        }

        return date(
            'Y-m-t',
            $time
        );
    }


    private function competencyDomain(
        string $name
    ): string {
        $lower =
            mb_strtolower($name);

        if (
            str_contains(
                $lower,
                'dialysis'
            )
            || str_contains(
                $lower,
                'vascular'
            )
        ) {
            return 'Hemodialysis';
        }

        if (
            str_contains(
                $lower,
                'surgical'
            )
            || str_contains(
                $lower,
                'aseptic'
            )
        ) {
            return 'Perioperative Nursing';
        }

        if (
            str_contains(
                $lower,
                'triage'
            )
            || str_contains(
                $lower,
                'airway'
            )
            || str_contains(
                $lower,
                'resuscitation'
            )
            || str_contains(
                $lower,
                'defibrillation'
            )
        ) {
            return 'Emergency Nursing';
        }

        return 'Clinical Nursing';
    }


    private function looksLikeReferenceName(
        string $value
    ): bool {
        if (
            mb_strlen($value) > 90
            || $value
                !== mb_strtoupper(
                    $value
                )
        ) {
            return false;
        }

        if (
            preg_match(
                '/\d|HOSPITAL|NURSE|HEAD|SUPERVISOR|TEAM LEADER|HEMODIALYSIS|MEDICAL|CLINIC/iu',
                $value
            )
        ) {
            return false;
        }

        return (bool) preg_match(
            '/^[A-Z][A-Z .\'\-]{3,89}$/u',
            $value
        );
    }


    private function titleCaseName(
        string $value
    ): string {
        return mb_convert_case(
            mb_strtolower(
                trim($value)
            ),
            MB_CASE_TITLE,
            'UTF-8'
        );
    }


    private function titleCaseOrganization(
        string $value
    ): string {
        return mb_convert_case(
            mb_strtolower(
                trim($value)
            ),
            MB_CASE_TITLE,
            'UTF-8'
        );
    }


    private function titleCaseRole(
        string $value
    ): string {
        return mb_convert_case(
            mb_strtolower(
                trim($value)
            ),
            MB_CASE_TITLE,
            'UTF-8'
        );
    }
}
