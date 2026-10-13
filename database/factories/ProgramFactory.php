<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProgramFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'P'.fake()->unique()->numerify('####'),
            'name' => 'Program '.fake()->unique()->word(),
        ];
    }
}
