<?php

namespace Database\Factories;

use App\Models\AcademicTerm;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

class CourseOfferingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'academic_term_id' => AcademicTerm::factory(),
            'section' => fake()->unique()->bothify('SEC-##?'),
        ];
    }
}
