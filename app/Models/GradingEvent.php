<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradingEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'grading_result_id', 'user_id', 'event', 'previous_score', 'new_score',
        'previous_answer', 'new_answer', 'reason',
    ];

    protected function casts(): array
    {
        return ['previous_score' => 'float', 'new_score' => 'float', 'created_at' => 'datetime'];
    }

    public function gradingResult(): BelongsTo
    {
        return $this->belongsTo(GradingResult::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
