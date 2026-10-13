<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AcademicTermFactory extends Factory
{
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2000, 2099);

        return ['academic_year' => $year.'-'.($year + 1), 'term' => 'first', 'is_current' => false];
    }
}
