<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Question extends Model
{
    public const TYPES = [
        'multiple_choice' => 'Multiple Choice',
        'true_false' => 'True or False',
        'identification' => 'Identification',
        'problem_solving' => 'Problem Solving',
        'coding' => 'Coding',
    ];

    /** Types that can be created in the current release (each has an automatic grader). */
    public const SUPPORTED_TYPES = ['multiple_choice', 'true_false'];

    protected $fillable = ['exam_version_id', 'number', 'type', 'prompt', 'points', 'config'];

    protected function casts(): array
    {
        return ['points' => 'float', 'config' => 'array'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ExamVersion::class, 'exam_version_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    public function answerKey(): HasOne
    {
        return $this->hasOne(AnswerKey::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function isMultipleSelect(): bool
    {
        return $this->type === 'multiple_choice' && (bool) $this->setting('multiple_select', false);
    }
}
