<?php

return [
    'mailbox' => env('NURSELINK_MAILBOX_ADDRESS', 'privacy@lbs-nurselink.local'),
    'allowed_admins' => array_values(array_filter(array_map('trim', explode(',', env('NURSELINK_EMAIL_ADMINS', 'dev-admin@lbs-nurselink.local,dev-admin@lbs-nurselink.local'))))),
    'max_attachment_kb' => (int) env('NURSELINK_EMAIL_ATTACHMENT_MAX_KB', 10240),
    'retention_years' => (int) env('NURSELINK_EMAIL_RETENTION_YEARS', 7),
    'sync_enabled' => (bool) env('NURSELINK_EMAIL_SYNC_ENABLED', false),
    'imap' => [
        'host' => env('MAILBOX_IMAP_HOST'),
        'port' => (int) env('MAILBOX_IMAP_PORT', 993),
        'encryption' => env('MAILBOX_IMAP_ENCRYPTION', 'ssl'),
        'username' => env('MAILBOX_IMAP_USERNAME'),
        'password' => env('MAILBOX_IMAP_PASSWORD'),
    ],
];
