<?php

namespace App\Http\Requests;

use App\Models\Question;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Multiple choice: options [{label, text}], correct [labels], optional multiple_select / partial_credit.
 * True or false: correct ["true"] or ["false"].
 */
class StoreQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is enforced by policies in the controller.
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->options)) {
            // Drop completely empty option rows submitted by the web form.
            $options = array_values(array_filter($this->options, fn ($o) => is_array($o) && trim((string) ($o['label'] ?? '')) !== ''));
            $this->merge(['options' => array_map(fn ($o) => [...$o, 'label' => strtoupper(trim($o['label']))], $options)]);
        }
        if (is_string($this->correct)) {
            $this->merge(['correct' => [$this->correct]]);
        }
        $this->merge(['multiple_select' => $this->boolean('multiple_select')]);
    }

    public function rules(): array
    {
        $max = config('exam_checker.max_options', 10);

        return [
            'number' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'type' => ['required', Rule::in(Question::SUPPORTED_TYPES)],
            'prompt' => ['nullable', 'string', 'max:5000'],
            'points' => ['required', 'numeric', 'min:0.01', 'max:1000'],
            'options' => ['exclude_unless:type,multiple_choice', 'required', 'array', 'min:2', "max:{$max}"],
            'options.*.label' => ['exclude_unless:type,multiple_choice', 'required', 'string', 'regex:/^[A-Z]$/', 'distinct'],
            'options.*.text' => ['exclude_unless:type,multiple_choice', 'nullable', 'string', 'max:1000'],
            'correct' => ['required', 'array', 'min:1'],
            'correct.*' => ['required', 'string', 'max:10'],
            'multiple_select' => ['boolean'],
            'partial_credit' => ['exclude_unless:type,multiple_choice', 'nullable', Rule::in(['none', 'proportional'])],
        ];
    }

    public function messages(): array
    {
        return [
            'options.*.label.regex' => 'Option labels must be single letters (A–Z).',
            'correct.required' => 'Select the correct answer.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $correct = $this->input('correct', []);

            if ($this->input('type') === 'true_false') {
                if (count($correct) !== 1 || ! in_array(strtolower($correct[0]), ['true', 'false'], true)) {
                    $validator->errors()->add('correct', 'The answer key must be either true or false.');
                }

                return;
            }

            $labels = array_column($this->input('options', []), 'label');
            $correct = array_unique(array_map('strtoupper', $correct));
            if (array_diff($correct, $labels) !== []) {
                $validator->errors()->add('correct', 'The correct answer must be one of the options.');
            }
            if (! $this->boolean('multiple_select') && count($correct) !== 1) {
                $validator->errors()->add('correct', 'A single-answer question must have exactly one correct option.');
            }
        }];
    }
}
