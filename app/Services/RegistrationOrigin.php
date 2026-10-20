<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Registration origin is evidence-based and never inferred from current roles. */
class RegistrationOrigin
{
    public static function unknown(): array
    {
        return ['code' => 'not_recorded', 'label' => 'Not recorded', 'encoded_by' => null, 'registered_at' => null];
    }

    public function map(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        if (!$ids) return [];
        $result = array_fill_keys($ids, self::unknown());
        foreach (DB::table('audit_logs')->where('action', 'auth.registered')
            ->where('object_type', 'user')->whereIn('object_id', $ids)->orderBy('created_at')->get(['object_id', 'created_at']) as $row) {
            if ($result[(string) $row->object_id]['code'] !== 'not_recorded') continue;
            $result[(string) $row->object_id] = ['code' => 'self_registered', 'label' => 'Self-registration', 'encoded_by' => null, 'registered_at' => $row->created_at];
        }
        // The batch creator/encoder is distinct from the administrator approving import.
        $imports = DB::table('nurselink_bulk_intake_candidates as c')
            ->join('nurselink_bulk_intake_batches as b', 'b.id', '=', 'c.batch_id')
            ->leftJoin('temporary_encoder_accounts as e', 'e.id', '=', 'b.encoder_account_id')
            ->leftJoin('users as u', 'u.id', '=', 'b.created_by_user_id')
            ->whereIn('c.imported_user_id', $ids)->orderBy('c.imported_at')
            ->get(['c.imported_user_id', 'c.imported_at', 'b.encoder_account_id', 'b.created_by_user_id', 'e.display_name', 'e.username', 'u.name']);
        foreach ($imports as $row) {
            $id = (string) $row->imported_user_id;
            if ($result[$id]['code'] === 'staff_encoded') continue;
            $name = $row->encoder_account_id ? ($row->display_name ?: $row->username) : $row->name;
            $result[$id] = ['code' => 'staff_encoded', 'label' => 'Encoded by LBS staff', 'encoded_by' => $name ?: 'Encoder not recorded', 'registered_at' => $row->imported_at];
        }
        return $result;
    }
}
