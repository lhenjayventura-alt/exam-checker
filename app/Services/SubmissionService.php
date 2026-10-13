<?php

namespace App\Services;

use App\Jobs\GradeSubmission;
use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\Student;
use App\Models\Submission;
use App\Models\User;
use App\Services\Grading\GraderRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmissionService
{
    public function __construct(private readonly GraderRegistry $graders) {}

    /**
     * Record typed (digital) answers for one student and queue grading.
     *
     * @param  array<int|string, ?string>  $answers  Raw answers keyed by question number.
     */
    public function createDigital(Exam $exam, Student $student, array $answers, User $submitter): Submission
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

        $version = $exam->currentVersion;
        $questions = $version->questions()->get()->keyBy('number');
        $unknown = array_diff(array_map('intval', array_keys($answers)), $questions->keys()->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['answers' => 'Unknown question number(s): '.implode(', ', $unknown).'.']);
        }

        try {
            $submission = DB::transaction(function () use ($exam, $version, $student, $answers, $questions, $submitter) {
                $submission = $exam->submissions()->create([
                    'exam_version_id' => $version->id,
                    'student_id' => $student->id,
                    'source' => 'digital',
                    'status' => 'pending',
                    'submitted_by' => $submitter->id,
                ]);

                foreach ($questions as $number => $question) {
                    $raw = $answers[$number] ?? null;
                    $raw = $raw === null ? null : trim((string) $raw);
                    $submission->answers()->create([
                        'question_id' => $question->id,
                        'source' => 'digital',
                        'raw_value' => $raw === '' ? null : $raw,
                        'normalized_value' => $this->graders->normalize($question, $raw),
                        'confidence' => null,
                    ]);
                }

                AuditLog::record('submission.created', $submission, [], ['student_id' => $student->id, 'source' => 'digital']);

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
