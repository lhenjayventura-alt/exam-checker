<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradingResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'submission_id' => $this->submission_id,
            'question_id' => $this->question_id,
            'question_number' => $this->whenLoaded('question', fn () => $this->question->number),
            'grader' => $this->grader,
            'max_points' => $this->max_points,
            'proposed_score' => $this->proposed_score,
            'awarded_score' => $this->awarded_score,
            'is_correct' => $this->is_correct,
            'status' => $this->status,
            'explanation' => $this->explanation,
            'feedback' => $this->feedback,
            'details' => $this->whenLoaded('details', fn () => $this->details->map(fn ($d) => [
                'rubric_criterion_id' => $d->rubric_criterion_id,
                'label' => $d->label,
                'max_points' => $d->max_points,
                'auto_score' => $d->auto_score,
                'score' => $d->score,
                'comment' => $d->comment,
            ])),
            'executions' => $this->whenLoaded('executions', fn () => $this->executions->map(fn ($e) => [
                'step' => $e->step,
                'test_case_id' => $e->coding_test_case_id,
                'status' => $e->status,
                'points' => $e->points,
                'exit_code' => $e->exit_code,
                'duration_ms' => $e->duration_ms,
                'stdout' => $e->stdout,
                'stderr' => $e->stderr,
            ])),
            'review_reason' => $this->whenLoaded('openReviewTask', fn () => $this->openReviewTask?->reason),
            'history' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'event' => $e->event,
                'user_id' => $e->user_id,
                'previous_score' => $e->previous_score,
                'new_score' => $e->new_score,
                'previous_answer' => $e->previous_answer,
                'new_answer' => $e->new_answer,
                'reason' => $e->reason,
                'at' => $e->created_at?->toIso8601String(),
            ])),
        ];
    }
}
