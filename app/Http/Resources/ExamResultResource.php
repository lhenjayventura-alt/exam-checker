<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'submission_id' => $this->submission_id,
            'exam_id' => $this->exam_id,
            'student_id' => $this->student_id,
            'total_score' => $this->total_score,
            'max_score' => $this->max_score,
            'percentage' => $this->percentage,
            'passed' => $this->passed,
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
        ];
    }
}
