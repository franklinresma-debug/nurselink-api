<?php
return [
    'request_types' => ['access_export','correction','restriction','account_closure'],
    'export_disk' => env('NURSELINK_PRIVACY_EXPORT_DISK', 'private'),
    'export_retention_days' => (int) env('NURSELINK_PRIVACY_EXPORT_RETENTION_DAYS', 7),
    'default_request_target_days' => (int) env('NURSELINK_PRIVACY_REQUEST_TARGET_DAYS', 15),
    'sensitive_fields' => ['date_of_birth','mobile_phone','credential_number'],
];
