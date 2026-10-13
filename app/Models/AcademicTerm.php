<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicTerm extends Model
{
    use HasFactory;

    public const TERMS = ['first' => 'First Semester', 'second' => 'Second Semester', 'summer' => 'Summer'];

    protected $fillable = ['academic_year', 'term', 'starts_on', 'ends_on', 'is_current'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_current' => 'boolean'];
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }

    public function label(): string
    {
        return 'AY '.$this->academic_year.', '.(self::TERMS[$this->term] ?? $this->term);
    }
}
