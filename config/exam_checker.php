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

    /*
    | Coding questions: student code runs only in throwaway Docker containers (see
    | DockerCodeRunner for the isolation flags). Build the image with:
    |   docker build -t exam-checker-runner:1 runner/
    | Set DOCKER_HOST in the queue worker's environment to execute on a separate machine.
    */
    'code_runner' => [
        'docker' => env('CODE_RUNNER_DOCKER', 'docker'),
        'image' => env('CODE_RUNNER_IMAGE', 'exam-checker-runner:1'),
        'work_root' => storage_path('app/private/code-runs'),
        'max_source_kb' => 64,
    ],
];
