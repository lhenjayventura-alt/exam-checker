<?php

namespace App\Http\Requests;

use App\Models\Exam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is enforced by policies in the controller.
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'exam_type' => ['required', Rule::in(array_keys(Exam::TYPES))],
            'exam_date' => ['nullable', 'date'],
            'passing_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
