<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'section' => $this->section,
            'course' => $this->whenLoaded('course', fn () => [
                'id' => $this->course->id,
                'code' => $this->course->code,
                'title' => $this->course->title,
            ]),
            'term' => $this->whenLoaded('term', fn () => [
                'id' => $this->term->id,
                'academic_year' => $this->term->academic_year,
                'term' => $this->term->term,
                'label' => $this->term->label(),
            ]),
        ];
    }
}
