<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\Submission;
use App\Services\ExamAnalyticsService;
use App\Services\ExamResultService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExamResultController extends Controller
{
    public function __construct(
        private readonly ExamResultService $results,
        private readonly ExamAnalyticsService $analytics,
    ) {}

    public function index(Exam $exam, Request $request): View
    {
        $this->authorize('view', $exam);

        $exam->load(['offering.course', 'offering.term', 'currentVersion']);

        return view('exams.results', [
            'exam' => $exam,
            'results' => $exam->results()->with('student')->get()
                ->sortBy(fn ($r) => $r->student->last_name.' '.$r->student->first_name),
            'summary' => $this->analytics->summary($exam),
            'items' => $this->analytics->itemAnalysis($exam),
            'unfinalized' => $exam->submissions()->where('status', '!=', 'finalized')->count(),
            'canManage' => $request->user()->can('manage', $exam),
            'showKey' => $request->user()->can('grade', $exam),
        ]);
    }

    public function finalize(Submission $submission, Request $request): RedirectResponse
    {
        $this->authorize('grade', $submission->exam);
        $result = $this->results->finalize($submission, $request->user());

        return back()->with('status', "Finalized: {$result->total_score}/{$result->max_score} ({$result->percentage}%).");
    }

    public function finalizeAll(Exam $exam, Request $request): RedirectResponse
    {
        $this->authorize('grade', $exam);
        $count = $this->results->finalizeAllGraded($exam, $request->user());

        return back()->with('status', $count ? "{$count} graded submission(s) finalized." : 'No graded submissions were waiting to be finalized.');
    }

    public function release(Exam $exam, Request $request): RedirectResponse
    {
        $this->authorize('grade', $exam);
        $count = $this->results->release($exam, $request->user());

        return back()->with('status', "{$count} result(s) released.");
    }

    /** CSV class score sheet of finalized results. */
    public function export(Exam $exam): StreamedResponse
    {
        $this->authorize('view', $exam);

        $exam->load('offering.course');
        $filename = str($exam->offering->course->code.'-'.$exam->offering->section.'-'.$exam->title)->slug().'-results.csv';
        $results = $exam->results()->with('student')->get()->sortBy(fn ($r) => $r->student->last_name.' '.$r->student->first_name);

        return response()->streamDownload(function () use ($results) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Student Number', 'Last Name', 'First Name', 'Score', 'Max', 'Percentage', 'Passed', 'Finalized At', 'Released At']);
            // Prefix cells that a spreadsheet would treat as formulas.
            $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
            foreach ($results as $r) {
                fputcsv($out, array_map($safe, [
                    $r->student->student_number, $r->student->last_name, $r->student->first_name,
                    $r->total_score, $r->max_score, $r->percentage, $r->passed ? 'Yes' : 'No',
                    $r->finalized_at?->toDateTimeString(), $r->released_at?->toDateTimeString(),
                ]));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
