<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class IdentifierService
{
    public function next(string $type, string $prefix): string
    {
        $year = (int) now()->format('Y');

        return DB::transaction(function () use ($type, $prefix, $year) {
            $row = DB::table('identifier_counters')
                ->where('type', $type)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                DB::table('identifier_counters')->insert(['type' => $type, 'year' => $year, 'last_value' => 1]);
                $value = 1;
            } else {
                $value = ((int) $row->last_value) + 1;
                DB::table('identifier_counters')->where('type', $type)->where('year', $year)->update(['last_value' => $value]);
            }

            return sprintf('%s-%d-%06d', $prefix, $year, $value);
        }, 3);
    }
}
