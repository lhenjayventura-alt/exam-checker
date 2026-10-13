<?php

namespace App\Http\Controllers;

use App\Models\ReviewTask;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReviewQueueController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tasks = ReviewTask::openForTeacher($request->user())
            ->with(['gradingResult.question', 'gradingResult.submission.student', 'gradingResult.submission.exam.offering.course'])
            ->oldest()
            ->paginate(25);

        return view('review-queue', compact('tasks'));
    }
}
