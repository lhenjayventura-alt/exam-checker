<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionFile extends Model
{
    protected $fillable = ['submission_id', 'scan_sheet_id', 'question_id', 'kind', 'page', 'disk', 'path', 'mime', 'size'];

    // Storage paths are never exposed; files are streamed through an authorized route.
    protected $hidden = ['disk', 'path'];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
