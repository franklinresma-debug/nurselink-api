<?php
return [
    'cache_seconds' => (int) env('NURSELINK_ANALYTICS_CACHE_SECONDS', 300),
    'snapshot_retention_days' => (int) env('NURSELINK_ANALYTICS_SNAPSHOT_RETENTION_DAYS', 730),
    'export_disk' => env('NURSELINK_REPORT_EXPORT_DISK', 'private'),
    'export_retention_days' => (int) env('NURSELINK_REPORT_EXPORT_RETENTION_DAYS', 14),
    'minimum_group_size' => (int) env('NURSELINK_ANALYTICS_MINIMUM_GROUP_SIZE', 5),
    'metric_version' => '1.0',
];
