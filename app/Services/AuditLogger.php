<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    public function write(string $action, ?User $actor = null, ?string $objectType = null, ?string $objectId = null, array $metadata = [], ?Request $request = null): void
    {
        $ipHash = null;
        $ip = $request?->ip();
        $key = config('nurselink.audit_ip_hash_key');

        if ($ip && $key) {
            $ipHash = hash_hmac('sha256', $ip, $key);
        }

        AuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'metadata' => $metadata,
            'ip_hash' => $ipHash,
        ]);
    }
}
