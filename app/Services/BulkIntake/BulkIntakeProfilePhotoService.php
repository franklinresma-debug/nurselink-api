<?php

namespace App\Services\BulkIntake;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class BulkIntakeProfilePhotoService
{
    private const DISK = 'local';

    public function stageBatch(
        int $batchId
    ): array {
        $candidates = DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where('batch_id', $batchId)
            ->get();

        $staged = 0;

        foreach ($candidates as $candidate) {
            if ($this->stageCandidate(
                (int) $candidate->id
            )) {
                $staged++;
            }
        }

        return [
            'staged' => $staged,
        ];
    }

    public function stageCandidate(
        int $candidateId
    ): bool {
        $candidate = DB::table(
            'nurselink_bulk_intake_candidates'
        )
            ->where('id', $candidateId)
            ->first();

        if (! $candidate) {
            return false;
        }

        $sourceIds = json_decode(
            (string) (
                $candidate->source_file_ids
                ?? '[]'
            ),
            true
        );

        if (! is_array($sourceIds)) {
            return false;
        }

        $files = DB::table(
            'nurselink_bulk_intake_files'
        )
            ->whereIn('id', $sourceIds)
            ->where('document_type', 'cv')
            ->where('extension', 'pdf')
            ->orderBy('id')
            ->get();

        foreach ($files as $file) {
            $photo = $this->extractBestPortrait(
                Storage::disk(self::DISK)->path(
                    $file->storage_path
                )
            );

            if (! $photo) {
                continue;
            }

            $directory =
                'nurselink-bulk-intake-profile-photos/'
                . $candidate->batch_id
                . '/'
                . $candidate->id;

            $filename =
                (string) Str::uuid()
                . '.png';

            $path =
                $directory
                . '/'
                . $filename;

            Storage::disk(self::DISK)->put(
                $path,
                file_get_contents(
                    $photo['path']
                )
            );

            if (
                ! Storage::disk(
                    self::DISK
                )->exists($path)
            ) {
                continue;
            }

            /*
             * Bulk Intake may be executed from CLI or a queue worker
             * running under a different Unix account than PHP-FPM.
             * Ensure the private staged photo remains readable by the
             * web application while staying outside the public web root.
             */
            $absolutePath =
                Storage::disk(
                    self::DISK
                )->path($path);

            @chmod(
                $absolutePath,
                0664
            );

            $currentDirectory =
                dirname(
                    $absolutePath
                );

            $storageRoot =
                Storage::disk(
                    self::DISK
                )->path('');

            while (
                str_starts_with(
                    $currentDirectory,
                    rtrim(
                        $storageRoot,
                        DIRECTORY_SEPARATOR
                    )
                )
                && $currentDirectory
                    !== rtrim(
                        $storageRoot,
                        DIRECTORY_SEPARATOR
                    )
            ) {
                @chmod(
                    $currentDirectory,
                    0775
                );

                $parent =
                    dirname(
                        $currentDirectory
                    );

                if (
                    $parent
                    === $currentDirectory
                ) {
                    break;
                }

                $currentDirectory =
                    $parent;
            }

            $oldPath =
                $candidate->photo_path
                ?? null;

            DB::table(
                'nurselink_bulk_intake_candidates'
            )
                ->where(
                    'id',
                    $candidateId
                )
                ->update([
                    'photo_path' =>
                        $path,
                    'photo_source_file_id' =>
                        (int) $file->id,
                    'photo_status' =>
                        'staged',
                    'photo_confidence' =>
                        $photo['confidence'],
                    'updated_at' =>
                        now(),
                ]);

            if (
                is_string($oldPath)
                && $oldPath !== ''
                && $oldPath !== $path
                && Storage::disk(
                    self::DISK
                )->exists($oldPath)
            ) {
                Storage::disk(
                    self::DISK
                )->delete($oldPath);
            }

            return true;
        }

        return false;
    }

    private function extractBestPortrait(
        string $pdfPath
    ): ?array {
        if (! is_file($pdfPath)) {
            return null;
        }

        $directory =
            sys_get_temp_dir()
            . '/nurselink-photo-'
            . Str::uuid();

        if (! mkdir(
            $directory,
            0700,
            true
        )) {
            return null;
        }

        try {
            $prefix =
                $directory
                . '/img';

            $process = new Process([
                'pdfimages',
                '-png',
                $pdfPath,
                $prefix,
            ]);

            $process->setTimeout(45);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $best = null;
            $bestScore = 0.0;

            foreach (
                glob(
                    $directory
                    . '/img-*.png'
                ) ?: []
                as $image
            ) {
                $info =
                    @getimagesize(
                        $image
                    );

                if (! $info) {
                    continue;
                }

                $width =
                    (int) $info[0];

                $height =
                    (int) $info[1];

                $bytes =
                    (int) filesize(
                        $image
                    );

                /*
                 * Reject icons, masks and tiny decorative objects.
                 */
                if (
                    $width < 160
                    || $height < 160
                    || $bytes < 5000
                ) {
                    continue;
                }

                $ratio =
                    $width
                    / max(
                        1,
                        $height
                    );

                /*
                 * Profile photos are usually square-ish or portrait-ish.
                 */
                if (
                    $ratio < 0.70
                    || $ratio > 1.35
                ) {
                    continue;
                }

                $area =
                    $width
                    * $height;

                $shapeScore =
                    1.0
                    - min(
                        1.0,
                        abs(
                            1.0
                            - $ratio
                        )
                    );

                $sizeScore =
                    min(
                        1.0,
                        $bytes
                        / 60000
                    );

                $areaScore =
                    min(
                        1.0,
                        $area
                        / 100000
                    );

                $score =
                    (
                        $shapeScore
                        * 0.35
                    )
                    + (
                        $sizeScore
                        * 0.45
                    )
                    + (
                        $areaScore
                        * 0.20
                    );

                if (
                    $score
                    > $bestScore
                ) {
                    $bestScore =
                        $score;

                    $best = [
                        'path' =>
                            $image,
                        'width' =>
                            $width,
                        'height' =>
                            $height,
                        'bytes' =>
                            $bytes,
                        'confidence' =>
                            round(
                                min(
                                    0.95,
                                    max(
                                        0.70,
                                        $score
                                    )
                                ),
                                4
                            ),
                    ];
                }
            }

            if (! $best) {
                return null;
            }

            /*
             * Copy outside the temp directory before cleanup.
             */
            $copy =
                tempnam(
                    sys_get_temp_dir(),
                    'nl-photo-'
                );

            if (
                ! $copy
                || ! copy(
                    $best['path'],
                    $copy
                )
            ) {
                return null;
            }

            $best['path'] =
                $copy;

            return $best;

        } finally {
            foreach (
                glob(
                    $directory
                    . '/*'
                ) ?: []
                as $file
            ) {
                @unlink($file);
            }

            @rmdir($directory);
        }
    }
}
