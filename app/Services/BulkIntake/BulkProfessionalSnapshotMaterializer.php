<?php

namespace App\Services\BulkIntake;

use App\Models\Application;
use App\Models\Member;
use App\Models\PortfolioCompetency;
use App\Models\PortfolioEducation;
use App\Models\PortfolioEmployment;
use App\Models\PortfolioLanguage;
use App\Models\ProfessionalCredential;
use App\Services\Credentials\CredentialNumberService;
use App\Services\Credentials\CredentialReminderService;
use App\Services\Credentials\CredentialStatusService;
use App\Services\Portfolio\PortfolioCompletionService;
use App\Services\Portfolio\PortfolioTimelineService;

class BulkProfessionalSnapshotMaterializer
{
    public function __construct(
        private readonly CredentialNumberService $numbers,
        private readonly CredentialStatusService $statuses,
        private readonly CredentialReminderService $reminders,
        private readonly PortfolioCompletionService $completion,
        private readonly PortfolioTimelineService $timeline,
    ) {
    }

    public function materialize(
        Member $member,
        Application $application
    ): array {
        $profile =
            is_array($application->profile_data)
                ? $application->profile_data
                : [];

        $snapshot =
            $profile[
                '_bulk_professional_snapshot'
            ] ?? null;

        $counts = [
            'education' => 0,
            'employment' => 0,
            'credentials' => 0,
            'competencies' => 0,
            'languages' => 0,
        ];

        if (
            ! is_array($snapshot)
            || ($snapshot['source'] ?? null)
                !== 'bulk_nurse_intake'
        ) {
            return $counts;
        }

        /*
         * Education
         *
         * Extraction approval is not professional verification.
         * These records therefore start in the existing
         * member_confirmed portfolio state with all verification
         * fields left unset.
         */
        foreach (
            (array) ($snapshot['education'] ?? [])
            as $row
        ) {
            if (! is_array($row)) {
                continue;
            }

            $qualification =
                $this->text(
                    $row['qualification'] ?? null
                );

            $institution =
                $this->text(
                    $row['institution'] ?? null
                );

            if (
                $qualification === null
                || $institution === null
            ) {
                continue;
            }

            $education =
                PortfolioEducation::query()
                    ->firstOrCreate(
                        [
                            'member_id' =>
                                $member->id,

                            'qualification' =>
                                $qualification,

                            'institution' =>
                                $institution,

                            'started_on' =>
                                $this->date(
                                    $row[
                                        'started_on'
                                    ] ?? null
                                ),

                            'completed_on' =>
                                $this->date(
                                    $row[
                                        'completed_on'
                                    ] ?? null
                                ),
                        ],
                        [
                            'field_of_study' =>
                                $this->text(
                                    $row[
                                        'field_of_study'
                                    ] ?? null
                                ),

                            'country' =>
                                $this->countryCode(
                                    $row['country']
                                        ?? null
                                ),

                            'status' =>
                                'member_confirmed',

                            'source_document_id' =>
                                null,

                            'source_extracted_fact_id' =>
                                null,

                            'verified_by' =>
                                null,

                            'verified_at' =>
                                null,

                            'verification_note' =>
                                null,
                        ]
                    );

            if ($education->wasRecentlyCreated) {
                $counts['education']++;
            }
        }

        /*
         * Employment
         */
        foreach (
            (array) ($snapshot['employment'] ?? [])
            as $row
        ) {
            if (! is_array($row)) {
                continue;
            }

            $position =
                $this->text(
                    $row['position_title'] ?? null
                );

            $employer =
                $this->text(
                    $row['employer'] ?? null
                );

            if (
                $position === null
                || $employer === null
            ) {
                continue;
            }

            $employment =
                PortfolioEmployment::query()
                    ->firstOrCreate(
                        [
                            'member_id' =>
                                $member->id,

                            'position_title' =>
                                $position,

                            'employer' =>
                                $employer,

                            'started_on' =>
                                $this->date(
                                    $row[
                                        'started_on'
                                    ] ?? null
                                ),

                            'ended_on' =>
                                $this->date(
                                    $row[
                                        'ended_on'
                                    ] ?? null
                                ),
                        ],
                        [
                            'facility_type' =>
                                $this->text(
                                    $row[
                                        'facility_type'
                                    ] ?? null
                                ),

                            'country' =>
                                $this->countryCode(
                                    $row['country']
                                        ?? null
                                ),

                            'city' =>
                                $this->text(
                                    $row['city']
                                        ?? null
                                ),

                            'is_current' =>
                                (bool) (
                                    $row[
                                        'is_current'
                                    ] ?? false
                                ),

                            'responsibilities' =>
                                $this->text(
                                    $row[
                                        'responsibilities'
                                    ] ?? null
                                ),

                            'status' =>
                                'member_confirmed',

                            'source_document_id' =>
                                null,

                            'source_extracted_fact_id' =>
                                null,

                            'verified_by' =>
                                null,

                            'verified_at' =>
                                null,

                            'verification_note' =>
                                null,
                        ]
                    );

            if ($employment->wasRecentlyCreated) {
                $counts['employment']++;
            }
        }

        /*
         * Competencies
         *
         * NULL proficiency is deliberate: NurseLink does not infer
         * proficiency merely because a competency was mentioned.
         */
        foreach (
            (array) ($snapshot['competencies'] ?? [])
            as $row
        ) {
            if (! is_array($row)) {
                continue;
            }

            $domain =
                $this->text(
                    $row['domain'] ?? null
                );

            $name =
                $this->text(
                    $row['name'] ?? null
                );

            if (
                $domain === null
                || $name === null
            ) {
                continue;
            }

            $competency =
                PortfolioCompetency::query()
                    ->firstOrCreate(
                        [
                            'member_id' =>
                                $member->id,

                            'domain' =>
                                $domain,

                            'name' =>
                                $name,
                        ],
                        [
                            'proficiency' =>
                                $this->text(
                                    $row[
                                        'proficiency'
                                    ] ?? null
                                ),

                            'evidence_state' =>
                                'claimed',

                            'source_document_id' =>
                                null,

                            'source_extracted_fact_id' =>
                                null,

                            'assessed_by' =>
                                null,

                            'assessed_at' =>
                                null,

                            'verified_by' =>
                                null,

                            'verified_at' =>
                                null,
                        ]
                    );

            if ($competency->wasRecentlyCreated) {
                $counts['competencies']++;
            }
        }

        /*
         * Languages
         *
         * Only explicitly extracted languages are materialized.
         * Individual ability levels remain NULL when unstated.
         */
        foreach (
            (array) ($snapshot['languages'] ?? [])
            as $row
        ) {
            if (! is_array($row)) {
                continue;
            }

            $language =
                $this->text(
                    $row['language'] ?? null
                );

            if ($language === null) {
                continue;
            }

            $languageRow =
                PortfolioLanguage::query()
                    ->firstOrCreate(
                        [
                            'member_id' =>
                                $member->id,

                            'language' =>
                                $language,
                        ],
                        [
                            'speaking' =>
                                $this->text(
                                    $row[
                                        'speaking'
                                    ] ?? null
                                ),

                            'reading' =>
                                $this->text(
                                    $row[
                                        'reading'
                                    ] ?? null
                                ),

                            'writing' =>
                                $this->text(
                                    $row[
                                        'writing'
                                    ] ?? null
                                ),

                            'status' =>
                                'member_confirmed',
                        ]
                    );

            if ($languageRow->wasRecentlyCreated) {
                $counts['languages']++;
            }
        }

        /*
         * Professional credentials
         *
         * Human approval of extraction NEVER means credential
         * verification. Every materialized credential is unverified.
         */
        foreach (
            (array) ($snapshot['credentials'] ?? [])
            as $row
        ) {
            if (! is_array($row)) {
                continue;
            }

            $category =
                $this->text(
                    $row['category'] ?? null
                );

            $type =
                $this->text(
                    $row[
                        'credential_type'
                    ] ?? null
                );

            $title =
                $this->text(
                    $row['title'] ?? null
                );

            if (
                $category === null
                || $type === null
                || $title === null
            ) {
                continue;
            }

            $rawNumber =
                $this->text(
                    $row[
                        'credential_number'
                    ] ?? null
                );

            $numberHash =
                $this->numbers
                    ->fingerprint(
                        $rawNumber
                    );

            $country =
                $this->countryCode(
                    $row['country']
                        ?? null
                );

            $issuedOn =
                $this->date(
                    $row['issued_on']
                        ?? null
                );

            $expiresOn =
                $this->date(
                    $row['expires_on']
                        ?? null
                );

            $query =
                ProfessionalCredential::query()
                    ->where(
                        'member_id',
                        $member->id
                    )
                    ->where(
                        'category',
                        $category
                    )
                    ->where(
                        'credential_type',
                        $type
                    )
                    ->where(
                        'title',
                        $title
                    );

            if ($numberHash !== null) {
                $query->where(
                    'credential_number_hash',
                    $numberHash
                );
            } else {
                $query
                    ->where(
                        'issuing_authority',
                        $this->text(
                            $row[
                                'issuing_authority'
                            ] ?? null
                        )
                    )
                    ->where(
                        'country',
                        $country
                    )
                    ->where(
                        'issued_on',
                        $issuedOn
                    )
                    ->where(
                        'expires_on',
                        $expiresOn
                    );
            }

            $credential =
                $query->first();

            if ($credential) {
                continue;
            }

            $credential =
                ProfessionalCredential::query()
                    ->create([
                        'member_id' =>
                            $member->id,

                        'category' =>
                            $category,

                        'credential_type' =>
                            $type,

                        'title' =>
                            $title,

                        'credential_number' =>
                            $rawNumber,

                        'credential_number_hash' =>
                            $numberHash,

                        'credential_number_last4' =>
                            $this->numbers
                                ->last4(
                                    $rawNumber
                                ),

                        'issuing_authority' =>
                            $this->text(
                                $row[
                                    'issuing_authority'
                                ] ?? null
                            ),

                        'country' =>
                            $country,

                        'issued_on' =>
                            $issuedOn,

                        'expires_on' =>
                            $expiresOn,

                        /*
                         * Absence of an expiry date does not prove
                         * that a credential never expires.
                         */
                        'does_not_expire' =>
                            false,

                        'credential_status' =>
                            'active',

                        'verification_status' =>
                            'unverified',

                        'primary_document_id' =>
                            null,

                        'source_extracted_fact_id' =>
                            null,

                        'verified_by' =>
                            null,

                        'verified_at' =>
                            null,

                        'verification_note' =>
                            null,
                    ]);

            $credential =
                $this->statuses
                    ->refresh(
                        $credential
                    );

            $this->reminders
                ->rebuild(
                    $credential
                );

            $counts['credentials']++;
        }

        /*
         * Character/professional references intentionally remain only
         * inside the private Application snapshot. They are third-party
         * personal data and are not copied into the Member portfolio.
         */

        $this->completion
            ->refresh(
                $member->fresh()
            );

        $this->timeline
            ->rebuild(
                $member->fresh()
            );

        return $counts;
    }

    private function countryCode(
        mixed $value
    ): ?string {
        $value =
            $this->text($value);

        if ($value === null) {
            return null;
        }

        if (
            preg_match(
                '/^[A-Za-z]{2}$/',
                $value
            )
        ) {
            return strtoupper($value);
        }

        $key =
            mb_strtolower(
                trim(
                    preg_replace(
                        '/\s+/u',
                        ' ',
                        $value
                    ) ?? $value
                )
            );

        return match ($key) {
            'philippines',
            'republic of the philippines' =>
                'PH',

            'saudi arabia',
            'kingdom of saudi arabia' =>
                'SA',

            default =>
                null,
        };
    }

    private function text(
        mixed $value
    ): ?string {
        if (
            $value === null
            || is_array($value)
            || is_object($value)
        ) {
            return null;
        }

        $value =
            trim(
                preg_replace(
                    '/\s+/u',
                    ' ',
                    (string) $value
                ) ?? (string) $value
            );

        return $value !== ''
            ? $value
            : null;
    }

    private function date(
        mixed $value
    ): ?string {
        $value =
            $this->text($value);

        if ($value === null) {
            return null;
        }

        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $value
            )
        ) {
            return $value;
        }

        $time =
            strtotime($value);

        if ($time === false) {
            return null;
        }

        return date(
            'Y-m-d',
            $time
        );
    }
}
