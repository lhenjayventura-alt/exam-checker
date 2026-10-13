<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ScanSheet;
use App\Models\Student;
use App\Models\SubmissionFile;
use App\Services\Scanning\AnswerSheetService;
use App\Services\Scanning\ScanService;
use App\Services\Scanning\SheetRecognizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScanController extends Controller
{
    public function __construct(
        private readonly ScanService $scans,
        private readonly AnswerSheetService $answerSheets,
    ) {}

    public function index(Exam $exam, SheetRecognizer $recognizer): View
    {
        $this->authorize('grade', $exam);

        $exam->load(['offering.course', 'currentVersion']);
        $uploads = $exam->scanUploads()->with(['uploader', 'sheets.student', 'sheets.submission'])->latest()->get();
        $submitted = $exam->submissions()->pluck('student_id');

        return view('scans.index', [
            'exam' => $exam,
            'uploads' => $uploads,
            'pending' => $uploads->flatMap->sheets->whereIn('status', ScanSheet::NEEDS_ACTION)->values(),
            'students' => $exam->offering->students()->whereNotIn('students.id', $submitted)->orderBy('last_name')->orderBy('first_name')->get(),
            'scannerReady' => Cache::remember('scanner.available', 60, fn () => $recognizer->available()),
            'maxKb' => config('exam_checker.scanner.max_upload_kb'),
        ]);
    }

    public function store(Exam $exam, Request $request): RedirectResponse
    {
        $this->authorize('grade', $exam);
        $request->validate(['scan' => self::fileRules()], [
            'scan.mimes' => 'Upload a PDF, JPEG or PNG file.',
            'scan.max' => 'The file is too large.',
        ]);

        $upload = $this->scans->upload($exam, $request->file('scan'), $request->user());

        return redirect()->route('exams.scans.index', $exam)
            ->with('status', "\"{$upload->original_name}\" uploaded and queued for recognition. Refresh this page to see the results.");
    }

    public function assign(ScanSheet $sheet, Request $request): RedirectResponse
    {
        $exam = $sheet->upload->exam;
        $this->authorize('grade', $exam);
        $data = $request->validate(['student_id' => ['required', 'integer', 'exists:students,id']]);

        $sheet = $this->scans->assign($sheet, Student::findOrFail($data['student_id']), $request->user());

        return back()->with('status', "Sheet assigned to {$sheet->student->fullName()} and queued for grading.");
    }

    /** Printable PDF: one generic sheet, or ?for=roster for one named sheet per student without a submission. */
    public function answerSheets(Exam $exam, Request $request): BinaryFileResponse
    {
        $this->authorize('grade', $exam);
        $exam->load('offering.course', 'currentVersion');

        $students = null;
        if ($request->query('for') === 'roster') {
            $submitted = $exam->submissions()->pluck('student_id');
            $students = $exam->offering->students()->whereNotIn('students.id', $submitted)->orderBy('last_name')->orderBy('first_name')->get();
            abort_if($students->isEmpty(), 422, 'Every student on the roster already has a submission.');
        }

        $disk = Storage::disk(config('exam_checker.scanner.disk'));
        $disk->makeDirectory('tmp');
        $path = $disk->path('tmp/'.Str::uuid().'.pdf');
        $this->answerSheets->renderPdf($exam, $students, $path);

        $name = Str::slug($exam->offering->course->code.'-'.$exam->offering->section.'-'.$exam->title)
            .'-v'.$exam->currentVersion->version_number.($students ? '-named' : '').'-answer-sheets.pdf';

        return response()->download($path, $name, ['Content-Type' => 'application/pdf'])->deleteFileAfterSend();
    }

    /** Aligned page image of a recognized sheet (for matching unidentified sheets). */
    public function sheetPage(ScanSheet $sheet, int $page): StreamedResponse
    {
        $this->authorize('grade', $sheet->upload->exam);
        $path = $sheet->page_images[$page - 1] ?? abort(404);

        return Storage::disk(config('exam_checker.scanner.disk'))->response($path, null, self::privateHeaders());
    }

    /** Page images and answer crops attached to a submission. */
    public function file(SubmissionFile $file): StreamedResponse
    {
        $this->authorize('view', $file->submission->exam);

        return Storage::disk($file->disk)->response($file->path, null, self::privateHeaders());
    }

    public static function fileRules(): array
    {
        return ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png',
            'max:'.config('exam_checker.scanner.max_upload_kb')];
    }

    private static function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
    }
}
