<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamResultService
{
    /**
     * Validate that every question has an authoritative score, recalculate the total,
     * and record the final result. Runs in one transaction with the submission locked.
     */
    public function finalize(Submission $submission, User $teacher): ExamResult
    {
        return DB::transaction(function () use ($submission, $teacher) {
            $submission = Submission::query()->lockForUpdate()->findOrFail($submission->id);

            if ($submission->isFinalized()) {
                throw ValidationException::withMessages(['submission' => 'This submission is already finalized.']);
            }
            if ($submission->status !== 'graded') {
                $why = $submission->status === 'needs_review'
                    ? 'Resolve all review tasks before finalizing.'
                    : "The submission is {$submission->status}; it must be graded first.";
                throw ValidationException::withMessages(['submission' => $why]);
            }

            $version = $submission->version;
            $questionIds = $version->questions()->pluck('id');
            $results = $submission->gradingResults()->get()->keyBy('question_id');

            $missing = $questionIds->reject(fn ($id) => $results->get($id)?->awarded_score !== null);
            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages(['submission' => 'Some questions do not have an awarded score yet.']);
            }

            $total = round($questionIds->sum(fn ($id) => $results->get($id)->awarded_score), 2);
            $max = round($version->totalPoints(), 2);
            $percentage = $max > 0 ? round($total / $max * 100, 2) : 0.0;

            $result = ExamResult::create([
                'submission_id' => $submission->id,
                'exam_id' => $submission->exam_id,
                'student_id' => $submission->student_id,
                'total_score' => $total,
                'max_score' => $max,
                'percentage' => $percentage,
                'passed' => $percentage >= (float) $submission->exam->passing_percentage,
                'finalized_by' => $teacher->id,
                'finalized_at' => now(),
            ]);

            $submission->update(['status' => 'finalized']);
            AuditLog::record('result.finalized', $result, [], ['total' => $total, 'max' => $max]);

            return $result;
        });
    }

    /** Finalize every graded submission of an exam; returns the number finalized. */
    public function finalizeAllGraded(Exam $exam, User $teacher): int
    {
        $count = 0;
        foreach ($exam->submissions()->where('status', 'graded')->get() as $submission) {
            $this->finalize($submission, $teacher);
            $count++;
        }

        return $count;
    }

    /** Release all finalized, unreleased results of an exam. Released results are not changed silently. */
    public function release(Exam $exam, User $teacher): int
    {
        // A draft exam may still hold finalized results from an earlier version, so only the
        // existence of unreleased results is checked.
        return DB::transaction(function () use ($exam, $teacher) {
            $ids = $exam->results()->whereNull('released_at')->lockForUpdate()->pluck('id');
            if ($ids->isEmpty()) {
                throw ValidationException::withMessages(['exam' => 'There are no finalized, unreleased results to release.']);
            }

            ExamResult::whereIn('id', $ids)->update(['released_at' => now(), 'released_by' => $teacher->id]);
            AuditLog::record('results.released', $exam, [], ['count' => $ids->count(), 'result_ids' => $ids->all()]);

            return $ids->count();
        });
    }
}
