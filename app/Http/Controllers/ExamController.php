<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExamRequest;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Services\CodeExecution\CodeRunner;
use App\Services\ExamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class ExamController extends Controller
{
    public function __construct(private readonly ExamService $exams) {}

    public function create(CourseOffering $offering): View
    {
        $this->authorize('createExam', $offering);

        return view('exams.create', ['offering' => $offering->load('course', 'term')]);
    }

    public function store(CourseOffering $offering, StoreExamRequest $request): RedirectResponse
    {
        $this->authorize('createExam', $offering);

        $exam = $this->exams->create($offering, $request->validated(), $request->user());

        return redirect()->route('exams.show', $exam)->with('status', 'Exam created. Add questions and the answer key, then publish.');
    }

    public function show(Exam $exam, Request $request): View
    {
        $this->authorize('view', $exam);

        $exam->load(['offering.course', 'offering.term', 'currentVersion']);
        $canManage = $request->user()->can('manage', $exam);
        $questions = $exam->currentVersion->questions()->with(['options', 'answerKey', 'rubricCriteria', 'testCases'])->get();

        $submissions = $exam->submissions()
            ->with(['student', 'result', 'version'])
            ->withCount(['reviewTasks as open_reviews' => fn ($q) => $q->where('review_tasks.status', 'open')])
            ->get()
            ->sortBy(fn ($s) => $s->student->last_name.' '.$s->student->first_name);

        return view('exams.show', [
            'exam' => $exam,
            'questions' => $questions,
            'totalPoints' => $questions->sum('points'),
            'submissions' => $submissions,
            'canManage' => $canManage,
            // Program chairs and administrators see structure but not the answer key.
            'showKey' => $canManage,
            'rosterCount' => $exam->offering->students()->count(),
            // Only checked when the exam has coding questions (it starts a docker process).
            'runnerReady' => $questions->contains('type', 'coding') && $canManage
                ? Cache::remember('code_runner.available', 60, fn () => app(CodeRunner::class)->available())
                : null,
        ]);
    }

    public function update(Exam $exam, StoreExamRequest $request): RedirectResponse
    {
        $this->authorize('manage', $exam);
        $this->exams->update($exam, $request->validated());

        return back()->with('status', 'Exam details saved.');
    }

    public function publish(Exam $exam, Request $request): RedirectResponse
    {
        $this->authorize('manage', $exam);
        $this->exams->publish($exam, $request->user());

        return back()->with('status', 'Exam published. Its questions and answer key are now locked, and submissions are open.');
    }

    public function newVersion(Exam $exam, Request $request): RedirectResponse
    {
        $this->authorize('manage', $exam);
        $exam = $this->exams->newVersion($exam, $request->user());

        return back()->with('status', "Version {$exam->currentVersion->version_number} created as a draft from the previous version. Edit it, then publish. Earlier submissions keep their original version.");
    }

    public function close(Exam $exam): RedirectResponse
    {
        $this->authorize('manage', $exam);
        $this->exams->close($exam);

        return back()->with('status', 'Exam closed to new submissions.');
    }
}
