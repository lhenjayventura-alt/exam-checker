<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed reference data required in every environment. Demo data is separate:
     * php artisan db:seed --class=DemoSeeder (local development only).
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
    }
}
