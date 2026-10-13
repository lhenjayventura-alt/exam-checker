<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * action=correct_answer: answer (nullable = blank), reason optional.
 * action=override: score and reason required, feedback optional.
 * action=confirm: accept the proposed score, note optional.
 */
class UpdateGradingResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is enforced by policies in the controller.
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['correct_answer', 'override', 'confirm'])],
            'answer' => ['nullable', 'string', 'max:50'],
            'score' => ['required_if:action,override', 'nullable', 'numeric', 'min:0'],
            'reason' => ['required_if:action,override', 'nullable', 'string', 'max:1000'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required_if' => 'A reason is required for a score override.'];
    }
}
