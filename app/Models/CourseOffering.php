<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseOffering extends Model
{
    use HasFactory;

    protected $fillable = ['course_id', 'academic_term_id', 'section'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'teaching_assignments')->withTimestamps();
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'enrollments')->withTimestamps();
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    public function isTaughtBy(User $user): bool
    {
        return $this->teachers()->whereKey($user->id)->exists();
    }

    public function isInProgramChairedBy(User $user): bool
    {
        return in_array($this->course->program_id, $user->chairedProgramIds(), true);
    }

    /** Offerings the user may view: assigned teacher, chair of the course's program, or administrator. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $programIds = $user->chairedProgramIds();

        return $query->where(function (Builder $q) use ($user, $programIds) {
            $q->whereHas('teachers', fn (Builder $t) => $t->whereKey($user->id));
            if ($programIds !== []) {
                $q->orWhereHas('course', fn (Builder $c) => $c->whereIn('program_id', $programIds));
            }
        });
    }

    public function label(): string
    {
        return $this->course->code.' ('.$this->section.')';
    }
}
