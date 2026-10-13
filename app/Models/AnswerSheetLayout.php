<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnswerSheetLayout extends Model
{
    protected $fillable = ['exam_version_id', 'layout', 'page_count'];

    protected function casts(): array
    {
        return ['layout' => 'array'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ExamVersion::class, 'exam_version_id');
    }
}
