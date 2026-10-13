<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodingTestCase extends Model
{
    protected $fillable = ['question_id', 'name', 'input', 'expected_output', 'points', 'is_hidden', 'position'];

    // Expected output and input of hidden tests must never leak through serialization.
    protected $hidden = ['input', 'expected_output'];

    protected function casts(): array
    {
        return ['points' => 'float', 'is_hidden' => 'boolean'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
