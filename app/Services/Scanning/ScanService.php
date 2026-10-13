<?php

namespace App\Services\Scanning;

use App\Jobs\ProcessScanUpload;
use App\Models\AnswerSheetLayout;
use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ScanSheet;
use App\Models\ScanUpload;
use App\Models\Student;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Services\Grading\RecognitionConfidenceService;
use App\Services\SubmissionService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Scanned answer sheets: store uploads privately, recognize them on the queue, group pages
 * into sheets, match students and create scan submissions.
 */
class ScanService
{
    public function __construct(
        private readonly SheetRecognizer $recognizer,
        private readonly AnswerSheetService $sheets,
        private readonly SubmissionService $submissions,
        private readonly RecognitionConfidenceService $confidence,
    ) {}

    private function disk(): Filesystem
    {
        return Storage::disk(config('exam_checker.scanner.disk'));
    }

    /** Store the original file (never modified) and queue recognition. */
    public function upload(Exam $exam, UploadedFile $file, User $user): ScanUpload
    {
        if (! $exam->acceptsSubmissions()) {
            throw ValidationException::withMessages(['scan' => 'This exam is not accepting submissions.']);
        }
        $this->sheets->layoutFor($exam->currentVersion); // sheets must have been printable for this version

        $sha = hash_file('sha256', $file->getRealPath());
        if ($existing = $exam->scanUploads()->where('sha256', $sha)->first()) {
            throw ValidationException::withMessages(['scan' => "This file was already uploaded on {$existing->created_at->format('M j, Y g:i A')}."]);
        }

        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $path = $file->storeAs('scans/'.$exam->id.'/originals', Str::uuid().'.'.$extension, config('exam_checker.scanner.disk'));

        $upload = $exam->scanUploads()->create([
            'uploaded_by' => $user->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'sha256' => $sha,
            'status' => 'pending',
        ]);
        AuditLog::record('scan.uploaded', $upload, [], ['name' => $upload->original_name, 'size' => $upload->size]);

        ProcessScanUpload::dispatch($upload->id);

        return $upload;
    }

    /** Run recognition and turn the pages into sheets and submissions. Safe to call once per upload. */
    public function process(ScanUpload $upload): ScanUpload
    {
        $upload->update(['status' => 'processing', 'error' => null]);
        $exam = $upload->exam()->with(['currentVersion', 'offering'])->firstOrFail();

        $layouts = AnswerSheetLayout::whereIn('exam_version_id', $exam->versions()->select('id'))->get()->keyBy('id');
        $current = $layouts->firstWhere('exam_version_id', $exam->currentVersion->id);
        $workDir = $upload->workDirectory();
        $this->disk()->makeDirectory($workDir);

        $result = $this->recognizer->read(
            $this->disk()->path($upload->path),
            $this->disk()->path($workDir),
            $layouts->map(fn ($l) => $l->layout)->all(),
            $current?->id,
            $this->confidence->threshold(),
        );

        if (! ($result['ok'] ?? false)) {
            $upload->update(['status' => 'failed', 'error' => $result['error'] ?? 'Recognition failed.']);

            return $upload;
        }

        $pages = $result['pages'];
        foreach ($this->groupIntoSheets($pages, $layouts) as $group) {
            $this->createSheet($upload, $exam, $group, $layouts);
        }

        $counts = $upload->sheets()->get()->countBy('status')->all();
        $upload->update(['status' => 'completed', 'page_count' => count($pages), 'summary' => $counts]);
        AuditLog::record('scan.processed', $upload, [], ['pages' => count($pages), 'sheets' => $counts]);

        return $upload;
    }

    /**
     * Consecutive pages belong to the same sheet when page n+1 follows page n of the same layout
     * and student. A page that cannot be identified forms its own sheet.
     *
     * @return list<array{pages: list<array>, qr: ?array, error: ?string}>
     */
    private function groupIntoSheets(array $pages, $layouts): array
    {
        $groups = [];
        foreach ($pages as $page) {
            $qr = $this->sheets->verify($page['qr'] ?? null);
            if (! $page['ok']) {
                $groups[] = ['pages' => [$page], 'qr' => null, 'error' => $page['error']];

                continue;
            }
            if (($page['qr'] ?? null) !== null && $qr === null) {
                $groups[] = ['pages' => [$page], 'qr' => null, 'error' => 'The QR code on this page is not from this system or was damaged.'];

                continue;
            }

            $last = $groups === [] ? null : $groups[array_key_last($groups)];
            $continues = $last && $last['error'] === null && $qr !== null && $last['qr'] !== null
                && $last['qr']['layout_id'] === $qr['layout_id']
                && $last['qr']['student_id'] === $qr['student_id']
                && $qr['page'] === count($last['pages']) + 1;

            if ($continues) {
                $groups[array_key_last($groups)]['pages'][] = $page;
            } else {
                $groups[] = ['pages' => [$page], 'qr' => $qr, 'error' => null];
            }
        }

        foreach ($groups as &$group) {
            $layoutId = $group['qr']['layout_id'] ?? (int) ($group['pages'][0]['layout_id'] ?? 0);
            $expected = $layouts->get($layoutId)?->page_count;
            if ($group['error'] === null && $expected !== null && count($group['pages']) !== $expected) {
                $group['error'] = 'Incomplete sheet: found '.count($group['pages'])." of {$expected} page(s).";
            }
            if ($group['error'] === null && $group['qr'] === null && $expected > 1) {
                $group['error'] = 'Multi-page sheet without a readable QR code; pages cannot be put together reliably.';
            }
        }

        return $groups;
    }

    private function createSheet(ScanUpload $upload, Exam $exam, array $group, $layouts): ScanSheet
    {
        $first = $group['pages'][0];
        $layoutId = $group['qr']['layout_id'] ?? (($first['ok'] && isset($first['layout_id'])) ? (int) $first['layout_id'] : null);
        $layout = $layoutId ? $layouts->get($layoutId) : null;

        $recognition = [];
        foreach ($group['pages'] as $page) {
            foreach ($page['answers'] ?? [] as $number => $answer) {
                $recognition[$number] = [...$answer, 'page_index' => $page['index']];
            }
        }

        $sheet = $upload->sheets()->create([
            'answer_sheet_layout_id' => $layout?->id,
            'first_page' => $first['index'],
            'page_count' => count($group['pages']),
            'status' => 'unreadable',
            'qr_student_id' => $group['qr']['student_id'] ?? null,
            'recognition' => $group['error'] ? null : $recognition,
            'page_images' => array_values(array_filter(array_map(
                fn ($p) => $p['aligned'] ? $upload->workDirectory().'/'.$p['aligned'] : null, $group['pages']))),
        ]);

        if ($group['error'] !== null) {
            $sheet->update(['message' => $group['error']]);

            return $sheet;
        }
        $warnings = collect($group['pages'])->flatMap(fn ($p) => $p['warnings'] ?? [])->unique()->implode(' ');

        if ($layout === null || $layout->exam_version_id !== $exam->currentVersion->id) {
            $sheet->update(['status' => 'wrong_exam', 'message' => $layout
                ? 'This sheet was printed for an earlier version of the exam. Print new sheets or enter these answers manually.'
                : 'This sheet does not belong to this exam.']);

            return $sheet;
        }

        $studentId = $group['qr']['student_id'] ?? null;
        if ($studentId === null) {
            $sheet->update(['status' => 'needs_student', 'message' => trim('Select the student for this sheet. '.$warnings)]);

            return $sheet;
        }

        $student = Student::find($studentId);
        if (! $student || ! $exam->offering->students()->whereKey($student->id)->exists()) {
            $sheet->update(['status' => 'needs_student', 'message' => 'The printed student is not on this class roster. Select the student.']);

            return $sheet;
        }

        return $this->attachToStudent($sheet, $exam, $student, $upload->uploader, manual: false);
    }

    /** Teacher picks the student for a sheet that could not be matched automatically. */
    public function assign(ScanSheet $sheet, Student $student, User $user): ScanSheet
    {
        if (! $sheet->canBeAssigned()) {
            throw ValidationException::withMessages(['student_id' => 'This sheet cannot be assigned.']);
        }

        $exam = $sheet->upload->exam;
        $sheet = $this->attachToStudent($sheet, $exam, $student, $user, manual: true);
        if ($sheet->status === 'duplicate') {
            throw ValidationException::withMessages(['student_id' => $sheet->message]);
        }
        AuditLog::record('scan.sheet_assigned', $sheet, [], ['student_id' => $student->id]);

        return $sheet;
    }

    private function attachToStudent(ScanSheet $sheet, Exam $exam, Student $student, User $user, bool $manual): ScanSheet
    {
        $existing = $exam->submissions()->where('student_id', $student->id)->first();
        if ($existing) {
            $sheet->update([
                'status' => 'duplicate', 'student_id' => $student->id,
                'message' => "{$student->fullName()} already has a submission for this exam; this sheet was not graded.",
            ]);

            return $sheet;
        }

        $version = $sheet->layout->version;
        $questions = $version->questions()->get()->keyBy('number');
        $rows = [];
        foreach ($questions as $number => $question) {
            $rows[$number] = $this->rowFor($sheet->recognition[(string) $number] ?? null);
        }

        try {
            $submission = $this->submissions->createFromScan($exam, $version, $student, $rows, $user,
                fn (Submission $s) => $this->attachFiles($s, $sheet, $questions));
        } catch (ValidationException $e) {
            $sheet->update(['status' => 'needs_student', 'student_id' => null, 'message' => collect($e->errors())->flatten()->implode(' ')]);

            return $sheet;
        } catch (Throwable $e) {
            report($e);
            $sheet->update(['status' => 'unreadable', 'message' => 'The sheet could not be saved: '.$e->getMessage()]);

            return $sheet;
        }

        $sheet->update([
            'status' => $manual ? 'assigned' : 'matched',
            'student_id' => $student->id,
            'submission_id' => $submission->id,
            'message' => null,
        ]);

        return $sheet;
    }

    /** Translate one recognized item into a recognized-answer row. */
    private function rowFor(?array $item): array
    {
        if ($item === null) {
            return ['raw' => null, 'confidence' => 0.0, 'meta' => ['missing' => true]];
        }

        if ($item['kind'] === 'write') {
            return ['raw' => null, 'confidence' => (float) $item['confidence'], 'meta' => [
                'written' => true, 'blank' => (bool) $item['blank'], 'ink' => $item['ink'],
            ]];
        }

        $marked = $item['marked'];
        $raw = $marked === [] ? null : implode(',', $marked);

        return ['raw' => $raw, 'confidence' => (float) $item['confidence'], 'meta' => ['fills' => $item['fills']]];
    }

    private function attachFiles(Submission $submission, ScanSheet $sheet, $questions): void
    {
        foreach ($sheet->page_images ?? [] as $i => $path) {
            $this->fileRecord($submission, $sheet, null, 'aligned_page', $i + 1, $path);
        }
        foreach ($sheet->recognition ?? [] as $number => $item) {
            if (! empty($item['crop']) && $questions->has((int) $number)) {
                $this->fileRecord($submission, $sheet, $questions[(int) $number]->id, 'answer_crop', null,
                    $sheet->upload->workDirectory().'/'.$item['crop']);
            }
        }
    }

    private function fileRecord(Submission $submission, ScanSheet $sheet, ?int $questionId, string $kind, ?int $page, string $path): void
    {
        $disk = config('exam_checker.scanner.disk');
        if (! Storage::disk($disk)->exists($path)) {
            return;
        }
        SubmissionFile::create([
            'submission_id' => $submission->id,
            'scan_sheet_id' => $sheet->id,
            'question_id' => $questionId,
            'kind' => $kind,
            'page' => $page,
            'disk' => $disk,
            'path' => $path,
            'mime' => Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream',
            'size' => Storage::disk($disk)->size($path),
        ]);
    }
}
