<?php
return [
    'access_token' => env('META_WHATSAPP_ACCESS_TOKEN'),
    'phone_number_id' => env('META_WHATSAPP_PHONE_NUMBER_ID'),
    // Select a supported version from the Meta app dashboard before activation.
    'graph_version' => env('META_WHATSAPP_GRAPH_VERSION'),
    'app_secret' => env('META_WHATSAPP_APP_SECRET'),
    'verify_token' => env('META_WHATSAPP_VERIFY_TOKEN'),
    // Add named template mappings only after Meta approval; no arbitrary body fallback.
    // 'appointment_reminder' => ['name'=>'lbs_appointment_reminder', 'language'=>'en', 'parameter_count'=>2],
    'templates' => [],
];
