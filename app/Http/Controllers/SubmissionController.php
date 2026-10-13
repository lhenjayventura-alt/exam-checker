<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\Student;
use App\Models\Submission;
use App\Services\SubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubmissionController extends Controller
{
    public function __construct(private readonly SubmissionService $submissions) {}

    public function create(Exam $exam): View|RedirectResponse
    {
        $this->authorize('grade', $exam);
        if (! $exam->acceptsSubmissions()) {
            return redirect()->route('exams.show', $exam)->withErrors(['exam' => 'This exam is not accepting submissions.']);
        }

        $submitted = $exam->submissions()->pluck('student_id');

        return view('submissions.create', [
            'exam' => $exam->load('offering.course'),
            'questions' => $exam->currentVersion->questions()->with('options')->get(),
            'students' => $exam->offering->students()->whereNotIn('students.id', $submitted)
                ->orderBy('last_name')->orderBy('first_name')->get(),
        ]);
    }

    public function store(Exam $exam, Request $request): RedirectResponse
    {
        $this->authorize('grade', $exam);

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'answers' => ['array'],
            'answers.*' => ['nullable', 'string', 'max:255'],
        ]);

        $submission = $this->submissions->createDigital(
            $exam, Student::findOrFail($data['student_id']), $data['answers'] ?? [], $request->user(),
        );

        $next = $request->boolean('add_another') ? route('exams.submissions.create', $exam) : route('submissions.show', $submission);

        return redirect($next)->with('status', "Answers recorded for {$submission->student->fullName()} and queued for grading.");
    }

    public function show(Submission $submission): View
    {
        $this->authorize('view', $submission->exam);

        $submission->load(['exam.offering.course', 'student', 'result', 'version']);
        $answers = $submission->answers()->get()->keyBy('question_id');
        $results = $submission->gradingResults()->with(['openReviewTask', 'events.user', 'details'])->get()->keyBy('question_id');

        return view('submissions.show', [
            'submission' => $submission,
            'questions' => $submission->version->questions()->with(['options', 'answerKey', 'rubricCriteria'])->get(),
            'answers' => $answers,
            'results' => $results,
            'canGrade' => request()->user()->can('grade', $submission->exam),
        ]);
    }

    public function reprocess(Submission $submission): RedirectResponse
    {
        $this->authorize('grade', $submission->exam);
        $this->submissions->reprocess($submission);

        return back()->with('status', 'Submission queued for grading again.');
    }
}
