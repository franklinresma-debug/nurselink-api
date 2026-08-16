<?php
return [
    'max_document_mb'=>env('NURSELINK_MAX_DOCUMENT_MB',15),
    'private_disk'=>env('NURSELINK_PRIVATE_DISK','private'),
    'extractor'=>env('NURSELINK_DOCUMENT_EXTRACTOR','manual_review'),
    'confidence'=>['high'=>0.90,'review'=>0.70],
];
