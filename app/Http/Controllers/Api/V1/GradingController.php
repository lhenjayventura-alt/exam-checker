<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateGradingResultRequest;
use App\Http\Resources\ExamResultResource;
use App\Http\Resources\GradingResultResource;
use App\Http\Resources\ReviewTaskResource;
use App\Models\Exam;
use App\Models\GradingResult;
use App\Models\ReviewTask;
use App\Models\Submission;
use App\Services\ExamResultService;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GradingController extends Controller
{
    public function __construct(
        private readonly ReviewService $reviews,
        private readonly ExamResultService $results,
    ) {}

    public function reviewTasks(Request $request): AnonymousResourceCollection
    {
        return ReviewTaskResource::collection(
            ReviewTask::openForTeacher($request->user())
                ->with(['gradingResult.question', 'gradingResult.submission.student', 'gradingResult.submission.exam'])
                ->oldest()->paginate(50)
        );
    }

    public function show(GradingResult $gradingResult): GradingResultResource
    {
        $this->authorize('grade', $gradingResult->submission->exam);

        return new GradingResultResource($gradingResult->load(['question', 'openReviewTask', 'events', 'details']));
    }

    public function update(GradingResult $gradingResult, UpdateGradingResultRequest $request): GradingResultResource
    {
        $this->authorize('grade', $gradingResult->submission->exam);
        $result = $this->reviews->apply($gradingResult, $request->validated(), $request->user());

        return new GradingResultResource($result->load(['question', 'openReviewTask', 'events', 'details']));
    }

    public function finalize(Submission $submission, Request $request): ExamResultResource
    {
        $this->authorize('grade', $submission->exam);

        return new ExamResultResource($this->results->finalize($submission, $request->user()));
    }

    public function release(Exam $exam, Request $request): JsonResponse
    {
        $this->authorize('grade', $exam);

        return response()->json(['data' => ['released' => $this->results->release($exam, $request->user())]]);
    }
}
