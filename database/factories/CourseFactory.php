<?php

namespace Database\Factories;

use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

class CourseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'code' => 'C'.fake()->unique()->numerify('###'),
            'title' => 'Course '.fake()->word(),
        ];
    }
}
