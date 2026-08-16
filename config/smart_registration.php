<?php
return [
    'max_document_mb'=>env('NURSELINK_MAX_DOCUMENT_MB',15),
    'private_disk'=>env('NURSELINK_PRIVATE_DISK','private'),
    'extractor'=>env('NURSELINK_DOCUMENT_EXTRACTOR','local_ocr'),
    'queue'=>[
        'enabled'=>(bool) env('NURSELINK_OCR_QUEUE_ENABLED', true),
        'name'=>env('NURSELINK_OCR_QUEUE', 'document-extraction'),
    ],
    'ocr'=>[
        'max_pdf_pages'=>(int) env('NURSELINK_OCR_MAX_PDF_PAGES', 5),
        'process_timeout'=>(int) env('NURSELINK_OCR_PROCESS_TIMEOUT', 45),
        'idle_timeout'=>(int) env('NURSELINK_OCR_IDLE_TIMEOUT', 20),
        'language'=>env('NURSELINK_OCR_LANGUAGE', 'eng'),
    ],
    'confidence'=>['high'=>0.90,'review'=>0.70],
];
