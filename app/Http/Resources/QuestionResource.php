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
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn ($o) => [
                'label' => $o->label,
                'text' => $o->text,
            ])),
            // Present only when the controller loaded the key for an authorized user.
            'answer_key' => $this->whenLoaded('answerKey', fn () => $this->answerKey?->correct_values),
        ];
    }
}
