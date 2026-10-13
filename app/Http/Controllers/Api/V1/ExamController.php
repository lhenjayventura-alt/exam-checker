<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExamRequest;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Resources\ExamResource;
use App\Http\Resources\QuestionResource;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Services\ExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ExamController extends Controller
{
    public function __construct(private readonly ExamService $exams) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'offering_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Exam::STATUSES)],
        ]);
        $visible = CourseOffering::visibleTo($request->user())->select('id');

        return ExamResource::collection(
            Exam::whereIn('course_offering_id', $visible)
                ->when($request->integer('offering_id'), fn ($q, $id) => $q->where('course_offering_id', $id))
                ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
                ->with(['offering.course', 'offering.term', 'currentVersion'])
                ->latest()->paginate(50)
        );
    }

    public function store(CourseOffering $offering, StoreExamRequest $request): JsonResponse
    {
        $this->authorize('createExam', $offering);
        $exam = $this->exams->create($offering, $request->validated(), $request->user());

        return (new ExamResource($exam->load(['offering.course', 'offering.term', 'currentVersion'])))->response()->setStatusCode(201);
    }

    public function show(Exam $exam, Request $request): ExamResource
    {
        $this->authorize('view', $exam);
        $exam->load(['offering.course', 'offering.term', 'currentVersion.questions.options', 'currentVersion.questions.rubricCriteria']);
        // The answer key is included only for users with grading rights on this exam.
        if ($request->user()->can('grade', $exam)) {
            $exam->load('currentVersion.questions.answerKey');
        }

        return new ExamResource($exam);
    }

    public function storeQuestion(Exam $exam, StoreQuestionRequest $request): JsonResponse
    {
        $this->authorize('manage', $exam);
        $question = $this->exams->addQuestion($exam, $request->validated());

        return (new QuestionResource($question))->response()->setStatusCode(201);
    }

    /** Clone the locked version into a new draft version. */
    public function newVersion(Exam $exam, Request $request): JsonResponse
    {
        $this->authorize('manage', $exam);
        $exam = $this->exams->newVersion($exam, $request->user());

        return (new ExamResource($exam->load(['offering.course', 'offering.term', 'currentVersion'])))->response()->setStatusCode(201);
    }

    public function publish(Exam $exam, Request $request): ExamResource
    {
        $this->authorize('manage', $exam);

        return new ExamResource($this->exams->publish($exam, $request->user())->load(['offering.course', 'offering.term', 'currentVersion']));
    }
}
