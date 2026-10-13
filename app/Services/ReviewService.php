<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\GradingDetail;
use App\Models\GradingEvent;
use App\Models\GradingResult;
use App\Models\RecognizedAnswer;
use App\Models\ReviewTask;
use App\Models\User;
use App\Services\Grading\GraderRegistry;
use App\Services\Grading\GradingOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Teacher decisions on individual grading results. Every change is recorded as a
 * grading event (score history) and an audit log entry.
 */
class ReviewService
{
    public function __construct(
        private readonly GraderRegistry $graders,
        private readonly GradingOrchestrator $orchestrator,
    ) {}

    /** Apply a validated review action (see UpdateGradingResultRequest). */
    public function apply(GradingResult $result, array $data, User $teacher): GradingResult
    {
        return match ($data['action']) {
            'correct_answer' => $this->correctAnswer($result, $data['answer'] ?? null, $teacher, $data['reason'] ?? null),
            'override' => $this->overrideScore($result, (float) $data['score'], (string) $data['reason'], $teacher, $data['feedback'] ?? null),
            'confirm' => $this->confirm($result, $teacher, $data['reason'] ?? null),
            'score_rubric' => $this->scoreRubric(
                $result,
                isset($data['answer_score']) ? (float) $data['answer_score'] : null,
                $data['criteria'] ?? [],
                $teacher,
                $data['reason'] ?? null,
                $data['feedback'] ?? null,
            ),
        };
    }

    /**
     * Replace the recognized answer with a teacher correction and re-grade deterministically.
     * The original recognized value is preserved on the answer row. For problem-solving
     * questions with a rubric, the final-answer score is updated and the rubric stays to be scored.
     */
    public function correctAnswer(GradingResult $result, ?string $value, User $teacher, ?string $reason = null): GradingResult
    {
        return $this->mutate($result, $teacher, function (GradingResult $result) use ($value, $teacher, $reason) {
            $question = $result->question()->with(['options', 'answerKey', 'rubricCriteria'])->first();
            $answer = RecognizedAnswer::firstOrNew(
                ['submission_id' => $result->submission_id, 'question_id' => $question->id],
                ['source' => 'manual'],
            );
            $previousAnswer = $answer->effectiveValue();

            $answer->fill([
                'corrected_value' => $this->graders->normalize($question, $value),
                'corrected_by' => $teacher->id,
                'corrected_at' => now(),
            ])->save();

            [, $outcome] = $this->orchestrator->evaluate($question, $answer);
            if ($outcome->needsReview && ! $outcome->rubricPending) {
                throw ValidationException::withMessages([
                    'answer' => 'The corrected answer still cannot be graded automatically: '.$outcome->reviewReason.' Override the score instead.',
                ]);
            }

            $previousScore = $result->awarded_score;
            $this->orchestrator->syncAutoDetails($result, $outcome);

            if ($outcome->rubricPending) {
                // Keep any rubric scores already given; finish only when every criterion is scored.
                $details = $result->details()->get();
                $scoredCriteria = $details->whereNotNull('rubric_criterion_id')->whereNotNull('score');
                $complete = $scoredCriteria->count() === $question->rubricCriteria->count();

                $result->update([
                    'proposed_score' => $outcome->score,
                    'awarded_score' => $complete ? round($details->sum('score'), 2) : null,
                    'is_correct' => null,
                    'explanation' => $outcome->explanation,
                    'status' => $complete ? 'reviewed' : 'needs_review',
                ]);
                if (! $complete) {
                    $this->ensureOpenTask($result, $outcome->reviewReason);
                }
            } else {
                $result->update([
                    'proposed_score' => $outcome->score,
                    'awarded_score' => $outcome->score,
                    'is_correct' => $outcome->isCorrect,
                    'explanation' => $outcome->explanation,
                    'status' => 'reviewed',
                ]);
            }

            $this->recordEvent($result, $teacher, 'answer_corrected', $previousScore, $previousAnswer, $answer->corrected_value, $reason);

            return $result;
        });
    }

    /**
     * Score a problem-solving question criterion by criterion.
     *
     * @param  array<int|string, array{score: float|int|string, comment?: ?string}>  $criteria  Keyed by rubric criterion id.
     * @param  ?float  $answerScore  Final-answer score; null keeps the automatic score. Changing it requires a reason.
     */
    public function scoreRubric(GradingResult $result, ?float $answerScore, array $criteria, User $teacher, ?string $reason = null, ?string $feedback = null): GradingResult
    {
        $question = $result->question()->with('rubricCriteria')->firstOrFail();
        $rubric = $question->rubricCriteria->keyBy('id');
        if ($rubric->isEmpty()) {
            throw ValidationException::withMessages(['criteria' => 'This question has no rubric. Correct the answer or override the score instead.']);
        }

        $errors = [];
        $given = collect($criteria)->mapWithKeys(fn ($row, $id) => [(int) $id => $row]);
        foreach ($rubric as $id => $criterion) {
            $score = $given->get($id)['score'] ?? null;
            if ($score === null || $score === '' || ! is_numeric($score)) {
                $errors["criteria.{$id}.score"] = "Enter a score for \"{$criterion->label}\".";
            } elseif ($score < 0 || $score > $criterion->max_points) {
                $errors["criteria.{$id}.score"] = "\"{$criterion->label}\" must be between 0 and {$criterion->max_points}.";
            }
        }
        if ($given->keys()->diff($rubric->keys())->isNotEmpty()) {
            $errors['criteria'] = 'Scores were submitted for criteria that are not on this rubric.';
        }

        $answerDetail = $result->details()->whereNull('rubric_criterion_id')->first();
        $answerMax = $question->answerPoints();
        if ($answerScore !== null && ($answerScore < 0 || $answerScore > $answerMax)) {
            $errors['answer_score'] = "The final-answer score must be between 0 and {$answerMax}.";
        }
        $finalAnswer = $answerScore ?? $answerDetail?->score;
        if ($answerMax > 0 && $finalAnswer === null) {
            $errors['answer_score'] = 'The final answer could not be checked automatically; enter its score.';
        }
        $changesAuto = $answerDetail && $answerScore !== null && abs($answerScore - (float) $answerDetail->auto_score) > 0.0001;
        if ($changesAuto && trim((string) $reason) === '') {
            $errors['reason'] = 'A reason is required when changing the automatically checked final-answer score.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->mutate($result, $teacher, function (GradingResult $result) use ($rubric, $given, $finalAnswer, $answerMax, $answerDetail, $changesAuto, $teacher, $reason, $feedback) {
            if ($answerMax > 0) {
                GradingDetail::updateOrCreate(
                    ['grading_result_id' => $result->id, 'rubric_criterion_id' => null],
                    ['label' => 'Final answer', 'max_points' => $answerMax, 'auto_score' => $answerDetail?->auto_score, 'score' => round($finalAnswer, 2)],
                );
            }
            foreach ($rubric as $id => $criterion) {
                GradingDetail::updateOrCreate(
                    ['grading_result_id' => $result->id, 'rubric_criterion_id' => $id],
                    [
                        'label' => $criterion->label,
                        'max_points' => $criterion->max_points,
                        'score' => round((float) $given[$id]['score'], 2),
                        'comment' => $given[$id]['comment'] ?? null,
                    ],
                );
            }

            $previousScore = $result->awarded_score;
            $result->update([
                'awarded_score' => round($result->details()->sum('score'), 2),
                'status' => $changesAuto ? 'overridden' : 'reviewed',
                'feedback' => $feedback ?? $result->feedback,
            ]);
            $this->recordEvent($result, $teacher, 'rubric_scored', $previousScore, null, null, $reason);

            return $result;
        });
    }

    /** Award a teacher-determined score. A reason is mandatory. */
    public function overrideScore(GradingResult $result, float $score, string $reason, User $teacher, ?string $feedback = null): GradingResult
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for a score override.']);
        }
        if ($score < 0 || $score > $result->max_points) {
            throw ValidationException::withMessages(['score' => "The score must be between 0 and {$result->max_points}."]);
        }

        return $this->mutate($result, $teacher, function (GradingResult $result) use ($score, $reason, $teacher, $feedback) {
            $previousScore = $result->awarded_score ?? $result->proposed_score;
            $result->update([
                'awarded_score' => round($score, 2),
                'status' => 'overridden',
                'feedback' => $feedback ?? $result->feedback,
            ]);
            $this->recordEvent($result, $teacher, 'score_overridden', $previousScore, null, null, $reason);

            return $result;
        });
    }

    /** Accept the automatically proposed score after inspecting a flagged result. */
    public function confirm(GradingResult $result, User $teacher, ?string $note = null): GradingResult
    {
        if ($result->proposed_score === null) {
            throw ValidationException::withMessages(['score' => 'There is no proposed score to confirm; enter a score instead.']);
        }
        if ($result->question->rubricCriteria()->exists()) {
            throw ValidationException::withMessages(['score' => 'The proposed score covers only the final answer. Score the rubric instead.']);
        }

        return $this->mutate($result, $teacher, function (GradingResult $result) use ($teacher, $note) {
            $previousScore = $result->awarded_score;
            $result->update(['awarded_score' => $result->proposed_score, 'status' => 'reviewed']);
            $this->recordEvent($result, $teacher, 'review_confirmed', $previousScore, null, null, $note);

            return $result;
        });
    }

    /**
     * Run a change inside a transaction with the submission locked. Review tasks are closed
     * unless the result still needs review, then the submission status is refreshed.
     */
    private function mutate(GradingResult $result, User $teacher, callable $change): GradingResult
    {
        return DB::transaction(function () use ($result, $teacher, $change) {
            $submission = $result->submission()->lockForUpdate()->firstOrFail();
            if ($submission->isFinalized()) {
                throw ValidationException::withMessages([
                    'submission' => 'This submission is finalized. Released or finalized scores cannot be changed in this workflow.',
                ]);
            }

            $result = $change($result->fresh());

            if ($result->status !== 'needs_review') {
                $result->reviewTasks()->where('status', 'open')->update([
                    'status' => 'resolved',
                    'resolved_by' => $teacher->id,
                    'resolved_at' => now(),
                ]);
            }
            $this->orchestrator->refreshStatus($submission);

            return $result->fresh();
        });
    }

    private function ensureOpenTask(GradingResult $result, string $reason): void
    {
        $task = $result->reviewTasks()->where('status', 'open')->first();
        $task ? $task->update(['reason' => $reason]) : ReviewTask::create(['grading_result_id' => $result->id, 'reason' => $reason]);
    }

    private function recordEvent(GradingResult $result, User $teacher, string $event, ?float $previousScore, ?string $previousAnswer, ?string $newAnswer, ?string $reason): void
    {
        GradingEvent::create([
            'grading_result_id' => $result->id,
            'user_id' => $teacher->id,
            'event' => $event,
            'previous_score' => $previousScore,
            'new_score' => $result->awarded_score,
            'previous_answer' => $previousAnswer,
            'new_answer' => $newAnswer,
            'reason' => $reason,
        ]);

        AuditLog::record('grading.'.$event, $result,
            ['score' => $previousScore, 'answer' => $previousAnswer],
            ['score' => $result->awarded_score, 'answer' => $newAnswer, 'reason' => $reason, 'by' => $teacher->id],
        );
    }
}
