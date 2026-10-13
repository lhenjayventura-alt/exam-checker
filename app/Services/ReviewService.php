<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\GradingEvent;
use App\Models\GradingResult;
use App\Models\RecognizedAnswer;
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
        };
    }

    /**
     * Replace the recognized answer with a teacher correction and re-grade deterministically.
     * The original recognized value is preserved on the answer row.
     */
    public function correctAnswer(GradingResult $result, ?string $value, User $teacher, ?string $reason = null): GradingResult
    {
        return $this->mutate($result, $teacher, function (GradingResult $result) use ($value, $teacher, $reason) {
            $question = $result->question()->with(['options', 'answerKey'])->first();
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
            if ($outcome->needsReview) {
                throw ValidationException::withMessages([
                    'answer' => 'The corrected answer still cannot be graded automatically: '.$outcome->reviewReason.' Override the score instead.',
                ]);
            }

            $previousScore = $result->awarded_score;
            $result->update([
                'proposed_score' => $outcome->score,
                'awarded_score' => $outcome->score,
                'is_correct' => $outcome->isCorrect,
                'explanation' => $outcome->explanation,
                'status' => 'reviewed',
            ]);

            $this->recordEvent($result, $teacher, 'answer_corrected', $previousScore, $previousAnswer, $answer->corrected_value, $reason);

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

        return $this->mutate($result, $teacher, function (GradingResult $result) use ($teacher, $note) {
            $previousScore = $result->awarded_score;
            $result->update(['awarded_score' => $result->proposed_score, 'status' => 'reviewed']);
            $this->recordEvent($result, $teacher, 'review_confirmed', $previousScore, null, null, $note);

            return $result;
        });
    }

    /** Run a change inside a transaction with the submission locked, then close review tasks and refresh status. */
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

            $result->reviewTasks()->where('status', 'open')->update([
                'status' => 'resolved',
                'resolved_by' => $teacher->id,
                'resolved_at' => now(),
            ]);
            $this->orchestrator->refreshStatus($submission);

            return $result->fresh();
        });
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
