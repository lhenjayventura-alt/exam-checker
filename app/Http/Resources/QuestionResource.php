<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'type' => $this->type,
            'prompt' => $this->prompt,
            'points' => (float) $this->points,
            'multiple_select' => $this->isMultipleSelect(),
            'partial_credit' => $this->type === 'multiple_choice' ? $this->setting('partial_credit', 'none') : null,
            'case_sensitive' => $this->type === 'identification' ? (bool) $this->setting('case_sensitive', false) : null,
            'fuzzy' => $this->type === 'identification' ? (bool) $this->setting('fuzzy', false) : null,
            'units' => $this->type === 'problem_solving' ? $this->setting('units', []) : null,
            'unit_required' => $this->type === 'problem_solving' ? (bool) $this->setting('unit_required', false) : null,
            'answer_points' => $this->type === 'problem_solving' ? $this->answerPoints() : null,
            'rubric' => $this->whenLoaded('rubricCriteria', fn () => $this->rubricCriteria->map(fn ($c) => [
                'id' => $c->id,
                'label' => $c->label,
                'description' => $c->description,
                'max_points' => $c->max_points,
            ])),
            'tolerance' => $this->type === 'problem_solving'
                ? ['value' => (float) $this->setting('tolerance', 0), 'type' => $this->setting('tolerance_type', 'absolute')]
                : null,
            'coding' => $this->type === 'coding' ? [
                'language' => $this->setting('language'),
                'time_limit_ms' => (int) $this->setting('time_limit_ms', 2000),
                'memory_mb' => (int) $this->setting('memory_mb', 128),
                'comparison' => $this->setting('comparison', 'trim'),
                'test_points' => $this->answerPoints(),
            ] : null,
            // Loaded only for users with grading rights (like the answer key).
            'test_cases' => $this->whenLoaded('testCases', fn () => $this->testCases->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'input' => $t->input,
                'expected_output' => $t->expected_output,
                'points' => $t->points,
                'hidden' => $t->is_hidden,
            ])),
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn ($o) => [
                'label' => $o->label,
                'text' => $o->text,
            ])),
            // Present only when the controller loaded the key for an authorized user.
            'answer_key' => $this->whenLoaded('answerKey', fn () => $this->answerKey?->correct_values),
        ];
    }
}
