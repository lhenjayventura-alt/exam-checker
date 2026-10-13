<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $version = $this->whenLoaded('currentVersion', fn () => $this->currentVersion);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'instructions' => $this->instructions,
            'exam_type' => $this->exam_type,
            'exam_date' => $this->exam_date?->toDateString(),
            'passing_percentage' => (float) $this->passing_percentage,
            'status' => $this->status,
            'offering' => new OfferingResource($this->whenLoaded('offering')),
            'version' => $this->whenLoaded('currentVersion', fn () => [
                'id' => $version->id,
                'number' => $version->version_number,
                'locked' => $version->isLocked(),
                'total_points' => $version->relationLoaded('questions')
                    ? (float) $version->questions->sum('points')
                    : $version->totalPoints(),
            ]),
            'questions' => $this->whenLoaded('currentVersion', fn () => $version->relationLoaded('questions')
                ? QuestionResource::collection($version->questions)
                : null),
        ];
    }
}
