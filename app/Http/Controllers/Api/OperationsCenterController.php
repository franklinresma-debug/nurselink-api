<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class OperationsCenterController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $current = $this->collectHealth();

        return response()->json([
            'data' => [
                'release' => '5.5.8',
                'stage' => 'production',
                'current' => $current,
                'summary' => [
                    'open_incidents' => $this->countOpenIncidents(),
                    'critical_incidents' => $this->countCriticalIncidents(),
                    'snapshots_24h' => $this->countSnapshotsSince(now()->subDay()),
                    'deployments_30d' => $this->countDeploymentsSince(now()->subDays(30)),
                ],
                'history' => $this->snapshotHistory(),
                'incidents' => $this->incidentHistory(),
                'deployments' => $this->deploymentHistory(),
                'thresholds' => [
                    'disk_warning_below_percent' => 15,
                    'backup_warning_after_hours' => 168,
                    'database_warning_above_ms' => 500,
                    'recent_log_errors_warning_above' => 0,
                ],
            ],
            'privacy' => [
                'secrets_exposed' => false,
                'personal_data_exposed' => false,
                'log_messages_exposed' => false,
            ],
        ]);
    }

    public function snapshot(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $health = $this->collectHealth();

        $id = DB::table('nurselink_operations_snapshots')->insertGetId([
            'status' => $health['status'],
            'database_latency_ms' => $health['database_latency_ms'],
            'disk_free_percent' => $health['disk_free_percent'],
            'backup_age_hours' => $health['backup_age_hours'],
            'recent_log_error_count' => $health['recent_log_error_count'],
            'security_headers_ok' => $health['security_headers_ok'],
            'source' => 'admin',
            'created_by' => (string) $request->user()->getKey(),
            'captured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $id, 'snapshot' => $health]], 201);
    }

    public function storeIncident(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'severity' => ['required', Rule::in(['info', 'warning', 'critical'])],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:80'],
        ]);

        $id = DB::table('nurselink_operations_incidents')->insertGetId([
            'severity' => $data['severity'],
            'status' => 'open',
            'title' => trim($data['title']),
            'description' => isset($data['description']) ? trim((string)$data['description']) : null,
            'source' => isset($data['source']) ? trim((string)$data['source']) : 'manual',
            'opened_by' => (string) $request->user()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => DB::table('nurselink_operations_incidents')->where('id', $id)->first(),
        ], 201);
    }

    public function updateIncident(Request $request, int $incident): JsonResponse
    {
        $this->authorizeStaff($request);

        abort_unless(
            DB::table('nurselink_operations_incidents')->where('id', $incident)->exists(),
            404
        );

        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'monitoring', 'resolved'])],
        ]);

        $updates = ['status' => $data['status'], 'updated_at' => now()];

        if ($data['status'] === 'resolved') {
            $updates['resolved_by'] = (string) $request->user()->getKey();
            $updates['resolved_at'] = now();
        } else {
            $updates['resolved_by'] = null;
            $updates['resolved_at'] = null;
        }

        DB::table('nurselink_operations_incidents')->where('id', $incident)->update($updates);

        return response()->json([
            'data' => DB::table('nurselink_operations_incidents')->where('id', $incident)->first(),
        ]);
    }

    private function collectHealth(): array
    {
        $dbMs = $this->databaseLatencyMs();

        $diskFree = @disk_free_space(storage_path());
        $diskTotal = @disk_total_space(storage_path());
        $diskPct = (is_numeric($diskFree) && is_numeric($diskTotal) && (float)$diskTotal > 0)
            ? round(((float)$diskFree / (float)$diskTotal) * 100, 2)
            : null;

        $backupAge = $this->latestBackupAgeHours(
            (string) config('operations.backup.root', '')
        );
        $logErrors = $this->recentLogErrorCount();

        $securityPolicyPath = (string) config('operations.security_headers_policy_path', '');
        $securityPolicyMarker = (string) config(
            'operations.security_headers_policy_marker',
            'NURSELINK_SECURITY_HEADERS_V330_START'
        );
        $securityOk = $securityPolicyPath !== ''
            && is_file($securityPolicyPath)
            && str_contains(
                (string) @file_get_contents($securityPolicyPath),
                $securityPolicyMarker
            );

        $warnings = [];
        if ($dbMs === null || $dbMs > 500) $warnings[] = 'database_latency';
        if ($diskPct !== null && $diskPct < 15) $warnings[] = 'disk_capacity';
        if ($backupAge === null || $backupAge > 168) $warnings[] = 'backup_age';
        if ($logErrors !== null && $logErrors > 0) $warnings[] = 'recent_server_errors';
        if (!$securityOk) $warnings[] = 'security_headers';

        $status = $warnings === [] ? 'healthy' : 'warning';
        if ($diskPct !== null && $diskPct < 5) $status = 'critical';

        return [
            'status' => $status,
            'database_latency_ms' => $dbMs,
            'disk_free_percent' => $diskPct,
            'backup_age_hours' => $backupAge,
            'recent_log_error_count' => $logErrors,
            'security_headers_ok' => $securityOk,
            'warning_keys' => $warnings,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function snapshotHistory(): array
    {
        if (!Schema::hasTable('nurselink_operations_snapshots')) return [];

        return DB::table('nurselink_operations_snapshots')
            ->select(['id','status','database_latency_ms','disk_free_percent','backup_age_hours','recent_log_error_count','security_headers_ok','source','captured_at'])
            ->orderByDesc('captured_at')->limit(60)->get()->all();
    }

    private function incidentHistory(): array
    {
        if (!Schema::hasTable('nurselink_operations_incidents')) return [];

        return DB::table('nurselink_operations_incidents')
            ->select(['id','severity','status','title','description','source','resolved_at','created_at','updated_at'])
            ->orderByRaw("CASE WHEN status = 'resolved' THEN 1 ELSE 0 END")
            ->orderByDesc('created_at')->limit(80)->get()->all();
    }

    private function deploymentHistory(): array
    {
        if (!Schema::hasTable('nurselink_deployments')) return [];

        return DB::table('nurselink_deployments')
            ->select(['id','release','stage','backup_label','source','notes','deployed_at'])
            ->orderByDesc('deployed_at')->limit(40)->get()->all();
    }

    private function authorizeStaff(Request $request): void
    {
        $user = $request->user();
        abort_unless($user, 401);

        $access = DB::table('nurselink_reviewer_access')
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->whereIn('role', ['reviewer', 'admin'])
            ->first();

        $modelRole = strtolower((string)($user->role ?? $user->user_role ?? $user->user_type ?? ''));
        $modelAdmin = (bool)($user->is_admin ?? $user->is_super_admin ?? false);

        abort_unless(
            $access || $modelAdmin || in_array($modelRole, ['admin','administrator','super_admin'], true),
            403,
            'NurseLink reviewer or administrator access is required.'
        );
    }

    private function databaseLatencyMs(): ?float
    {
        try {
            $started = microtime(true);
            DB::select('select 1');
            return round((microtime(true) - $started) * 1000, 2);
        } catch (\Throwable) {
            return null;
        }
    }

    private function latestBackupAgeHours(string $root): ?float
    {
        if (!is_dir($root)) return null;
        $latest = null;

        foreach (glob(rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            $mtime = @filemtime($path);
            if ($mtime !== false && ($latest === null || $mtime > $latest)) $latest = $mtime;
        }

        return $latest === null ? null : round(max(0, time() - $latest) / 3600, 2);
    }

    private function recentLogErrorCount(): ?int
    {
        $files = glob(storage_path('logs/*.log')) ?: [];
        if ($files === []) return 0;

        usort($files, static fn(string $a, string $b): int => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));
        $file = $files[0];
        $size = @filesize($file);
        if ($size === false) return null;

        $bytes = min((int)$size, 262144);
        $handle = @fopen($file, 'rb');
        if (!$handle) return null;

        if ($bytes > 0) @fseek($handle, -$bytes, SEEK_END);
        $tail = (string)stream_get_contents($handle);
        fclose($handle);

        preg_match_all('/\.(ERROR|CRITICAL|ALERT|EMERGENCY):/i', $tail, $matches);
        return count($matches[0] ?? []);
    }

    private function countOpenIncidents(): int
    {
        if (!Schema::hasTable('nurselink_operations_incidents')) return 0;
        return DB::table('nurselink_operations_incidents')->where('status', '!=', 'resolved')->count();
    }

    private function countCriticalIncidents(): int
    {
        if (!Schema::hasTable('nurselink_operations_incidents')) return 0;
        return DB::table('nurselink_operations_incidents')
            ->where('status', '!=', 'resolved')->where('severity', 'critical')->count();
    }

    private function countSnapshotsSince($when): int
    {
        if (!Schema::hasTable('nurselink_operations_snapshots')) return 0;
        return DB::table('nurselink_operations_snapshots')->where('captured_at', '>=', $when)->count();
    }

    private function countDeploymentsSince($when): int
    {
        if (!Schema::hasTable('nurselink_deployments')) return 0;
        return DB::table('nurselink_deployments')->where('deployed_at', '>=', $when)->count();
    }
}
