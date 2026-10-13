<?php

namespace App\Http\Controllers;

use App\Exports\ExamResultsExport;
use App\Exports\ScoreSheet;
use App\Models\Exam;
use App\Models\Submission;
use App\Services\ExamAnalyticsService;
use App\Services\ExamResultService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

        $exam->load(['offering.course', 'offering.term', 'currentVersion', 'versions']);
        $version = $request->filled('version')
            ? $exam->versions->firstWhere('version_number', $request->integer('version'))
            : $this->analytics->defaultVersion($exam);
        abort_if($version === null, 404);

        return view('exams.results', [
            'exam' => $exam,
            'version' => $version,
            'results' => $exam->results()->with(['student', 'submission'])
                ->whereHas('submission', fn ($q) => $q->where('exam_version_id', $version->id))->get()
                ->sortBy(fn ($r) => $r->student->last_name.' '.$r->student->first_name),
            'summary' => $this->analytics->summary($exam, $version),
            'items' => $this->analytics->itemAnalysis($exam, $version),
            'unfinalized' => $exam->submissions()->where('status', '!=', 'finalized')->count(),
            'canManage' => $request->user()->can('manage', $exam),
            'showKey' => $request->user()->can('grade', $exam),
            'hasUnreleased' => $exam->results()->whereNull('released_at')->exists(),
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

    /** Class score sheet of finalized results (all versions): CSV, or Excel with item-analysis sheets. */
    public function export(Exam $exam, Request $request): StreamedResponse|BinaryFileResponse
    {
        $this->authorize('view', $exam);

        $exam->load('offering.course');
        $base = str($exam->offering->course->code.'-'.$exam->offering->section.'-'.$exam->title)->slug().'-results';

        if ($request->query('format') === 'xlsx') {
            return Excel::download(new ExamResultsExport($exam), $base.'.xlsx');
        }

        $rows = ScoreSheet::rows($exam);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ScoreSheet::HEADINGS);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $base.'.csv', ['Content-Type' => 'text/csv']);
    }
}
