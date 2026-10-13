<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GradingResult extends Model
{
    protected $fillable = [
        'submission_id', 'question_id', 'grader', 'max_points', 'proposed_score',
        'awarded_score', 'is_correct', 'explanation', 'status', 'feedback',
    ];

    protected function casts(): array
    {
        return [
            'max_points' => 'float',
            'proposed_score' => 'float',
            'awarded_score' => 'float',
            'is_correct' => 'boolean',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function reviewTasks(): HasMany
    {
        return $this->hasMany(ReviewTask::class);
    }

    public function openReviewTask(): HasOne
    {
        return $this->hasOne(ReviewTask::class)->where('status', 'open');
    }

    public function details(): HasMany
    {
        // Final answer (no criterion) first, then criteria in rubric order.
        return $this->hasMany(GradingDetail::class)->orderByRaw('rubric_criterion_id is not null')->orderBy('rubric_criterion_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(CodeExecutionResult::class)->orderByRaw("case when step = 'compile' then 0 else 1 end")->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GradingEvent::class)->orderBy('id');
    }

    /** True once a teacher has made a decision that automatic re-grading must not overwrite. */
    public function isTeacherDecided(): bool
    {
        return in_array($this->status, ['reviewed', 'overridden'], true);
    }
}
