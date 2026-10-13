<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RubricCriterion extends Model
{
    protected $table = 'rubric_criteria';

    protected $fillable = ['question_id', 'label', 'description', 'max_points', 'position'];

    protected function casts(): array
    {
        return ['max_points' => 'float'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
