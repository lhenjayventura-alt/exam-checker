<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamVersion extends Model
{
    protected $fillable = ['exam_id', 'version_number', 'locked_at', 'locked_by'];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('number');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function totalPoints(): float
    {
        return (float) $this->questions()->sum('points');
    }
}
