<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'exam_version_id' => $this->exam_version_id,
            'source' => $this->source,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'graded_at' => $this->graded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'student' => new StudentResource($this->whenLoaded('student')),
            'answers' => $this->whenLoaded('answers', fn () => $this->answers->map(fn ($a) => [
                'question_id' => $a->question_id,
                'source' => $a->source,
                'raw_value' => $a->raw_value,
                'recognized_value' => $a->normalized_value,
                'corrected_value' => $a->corrected_value,
                'effective_value' => $a->effectiveValue(),
                'confidence' => $a->confidence,
            ])),
            'grading_results' => GradingResultResource::collection($this->whenLoaded('gradingResults')),
            'result' => new ExamResultResource($this->whenLoaded('result')),
        ];
    }
}
