<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanSheet extends Model
{
    /** Statuses that still need a teacher's action. */
    public const NEEDS_ACTION = ['needs_student', 'duplicate', 'unreadable', 'wrong_exam'];

    protected $fillable = [
        'scan_upload_id', 'answer_sheet_layout_id', 'first_page', 'page_count', 'status', 'message',
        'qr_student_id', 'student_id', 'submission_id', 'recognition', 'page_images',
    ];

    protected function casts(): array
    {
        return ['recognition' => 'array', 'page_images' => 'array'];
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(ScanUpload::class, 'scan_upload_id');
    }

    public function layout(): BelongsTo
    {
        return $this->belongsTo(AnswerSheetLayout::class, 'answer_sheet_layout_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function canBeAssigned(): bool
    {
        return in_array($this->status, ['needs_student', 'duplicate'], true)
            && $this->answer_sheet_layout_id !== null
            && $this->recognition !== null;
    }
}
