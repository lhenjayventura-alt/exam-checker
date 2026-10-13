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
];
