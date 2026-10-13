<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Student extends Model
{
    use HasFactory;

    protected $fillable = ['student_number', 'last_name', 'first_name', 'middle_name', 'program_id'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function offerings(): BelongsToMany
    {
        return $this->belongsToMany(CourseOffering::class, 'enrollments')->withTimestamps();
    }

    public function fullName(): string
    {
        $middle = $this->middle_name ? ' '.mb_substr($this->middle_name, 0, 1).'.' : '';

        return $this->last_name.', '.$this->first_name.$middle;
    }
}
