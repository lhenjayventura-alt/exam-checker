<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnswerKey extends Model
{
    protected $fillable = ['question_id', 'correct_values'];

    // Never serialize the key into API payloads by accident.
    protected $hidden = ['correct_values'];

    protected function casts(): array
    {
        return ['correct_values' => 'array'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
