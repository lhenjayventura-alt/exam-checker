<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecognizedAnswer extends Model
{
    protected $fillable = [
        'submission_id', 'question_id', 'source', 'raw_value', 'normalized_value',
        'confidence', 'recognition_meta', 'corrected_value', 'corrected_by', 'corrected_at',
    ];

    protected function casts(): array
    {
        return ['confidence' => 'float', 'recognition_meta' => 'array', 'corrected_at' => 'datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** The answer used for grading: the teacher's correction when present, otherwise the recognized value. */
    public function effectiveValue(): ?string
    {
        return $this->isCorrected() ? $this->corrected_value : $this->normalized_value;
    }

    /** Source code of a coding answer: the teacher-provided code when present, otherwise as submitted. */
    public function code(): ?string
    {
        $code = $this->isCorrected() ? $this->corrected_value : $this->raw_value;

        return $code === null || trim($code) === '' ? null : $code;
    }

    /** A handwritten answer the scanner cropped but cannot read; the teacher transcribes it. */
    public function needsTranscription(): bool
    {
        return ! $this->isCorrected()
            && ($this->recognition_meta['written'] ?? false)
            && ! ($this->recognition_meta['blank'] ?? false);
    }

    public function isCorrected(): bool
    {
        return $this->corrected_at !== null;
    }
}
