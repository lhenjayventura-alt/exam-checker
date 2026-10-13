<?php

namespace App\Services\Grading;

use App\Models\RecognizedAnswer;

/**
 * Decides whether a recognized answer is reliable enough to grade automatically.
 * This is about reading the answer (OCR), and is kept separate from grading confidence.
 */
class RecognitionConfidenceService
{
    public function threshold(): float
    {
        return (float) config('exam_checker.recognition_confidence_threshold', 0.85);
    }

    public function reviewReason(?RecognizedAnswer $answer): ?string
    {
        if ($answer?->needsTranscription()) {
            return 'Handwritten answer: transcribe it from the scanned image.';
        }

        // Typed digital answers and teacher corrections carry no recognition uncertainty.
        if ($answer === null || $answer->confidence === null || $answer->isCorrected()) {
            return null;
        }

        if ($answer->confidence < $this->threshold()) {
            return sprintf(
                'Low recognition confidence (%.2f, threshold %.2f).',
                $answer->confidence,
                $this->threshold(),
            );
        }

        return null;
    }
}
