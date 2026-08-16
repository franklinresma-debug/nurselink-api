<?php
return [
    'renewal_thresholds_days' => [90, 60, 30],
    'private_disk' => env('NURSELINK_PRIVATE_DISK', 'private'),
    'max_upload_kb' => 15360,
    'external_delivery_enabled' => false, // NL-008 will attach email/SMS/push delivery adapters.
];
