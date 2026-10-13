<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Submission extends Model
{
    public const STATUSES = ['pending', 'processing', 'graded', 'needs_review', 'finalized', 'failed'];

    protected $fillable = [
        'exam_id', 'exam_version_id', 'student_id', 'source', 'status',
        'failure_reason', 'submitted_by', 'graded_at',
    ];

    protected function casts(): array
    {
        return ['graded_at' => 'datetime'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ExamVersion::class, 'exam_version_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(RecognizedAnswer::class);
    }

    public function gradingResults(): HasMany
    {
        return $this->hasMany(GradingResult::class);
    }

    public function reviewTasks(): HasManyThrough
    {
        return $this->hasManyThrough(ReviewTask::class, GradingResult::class);
    }

    public function result(): HasOne
    {
        return $this->hasOne(ExamResult::class);
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }
}
