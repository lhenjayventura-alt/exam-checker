<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGradingResultRequest;
use App\Models\GradingResult;
use App\Services\ReviewService;
use Illuminate\Http\RedirectResponse;

class GradingResultController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function update(GradingResult $gradingResult, UpdateGradingResultRequest $request): RedirectResponse
    {
        $this->authorize('grade', $gradingResult->submission->exam);

        $result = $this->reviews->apply($gradingResult, $request->validated(), $request->user());
        $number = $result->question->number;

        $message = match ($request->input('action')) {
            'correct_answer' => "Question {$number}: answer corrected and re-graded ({$result->awarded_score}/{$result->max_points}).",
            'override' => "Question {$number}: score set to {$result->awarded_score}/{$result->max_points}.",
            'confirm' => "Question {$number}: proposed score confirmed.",
            'score_rubric' => "Question {$number}: rubric scored ({$result->awarded_score}/{$result->max_points}).",
        };

        return back()->with('status', $message);
    }
}
