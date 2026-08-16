<?php
return [
    'default_currency' => env('NURSELINK_ORG_CURRENCY', 'PHP'),
    'private_disk' => env('NURSELINK_PRIVATE_DISK', 'private'),
    'max_document_kb' => 15360,
    'initiative_types' => ['program','project','advocacy'],
    'initiative_statuses' => ['proposed','planning','active','on_hold','completed','cancelled'],
    'policy_statuses' => ['proposed','research','drafting','consultation','submitted','deliberation','adopted','rejected','on_hold'],
    'policy_transitions' => [
        'proposed' => ['research','on_hold'],
        'research' => ['drafting','on_hold'],
        'drafting' => ['consultation','on_hold'],
        'consultation' => ['drafting','submitted','on_hold'],
        'submitted' => ['deliberation','on_hold'],
        'deliberation' => ['adopted','rejected','on_hold'],
        'on_hold' => ['research','drafting','consultation','submitted','deliberation'],
        'adopted' => [],
        'rejected' => [],
    ],
];
