<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Student extends Model
{
    use HasFactory;

    protected $fillable = ['student_number', 'last_name', 'first_name', 'middle_name', 'program_id'];

    /** Canonical form so lookups behave the same on every database collation. */
    public static function canonicalNumber(?string $number): string
    {
        return strtoupper(trim((string) $number));
    }

    protected function studentNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => self::canonicalNumber($value));
    }

    public function scopeWithNumber(Builder $query, ?string $number): Builder
    {
        return $query->where('student_number', self::canonicalNumber($number));
    }

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
