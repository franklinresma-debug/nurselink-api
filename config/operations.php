<?php
return [
    'release' => env('NURSELINK_RELEASE', '1.0.0'),
    'environment_label' => env('NURSELINK_ENVIRONMENT_LABEL', env('APP_ENV', 'production')),
    'ready_checks' => ['database','cache','queue','private_storage'],
    'backup' => [
        'target_rpo_minutes' => (int) env('NURSELINK_TARGET_RPO_MINUTES', 1440),
        'target_rto_minutes' => (int) env('NURSELINK_TARGET_RTO_MINUTES', 240),
        'manifest_disk' => env('NURSELINK_BACKUP_MANIFEST_DISK', 'private'),
        'root' => env('NURSELINK_BACKUP_ROOT'),
    ],
    'security_headers_policy_path' => env('NURSELINK_SECURITY_HEADERS_POLICY_PATH'),
    'security_headers_policy_marker' => env(
        'NURSELINK_SECURITY_HEADERS_POLICY_MARKER',
        'NURSELINK_SECURITY_HEADERS_V330_START'
    ),
    'audit_retention_days' => (int) env('NURSELINK_AUDIT_RETENTION_DAYS', 2555),
];
