<?php

return [
    /*
    | Recognized answers (e.g. from OCR) whose confidence is below this value are sent to
    | teacher review instead of being graded automatically. Typed digital answers carry no
    | recognition confidence and are not affected.
    */
    'recognition_confidence_threshold' => (float) env('EXAM_RECOGNITION_CONFIDENCE_THRESHOLD', 0.85),

    /* Lifetime of API tokens issued to devices, in days. */
    'api_token_days' => (int) env('EXAM_API_TOKEN_DAYS', 30),

    /* Maximum number of options on a multiple-choice question. */
    'max_options' => 10,

    /*
    | Answer-sheet scanner: a local Python worker (scanner/omr_cli.py, OpenCV). Scans never
    | leave this server. The worker runs in a separate process from queue jobs, never inside
    | a web request.
    */
    'scanner' => [
        'python' => env('SCANNER_PYTHON', base_path(PHP_OS_FAMILY === 'Windows'
            ? 'scanner/.venv/Scripts/python.exe'
            : 'scanner/.venv/bin/python')),
        'script' => base_path('scanner/omr_cli.py'),
        'timeout' => (int) env('SCANNER_TIMEOUT', 300),
        'max_upload_kb' => (int) env('SCANNER_MAX_UPLOAD_KB', 20480),
        'disk' => 'local', // private disk: storage/app/private
    ],
];
