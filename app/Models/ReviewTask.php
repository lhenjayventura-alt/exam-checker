<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewTask extends Model
{
    protected $fillable = ['grading_result_id', 'reason', 'status', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function gradingResult(): BelongsTo
    {
        return $this->belongsTo(GradingResult::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Open tasks on submissions for offerings the user teaches. */
    public function scopeOpenForTeacher(Builder $query, User $user): Builder
    {
        return $query->where('status', 'open')
            ->whereHas('gradingResult.submission.exam.offering.teachers', fn (Builder $q) => $q->whereKey($user->id));
    }
}
