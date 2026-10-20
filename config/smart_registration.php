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
        'engine'=>env('NURSELINK_OCR_ENGINE','paddleocr_v6'),
        'paddle_v6_model_dir'=>env('NURSELINK_PADDLE_V6_MODEL_DIR',storage_path('app/ocr-models/ppocr-v6')),
        'paddle_v6_timeout'=>(int)env('NURSELINK_PADDLE_V6_TIMEOUT',45),
        'tesseract_safety_fallback'=>(bool)env('NURSELINK_TESSERACT_SAFETY_FALLBACK',true),
        'accuracy_enabled'=>(bool) env('NURSELINK_OCR_ACCURACY_ENABLED', true),
        'accuracy_model_dir'=>env('NURSELINK_OCR_ACCURACY_MODEL_DIR', storage_path('app/ocr-models/tessdata-best')),
        'max_pdf_pages'=>(int) env('NURSELINK_OCR_MAX_PDF_PAGES', 5),
        'process_timeout'=>(int) env('NURSELINK_OCR_PROCESS_TIMEOUT', 45),
        'idle_timeout'=>(int) env('NURSELINK_OCR_IDLE_TIMEOUT', 20),
        'language'=>env('NURSELINK_OCR_LANGUAGE', 'eng'),
        'paddle_fallback'=>(bool) env('NURSELINK_PADDLE_FALLBACK_ENABLED', false),
        'paddle_python'=>env('NURSELINK_PADDLE_PYTHON', base_path('.venv-ocr/bin/python')),
        'paddle_script'=>env('NURSELINK_PADDLE_SCRIPT', base_path('scripts/paddle_ocr.py')),
        'paddle_timeout'=>(int) env('NURSELINK_PADDLE_TIMEOUT', 40),
        'paddle_min_chars'=>(int) env('NURSELINK_PADDLE_MIN_CHARS', 80),
    ],
    'confidence'=>['high'=>0.90,'review'=>0.70],
];
