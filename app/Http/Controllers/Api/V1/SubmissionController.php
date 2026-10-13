<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubmissionResource;
use App\Models\Exam;
use App\Models\Student;
use App\Models\Submission;
use App\Services\SubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubmissionController extends Controller
{
    public function __construct(private readonly SubmissionService $submissions) {}

    /**
     * Record digital answers. Body: {"student_id": 1} or {"student_number": "..."},
     * "answers": {"1": "B", "2": "T"} keyed by question number.
     *
     * Retrying the same request is safe: when the student already has a submission the
     * API returns 409 with the existing submission instead of creating a duplicate.
     */
    public function store(Exam $exam, Request $request): JsonResponse
    {
        $this->authorize('grade', $exam);

        $data = $request->validate([
            'student_id' => ['required_without:student_number', 'nullable', 'integer'],
            'student_number' => ['required_without:student_id', 'nullable', 'string', 'max:30'],
            'answers' => ['present', 'array'],
            'answers.*' => ['nullable', 'string', 'max:50'],
        ]);

        $student = isset($data['student_id'])
            ? Student::find($data['student_id'])
            : Student::where('student_number', $data['student_number'])->first();
        if (! $student) {
            return response()->json(['message' => 'Student not found.', 'errors' => ['student' => ['Student not found.']]], 422);
        }

        $existing = $exam->submissions()->where('student_id', $student->id)->first();
        if ($existing) {
            return response()->json([
                'message' => 'This student already has a submission for this exam.',
                'data' => new SubmissionResource($existing),
            ], 409);
        }

        $submission = $this->submissions->createDigital($exam, $student, $data['answers'], $request->user());

        return (new SubmissionResource($submission->load('student')))->response()->setStatusCode(202);
    }

    public function show(Submission $submission, Request $request): SubmissionResource
    {
        $this->authorize('view', $submission->exam);
        $submission->load(['student', 'result', 'answers']);
        // Grading explanations reveal the answer key, so they are limited to users with grading rights.
        if ($request->user()->can('grade', $submission->exam)) {
            $submission->load(['gradingResults.question', 'gradingResults.openReviewTask']);
        }

        return new SubmissionResource($submission);
    }

    /** Lightweight polling endpoint for processing progress. */
    public function status(Submission $submission): JsonResponse
    {
        $this->authorize('view', $submission->exam);

        return response()->json(['data' => [
            'id' => $submission->id,
            'status' => $submission->status,
            'failure_reason' => $submission->failure_reason,
            'graded_at' => $submission->graded_at?->toIso8601String(),
            'open_review_tasks' => $submission->reviewTasks()->where('review_tasks.status', 'open')->count(),
        ]]);
    }

    public function process(Submission $submission): JsonResponse
    {
        $this->authorize('grade', $submission->exam);

        return (new SubmissionResource($this->submissions->reprocess($submission)))->response()->setStatusCode(202);
    }
}
