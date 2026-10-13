<?php

namespace App\Services\Grading;

use App\Models\GradingDetail;
use App\Models\GradingEvent;
use App\Models\GradingResult;
use App\Models\Question;
use App\Models\RecognizedAnswer;
use App\Models\ReviewTask;
use App\Models\Submission;
use Illuminate\Support\Facades\DB;

/**
 * Grades every question of a submission against its locked exam version.
 *
 * Idempotent: running it again updates automatic results in place (one row per
 * submission/question), never touches results a teacher has already decided, and
 * never modifies a finalized submission.
 */
class GradingOrchestrator
{
    public function __construct(
        private readonly GraderRegistry $graders,
        private readonly RecognitionConfidenceService $confidence,
    ) {}

    public function grade(Submission $submission): Submission
    {
        return DB::transaction(function () use ($submission) {
            $submission = Submission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($submission->isFinalized()) {
                return $submission;
            }

            $questions = $submission->version->questions()->with(['options', 'answerKey', 'rubricCriteria'])->get();
            $answers = $submission->answers()->get()->keyBy('question_id');
            $existing = $submission->gradingResults()->with('openReviewTask')->get()->keyBy('question_id');

            foreach ($questions as $question) {
                $result = $existing->get($question->id);
                if ($result?->isTeacherDecided()) {
                    continue;
                }
                $this->gradeQuestion($submission, $question, $answers->get($question->id), $result);
            }

            return $this->refreshStatus($submission);
        });
    }

    /** Compute the automatic outcome for one question, including recognition-confidence checks. */
    public function evaluate(Question $question, ?RecognizedAnswer $answer): array
    {
        $grader = $this->graders->for($question);
        if ($grader === null) {
            return ['manual', GradeOutcome::review('No automatic grader exists for '.$question->type.' questions.', null)];
        }

        $outcome = $grader->grade($question, $answer?->effectiveValue());
        $lowConfidence = $this->confidence->reviewReason($answer);
        if ($lowConfidence !== null && ! $outcome->needsReview) {
            $outcome = $outcome->withReview($lowConfidence);
        }

        return [$grader->name(), $outcome];
    }

    private function gradeQuestion(Submission $submission, Question $question, ?RecognizedAnswer $answer, ?GradingResult $result): void
    {
        [$graderName, $outcome] = $this->evaluate($question, $answer);
        $previousScore = $result?->awarded_score;
        $isNew = $result === null;

        $result ??= new GradingResult(['submission_id' => $submission->id, 'question_id' => $question->id]);
        $result->fill([
            'grader' => $graderName,
            'max_points' => (float) $question->points,
            'proposed_score' => $outcome->score,
            'awarded_score' => $outcome->needsReview ? null : $outcome->score,
            'is_correct' => $outcome->isCorrect,
            'explanation' => $outcome->explanation,
            'status' => $outcome->needsReview ? 'needs_review' : 'auto',
        ])->save();

        $this->syncAutoDetails($result, $outcome);

        if ($isNew || $result->wasChanged(['proposed_score', 'awarded_score', 'status'])) {
            GradingEvent::create([
                'grading_result_id' => $result->id,
                'event' => 'auto_graded',
                'previous_score' => $previousScore,
                'new_score' => $result->awarded_score,
                'new_answer' => $answer?->effectiveValue(),
                'reason' => $outcome->reviewReason ?? $outcome->explanation,
            ]);
        }

        $openTask = $result->openReviewTask;
        if ($outcome->needsReview && $openTask === null) {
            ReviewTask::create(['grading_result_id' => $result->id, 'reason' => $outcome->reviewReason]);
        } elseif ($outcome->needsReview && $openTask->reason !== $outcome->reviewReason) {
            $openTask->update(['reason' => $outcome->reviewReason]);
        } elseif (! $outcome->needsReview && $openTask !== null) {
            // The condition that required review no longer applies; close the task automatically.
            $openTask->update(['status' => 'resolved', 'resolved_at' => now()]);
        }
    }

    /** Store automatically scored criterion rows (the final answer); teacher-scored rows are untouched. */
    public function syncAutoDetails(GradingResult $result, GradeOutcome $outcome): void
    {
        foreach ($outcome->details as $detail) {
            GradingDetail::updateOrCreate(
                ['grading_result_id' => $result->id, 'rubric_criterion_id' => null],
                ['label' => $detail['label'], 'max_points' => $detail['max_points'], 'auto_score' => $detail['score'], 'score' => $detail['score']],
            );
        }
    }

    /** Set the submission to needs_review while any review task is open, otherwise graded. */
    public function refreshStatus(Submission $submission): Submission
    {
        if ($submission->isFinalized()) {
            return $submission;
        }

        $open = $submission->reviewTasks()->where('review_tasks.status', 'open')->exists();
        $submission->update([
            'status' => $open ? 'needs_review' : 'graded',
            'graded_at' => now(),
            'failure_reason' => null,
        ]);

        return $submission;
    }
}
