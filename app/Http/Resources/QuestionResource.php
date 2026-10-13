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
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn ($o) => [
                'label' => $o->label,
                'text' => $o->text,
            ])),
            // Present only when the controller loaded the key for an authorized user.
            'answer_key' => $this->whenLoaded('answerKey', fn () => $this->answerKey?->correct_values),
        ];
    }
}
