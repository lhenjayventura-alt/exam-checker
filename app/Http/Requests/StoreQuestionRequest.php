<?php

namespace App\Http\Requests;

use App\Models\Question;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Multiple choice: options [{label, text}], correct [labels], optional multiple_select / partial_credit.
 * True or false: correct ["true"] or ["false"].
 * Identification: accepted_answers [strings] (or newline-separated text), optional case_sensitive / fuzzy.
 * Problem solving: expected, optional tolerance + tolerance_type, units [strings], unit_required,
 *   answer_points, criteria [{label, description, max_points}]; answer_points + criteria = points.
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
        // Text inputs: accepted answers one per line (they may contain commas); units by line or comma.
        foreach (['accepted_answers' => '/\r\n|\r|\n/', 'units' => '/\r\n|\r|\n|,/'] as $listField => $separator) {
            $value = $this->input($listField);
            if (is_string($value)) {
                $value = preg_split($separator, $value);
            }
            if (is_array($value)) {
                $this->merge([$listField => array_values(array_filter(array_map(fn ($v) => trim((string) $v), $value), fn ($v) => $v !== ''))]);
            }
        }
        if (is_array($this->criteria)) {
            $this->merge(['criteria' => array_values(array_filter($this->criteria, fn ($c) => is_array($c) && trim((string) ($c['label'] ?? '')) !== ''))]);
        }
        if (is_array($this->tests)) {
            // Drop untouched rows; normalize the hidden flag.
            $tests = array_values(array_filter($this->tests, fn ($t) => is_array($t) && trim((string) ($t['name'] ?? '')) !== ''));
            // Browsers submit textareas with CRLF; programs must see the LF input a terminal gives.
            $lf = fn ($v) => is_string($v) ? str_replace("\r\n", "\n", $v) : $v;
            $this->merge(['tests' => array_map(fn ($t) => [
                ...$t,
                'input' => $lf($t['input'] ?? null),
                'expected_output' => $lf($t['expected_output'] ?? null),
                'hidden' => filter_var($t['hidden'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ], $tests)]);
        }
        foreach (['multiple_select', 'case_sensitive', 'fuzzy', 'unit_required'] as $flag) {
            $this->merge([$flag => $this->boolean($flag)]);
        }
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
            'multiple_select' => ['boolean'],
            'partial_credit' => ['exclude_unless:type,multiple_choice', 'nullable', Rule::in(['none', 'proportional'])],
            'correct' => ['exclude_unless:type,multiple_choice,true_false', 'required', 'array', 'min:1'],
            'correct.*' => ['exclude_unless:type,multiple_choice,true_false', 'required', 'string', 'max:10'],

            'accepted_answers' => ['exclude_unless:type,identification', 'required', 'array', 'min:1', 'max:20'],
            'accepted_answers.*' => ['exclude_unless:type,identification', 'string', 'max:255'],
            'case_sensitive' => ['boolean'],
            'fuzzy' => ['boolean'],

            'expected' => ['exclude_unless:type,problem_solving', 'required', 'numeric'],
            'tolerance' => ['exclude_unless:type,problem_solving', 'nullable', 'numeric', 'min:0'],
            'tolerance_type' => ['exclude_unless:type,problem_solving', 'nullable', Rule::in(['absolute', 'percent'])],
            'units' => ['exclude_unless:type,problem_solving', 'array', 'max:10'],
            'units.*' => ['exclude_unless:type,problem_solving', 'string', 'max:30'],
            'unit_required' => ['boolean'],
            'answer_points' => ['exclude_unless:type,problem_solving', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'criteria' => ['exclude_unless:type,problem_solving,coding', 'array', 'max:10'],
            'criteria.*.label' => ['exclude_unless:type,problem_solving,coding', 'required', 'string', 'max:150'],
            'criteria.*.description' => ['exclude_unless:type,problem_solving,coding', 'nullable', 'string', 'max:1000'],
            'criteria.*.max_points' => ['exclude_unless:type,problem_solving,coding', 'required', 'numeric', 'min:0.01', 'max:1000'],

            'language' => ['exclude_unless:type,coding', 'required', Rule::in(array_keys(Question::LANGUAGES))],
            'time_limit_ms' => ['exclude_unless:type,coding', 'nullable', 'integer', 'min:100', 'max:10000'],
            'memory_mb' => ['exclude_unless:type,coding', 'nullable', 'integer', 'min:32', 'max:512'],
            'comparison' => ['exclude_unless:type,coding', 'nullable', Rule::in(array_keys(Question::OUTPUT_COMPARISONS))],
            'tests' => ['exclude_unless:type,coding', 'required', 'array', 'min:1', 'max:30'],
            'tests.*.name' => ['exclude_unless:type,coding', 'required', 'string', 'max:100'],
            'tests.*.input' => ['exclude_unless:type,coding', 'nullable', 'string', 'max:65536'],
            'tests.*.expected_output' => ['exclude_unless:type,coding', 'nullable', 'string', 'max:65536'],
            'tests.*.points' => ['exclude_unless:type,coding', 'required', 'numeric', 'min:0.01', 'max:1000'],
            'tests.*.hidden' => ['exclude_unless:type,coding', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'options.*.label.regex' => 'Option labels must be single letters (A–Z).',
            'correct.required' => 'Select the correct answer.',
            'accepted_answers.required' => 'Enter at least one accepted answer.',
            'expected.required' => 'Enter the expected numeric answer.',
            'criteria.*.label.required' => 'Each rubric criterion needs a label.',
            'criteria.*.max_points.required' => 'Each rubric criterion needs maximum points.',
            'tests.required' => 'Add at least one test case.',
            'tests.*.name.required' => 'Each test case needs a name.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            match ($this->input('type')) {
                'true_false' => $this->checkTrueFalse($validator),
                'multiple_choice' => $this->checkMultipleChoice($validator),
                'problem_solving' => $this->checkProblemSolving($validator),
                'coding' => $this->checkCoding($validator),
                default => null,
            };
        }];
    }

    private function checkCoding(Validator $validator): void
    {
        $points = (float) $this->input('points');
        $testPoints = (float) collect($this->input('tests', []))->sum(fn ($t) => (float) $t['points']);
        $rubricPoints = (float) collect($this->input('criteria', []))->sum(fn ($c) => (float) $c['max_points']);

        if (abs($testPoints + $rubricPoints - $points) > 0.001) {
            $validator->errors()->add('tests', sprintf(
                'Test points (%s) plus rubric points (%s) must equal the question points (%s).',
                $testPoints + 0, $rubricPoints + 0, $points + 0,
            ));
        }
    }

    private function checkTrueFalse(Validator $validator): void
    {
        $correct = $this->input('correct', []);
        if (count($correct) !== 1 || ! in_array(strtolower($correct[0]), ['true', 'false'], true)) {
            $validator->errors()->add('correct', 'The answer key must be either true or false.');
        }
    }

    private function checkMultipleChoice(Validator $validator): void
    {
        $labels = array_column($this->input('options', []), 'label');
        $correct = array_unique(array_map('strtoupper', $this->input('correct', [])));
        if (array_diff($correct, $labels) !== []) {
            $validator->errors()->add('correct', 'The correct answer must be one of the options.');
        }
        if (! $this->boolean('multiple_select') && count($correct) !== 1) {
            $validator->errors()->add('correct', 'A single-answer question must have exactly one correct option.');
        }
    }

    private function checkProblemSolving(Validator $validator): void
    {
        $points = (float) $this->input('points');
        $answerPoints = $this->filled('answer_points') ? (float) $this->input('answer_points') : $points;
        $rubricPoints = (float) collect($this->input('criteria', []))->sum(fn ($c) => (float) $c['max_points']);

        if ($answerPoints > $points) {
            $validator->errors()->add('answer_points', 'Final-answer points cannot exceed the question points.');
        } elseif (abs($answerPoints + $rubricPoints - $points) > 0.001) {
            $validator->errors()->add('criteria', sprintf(
                'Final-answer points (%s) plus rubric points (%s) must equal the question points (%s).',
                $answerPoints + 0, $rubricPoints + 0, $points + 0,
            ));
        }
        if ($this->boolean('unit_required') && $this->input('units', []) === []) {
            $validator->errors()->add('units', 'List the accepted unit(s) when a unit is required.');
        }
    }
}
