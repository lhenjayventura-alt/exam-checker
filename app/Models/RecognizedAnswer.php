<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecognizedAnswer extends Model
{
    protected $fillable = [
        'submission_id', 'question_id', 'source', 'raw_value', 'normalized_value',
        'confidence', 'corrected_value', 'corrected_by', 'corrected_at',
    ];

    protected function casts(): array
    {
        return ['confidence' => 'float', 'corrected_at' => 'datetime'];
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

    public function isCorrected(): bool
    {
        return $this->corrected_at !== null;
    }
}
