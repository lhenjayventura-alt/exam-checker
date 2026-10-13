<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Exam extends Model
{
    use HasFactory;

    public const TYPES = [
        'quiz' => 'Quiz', 'long_exam' => 'Long Exam', 'prelim' => 'Preliminary',
        'midterm' => 'Midterm', 'prefinal' => 'Pre-final', 'final' => 'Final', 'other' => 'Other',
    ];

    public const STATUSES = ['draft', 'published', 'closed', 'archived'];

    protected $fillable = [
        'course_offering_id', 'title', 'instructions', 'exam_type', 'exam_date',
        'passing_percentage', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['exam_date' => 'date', 'passing_percentage' => 'float'];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ExamVersion::class);
    }

    /** The newest version; questions are editable only while it is unlocked. */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(ExamVersion::class)->ofMany('version_number', 'max');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function scanUploads(): HasMany
    {
        return $this->hasMany(ScanUpload::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function acceptsSubmissions(): bool
    {
        return $this->status === 'published';
    }
}
