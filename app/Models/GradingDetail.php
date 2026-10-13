<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Criterion-level score. The row without a rubric criterion is the automatically
 * checked final answer; auto_score keeps the machine's value when a teacher changes score.
 */
class GradingDetail extends Model
{
    protected $fillable = ['grading_result_id', 'rubric_criterion_id', 'label', 'max_points', 'auto_score', 'score', 'comment'];

    protected function casts(): array
    {
        return ['max_points' => 'float', 'auto_score' => 'float', 'score' => 'float'];
    }

    public function gradingResult(): BelongsTo
    {
        return $this->belongsTo(GradingResult::class);
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class, 'rubric_criterion_id');
    }

    public function isFinalAnswer(): bool
    {
        return $this->rubric_criterion_id === null;
    }
}
