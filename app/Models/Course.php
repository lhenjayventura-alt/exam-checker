<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    protected $fillable = ['program_id', 'code', 'title'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }
}
