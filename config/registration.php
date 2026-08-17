<?php

$pilotEmails = array_values(array_filter(array_map(
    static fn (string $email): string => mb_strtolower(trim($email)),
    explode(',', (string) env('NURSELINK_REGISTRATION_PILOT_EMAILS', ''))
)));

return [
    // open: anyone may register; pilot: only configured emails; closed: no new accounts.
    'mode' => env('NURSELINK_REGISTRATION_MODE', 'open'),
    'pilot_emails' => $pilotEmails,
    'terms_version' => env('NURSELINK_TERMS_VERSION', '2026-08-18'),
    'privacy_version' => env('NURSELINK_PRIVACY_VERSION', '2026-08-18'),
];
