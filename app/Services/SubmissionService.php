<?php

namespace App\Services;

use App\Jobs\GradeSubmission;
use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamVersion;
use App\Models\Student;
use App\Models\Submission;
use App\Models\User;
use App\Services\Grading\GraderRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmissionService
{
    public function __construct(private readonly GraderRegistry $graders) {}

    /** Validation rules for typed answers and uploaded source files (keyed by question number). */
    public static function answerRules(): array
    {
        $kb = (int) config('exam_checker.code_runner.max_source_kb', 64);

        return [
            'answers' => ['array'],
            'answers.*' => ['nullable', 'string', 'max:'.($kb * 1024)],
            'answer_files' => ['array'],
            'answer_files.*' => ['nullable', 'file', 'max:'.$kb, 'extensions:c,h,py,txt', 'mimetypes:text/plain,text/x-c,text/x-csrc,text/x-python,text/x-script.python,application/octet-stream'],
        ];
    }

    /**
     * Merge uploaded source files into the answers of coding questions. A typed answer for the
     * same question wins. Files are read as text only; they are never executed here.
     *
     * @param  array<int|string, ?string>  $answers
     * @param  array<int|string, UploadedFile>  $files
     */
    public function withSourceFiles(Exam $exam, array $answers, array $files): array
    {
        if ($files === []) {
            return $answers;
        }
        $coding = $exam->currentVersion->questions()->where('type', 'coding')->pluck('number')->all();

        foreach ($files as $number => $file) {
            if (! in_array((int) $number, $coding, true)) {
                throw ValidationException::withMessages(["answer_files.{$number}" => "Question {$number} is not a coding question."]);
            }
            $code = (string) file_get_contents($file->getRealPath());
            if (! mb_check_encoding($code, 'UTF-8') || str_contains($code, "\0")) {
                throw ValidationException::withMessages(["answer_files.{$number}" => 'The source file must be UTF-8 text.']);
            }
            if (trim((string) ($answers[$number] ?? '')) === '') {
                $answers[$number] = $code;
            }
        }

        return $answers;
    }

    /**
     * Record typed (digital) answers for one student and queue grading.
     *
     * @param  array<int|string, ?string>  $answers  Raw answers keyed by question number.
     */
    public function createDigital(Exam $exam, Student $student, array $answers, User $submitter): Submission
    {
        $version = $exam->currentVersion;
        $questions = $version->questions()->get()->keyBy('number');
        $unknown = array_diff(array_map('intval', array_keys($answers)), $questions->keys()->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['answers' => 'Unknown question number(s): '.implode(', ', $unknown).'.']);
        }

        $rows = [];
        foreach ($questions as $number => $question) {
            $raw = $answers[$number] ?? null;
            // Source code keeps its whitespace (indentation matters), with line endings normalized
            // to LF as browsers send CRLF; other answers are trimmed.
            if ($raw !== null) {
                $raw = $question->type === 'coding' ? str_replace("\r\n", "\n", (string) $raw) : trim((string) $raw);
            }
            $rows[$number] = ['raw' => $raw, 'source' => 'digital', 'confidence' => null, 'meta' => null];
        }

        return $this->persist($exam, $version, $student, 'digital', $rows, $submitter);
    }

    /**
     * Record answers recognized from a scanned answer sheet and queue grading.
     *
     * @param  array<int, array{raw: ?string, confidence: ?float, meta: ?array}>  $rows  Keyed by question number.
     * @param  callable(Submission): void  $attachFiles  Runs inside the transaction to link page images and crops.
     */
    public function createFromScan(Exam $exam, ExamVersion $version, Student $student, array $rows, User $submitter, callable $attachFiles): Submission
    {
        if ($version->id !== $exam->currentVersion->id) {
            throw ValidationException::withMessages(['exam' => "This sheet was printed for version {$version->version_number}; the exam is now on version {$exam->currentVersion->version_number}."]);
        }

        return $this->persist($exam, $version, $student, 'scan', array_map(fn ($r) => [...$r, 'source' => 'ocr'], $rows), $submitter, $attachFiles);
    }

    private function persist(Exam $exam, ExamVersion $version, Student $student, string $source, array $rows, User $submitter, ?callable $afterCreate = null): Submission
    {
        if (! $exam->acceptsSubmissions()) {
            throw ValidationException::withMessages(['exam' => 'This exam is not accepting submissions.']);
        }
        if (! $exam->offering->students()->whereKey($student->id)->exists()) {
            throw ValidationException::withMessages(['student_id' => 'The student is not on this class roster.']);
        }
        if ($exam->submissions()->where('student_id', $student->id)->exists()) {
            throw ValidationException::withMessages(['student_id' => 'This student already has a submission for this exam.']);
        }

        $questions = $version->questions()->get()->keyBy('number');

        try {
            $submission = DB::transaction(function () use ($exam, $version, $student, $source, $rows, $questions, $submitter, $afterCreate) {
                $submission = $exam->submissions()->create([
                    'exam_version_id' => $version->id,
                    'student_id' => $student->id,
                    'source' => $source,
                    'status' => 'pending',
                    'submitted_by' => $submitter->id,
                ]);

                foreach ($questions as $number => $question) {
                    $row = $rows[$number] ?? ['raw' => null, 'source' => $source === 'scan' ? 'ocr' : 'digital', 'confidence' => null, 'meta' => null];
                    $raw = $row['raw'] === '' ? null : $row['raw'];
                    $submission->answers()->create([
                        'question_id' => $question->id,
                        'source' => $row['source'],
                        'raw_value' => $raw,
                        'normalized_value' => $this->graders->normalize($question, $raw),
                        'confidence' => $row['confidence'],
                        'recognition_meta' => $row['meta'],
                    ]);
                }

                if ($afterCreate) {
                    $afterCreate($submission);
                }
                AuditLog::record('submission.created', $submission, [], ['student_id' => $student->id, 'source' => $source]);

                return $submission;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the submission first.
            throw ValidationException::withMessages(['student_id' => 'This student already has a submission for this exam.']);
        }

        GradeSubmission::dispatch($submission->id);

        return $submission->fresh();
    }

    /** Re-queue grading for a pending or failed submission. */
    public function reprocess(Submission $submission): Submission
    {
        if ($submission->isFinalized()) {
            throw ValidationException::withMessages(['submission' => 'A finalized submission cannot be reprocessed.']);
        }
        $submission->update(['status' => 'pending', 'failure_reason' => null]);
        GradeSubmission::dispatch($submission->id);

        return $submission->fresh();
    }
}
