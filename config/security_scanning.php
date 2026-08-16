<?php

return [
    'driver' => env('NURSELINK_MALWARE_SCANNER', 'clamav'),
    'clamav' => [
        'socket' => env('CLAMAV_SOCKET'),
        'host' => env('CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout_seconds' => (int) env('CLAMAV_TIMEOUT_SECONDS', 30),
        'chunk_bytes' => (int) env('CLAMAV_CHUNK_BYTES', 1048576),
    ],
];
