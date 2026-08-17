<?php
return [
    'build' => env('NURSELINK_BUILD', 'NL-013-ultahost-pilot'),
    'release' => env('NURSELINK_RELEASE', '1.0.0'),
    'environment_label' => env('NURSELINK_ENVIRONMENT_LABEL', env('APP_ENV', 'production')),
    'ready_checks' => ['database','cache','queue','private_storage'],
    'backup' => [
        'target_rpo_minutes' => (int) env('NURSELINK_TARGET_RPO_MINUTES', 1440),
        'target_rto_minutes' => (int) env('NURSELINK_TARGET_RTO_MINUTES', 240),
        'manifest_disk' => env('NURSELINK_BACKUP_MANIFEST_DISK', 'private'),
        'root' => env('NURSELINK_BACKUP_ROOT'),
    ],
    'monitoring' => [
        'web_url' => env('NURSELINK_MONITOR_WEB_URL', 'https://app.amsertech.com/'),
        'readiness_url' => env('NURSELINK_MONITOR_READY_URL', 'https://api.amsertech.com/api/health/ready'),
        'alert_email' => env('NURSELINK_MONITOR_ALERT_EMAIL', 'franklin.resma@gmail.com'),
        'maximum_backup_age_hours' => (int) env('NURSELINK_MONITOR_BACKUP_MAX_HOURS', 26),
        'minimum_disk_free_percent' => (float) env('NURSELINK_MONITOR_DISK_FREE_PERCENT', 15),
        'queue_lag_minutes' => (int) env('NURSELINK_MONITOR_QUEUE_LAG_MINUTES', 15),
        'http_attempts' => max(1, (int) env('NURSELINK_MONITOR_HTTP_ATTEMPTS', 3)),
        'http_retry_delay_ms' => max(0, (int) env('NURSELINK_MONITOR_HTTP_RETRY_DELAY_MS', 750)),
        'http_timeout_seconds' => max(1, (int) env('NURSELINK_MONITOR_HTTP_TIMEOUT_SECONDS', 10)),
    ],
    'security_headers_policy_path' => env('NURSELINK_SECURITY_HEADERS_POLICY_PATH'),
    'security_headers_policy_marker' => env(
        'NURSELINK_SECURITY_HEADERS_POLICY_MARKER',
        'NURSELINK_SECURITY_HEADERS_V330_START'
    ),
    'audit_retention_days' => (int) env('NURSELINK_AUDIT_RETENTION_DAYS', 2555),
];
