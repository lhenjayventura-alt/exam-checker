<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** Synthetic student records only. */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_number' => 'TEST-'.fake()->unique()->numerify('######'),
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_name' => null,
        ];
    }
}
