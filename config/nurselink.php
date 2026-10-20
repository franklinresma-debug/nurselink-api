<?php

return [
    'application_delay_alert_hours' => env('NURSELINK_APPLICATION_DELAY_ALERT_HOURS',24),
    'applicant_access_without_approval' => env('NURSELINK_APPLICANT_ACCESS_WITHOUT_APPROVAL', true),
    // Temporary applicant testing switch; verified timestamps remain unchanged.
    'applicant_email_verification_required' => env('NURSELINK_APPLICANT_EMAIL_VERIFICATION_REQUIRED', false),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    'audit_ip_hash_key' => env('AUDIT_IP_HASH_KEY'),
];
