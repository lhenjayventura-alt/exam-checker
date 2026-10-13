<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScanUpload extends Model
{
    protected $fillable = [
        'exam_id', 'uploaded_by', 'original_name', 'path', 'mime', 'size', 'sha256',
        'status', 'page_count', 'summary', 'error',
    ];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['summary' => 'array'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(ScanSheet::class)->orderBy('first_page');
    }

    /** Directory on the private disk for this upload's derived images. */
    public function workDirectory(): string
    {
        return 'scans/'.$this->exam_id.'/'.$this->id;
    }
}
