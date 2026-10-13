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

    /** Types that can be created in the current release (each has a grader). */
    public const SUPPORTED_TYPES = ['multiple_choice', 'true_false', 'identification', 'problem_solving', 'coding'];

    /** Types answered on a paper bubble sheet or write-in box (coding answers are source code). */
    public const PAPER_TYPES = ['multiple_choice', 'true_false', 'identification', 'problem_solving'];

    public const LANGUAGES = ['c' => 'C (gcc, C11)', 'python' => 'Python 3'];

    public const OUTPUT_COMPARISONS = [
        'trim' => 'Ignore trailing spaces and blank lines',
        'tokens' => 'Ignore all whitespace differences',
        'exact' => 'Exact match',
    ];

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

    public function rubricCriteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class)->orderBy('position');
    }

    public function testCases(): HasMany
    {
        return $this->hasMany(CodingTestCase::class)->orderBy('position');
    }

    /**
     * Automatically scored points: the final answer of a problem-solving question, or the
     * test cases of a coding question. The rest comes from rubric criteria.
     */
    public function answerPoints(): float
    {
        return in_array($this->type, ['problem_solving', 'coding'], true)
            ? (float) $this->setting('answer_points', $this->points)
            : (float) $this->points;
    }

    /** True when scoring involves partial credit, so difficulty is a normalized mean score. */
    public function allowsPartialCredit(): bool
    {
        return in_array($this->type, ['problem_solving', 'coding'], true)
            || ($this->isMultipleSelect() && $this->setting('partial_credit') === 'proportional');
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
