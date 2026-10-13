<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuestionRequest;
use App\Models\Exam;
use App\Models\Question;
use App\Services\ExamService;
use Illuminate\Http\RedirectResponse;

class QuestionController extends Controller
{
    public function __construct(private readonly ExamService $exams) {}

    public function store(Exam $exam, StoreQuestionRequest $request): RedirectResponse
    {
        $this->authorize('manage', $exam);
        $question = $this->exams->addQuestion($exam, $request->validated());

        return back()->with('status', "Question {$question->number} added.");
    }

    public function destroy(Exam $exam, Question $question): RedirectResponse
    {
        $this->authorize('manage', $exam);
        $this->exams->deleteQuestion($exam, $question);

        return back()->with('status', "Question {$question->number} removed.");
    }
}
