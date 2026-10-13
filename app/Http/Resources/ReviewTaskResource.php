<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = $this->gradingResult;
        $submission = $result->submission;

        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'grading_result_id' => $result->id,
            'question_number' => $result->question->number,
            'question_type' => $result->question->type,
            'proposed_score' => $result->proposed_score,
            'max_points' => $result->max_points,
            'submission_id' => $submission->id,
            'exam' => ['id' => $submission->exam->id, 'title' => $submission->exam->title],
            'student' => new StudentResource($submission->student),
        ];
    }
}
