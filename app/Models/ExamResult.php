<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamResult extends Model
{
    protected $fillable = [
        'submission_id', 'exam_id', 'student_id', 'total_score', 'max_score', 'percentage',
        'passed', 'finalized_by', 'finalized_at', 'released_by', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'total_score' => 'float',
            'max_score' => 'float',
            'percentage' => 'float',
            'passed' => 'boolean',
            'finalized_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }
}
