<?php

return [
    'mailbox' => env('NURSELINK_MAILBOX_ADDRESS', 'nurselink@amsertech.com'),
    'allowed_admins' => array_values(array_filter(array_map('trim', explode(',', env('NURSELINK_EMAIL_ADMINS', 'franklin.resma@gmail.com,frank@amsertech.com'))))),
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
