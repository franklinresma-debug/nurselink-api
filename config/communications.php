<?php
return [
    'mandatory_categories' => ['security','account','membership_status','event_service'],
    'broadcast_categories' => ['credentials','qualifications','events','programs','community'],
    'default_channels' => ['in_app'=>true,'email'=>true,'sms'=>false,'push'=>false,'whatsapp'=>false],
    'external_providers' => [
        'email' => env('NURSELINK_EMAIL_PROVIDER','laravel_mail'),
        'sms' => env('NURSELINK_SMS_PROVIDER','unconfigured'),
        'push' => env('NURSELINK_PUSH_PROVIDER','unconfigured'),
        'whatsapp' => env('NURSELINK_WHATSAPP_PROVIDER','unconfigured'),
    ],
    'campaign_batch_size' => (int) env('NURSELINK_CAMPAIGN_BATCH_SIZE', 250),
    'event_reminder_days' => [7,1],
    'categories' => ['security','account','membership_status','event_service','credentials','qualifications','events','programs','community'],
];
