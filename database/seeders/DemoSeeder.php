<?php

namespace Database\Seeders;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Program;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * SYNTHETIC demo data for local development only. Every name and student number is fictitious.
 *
 * Demo accounts (password for all: "password"):
 *   admin@demo.test    Administrator
 *   chair@demo.test    Program Chair (DEMO program)
 *   teacher@demo.test  Teacher, assigned to DEMO-101 section A
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }

        $this->call(RoleSeeder::class);
        $roles = Role::pluck('id', 'slug');

        $program = Program::firstOrCreate(['code' => 'DEMO'], ['name' => 'Demonstration Program (synthetic)']);
        $term = AcademicTerm::firstOrCreate(
            ['academic_year' => '2026-2027', 'term' => 'first'],
            ['is_current' => true],
        );
        $course = Course::firstOrCreate(['program_id' => $program->id, 'code' => 'DEMO-101'], ['title' => 'Introduction to Demonstrations']);
        $offering = CourseOffering::firstOrCreate(['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => 'A']);

        $make = function (string $email, string $name, array $roleSlugs, ?int $programId = null) use ($roles) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password', 'is_active' => true]);
            foreach ($roleSlugs as $slug) {
                $pivot = $slug === Role::PROGRAM_CHAIR ? $programId : null;
                if (! $user->roles()->where('roles.id', $roles[$slug])->wherePivot('program_id', $pivot)->exists()) {
                    $user->roles()->attach($roles[$slug], ['program_id' => $pivot]);
                }
            }

            return $user;
        };

        $make('admin@demo.test', 'Demo Administrator', [Role::ADMIN]);
        $make('chair@demo.test', 'Demo Program Chair', [Role::PROGRAM_CHAIR], $program->id);
        $teacher = $make('teacher@demo.test', 'Demo Teacher', [Role::TEACHER]);
        $offering->teachers()->syncWithoutDetaching([$teacher->id]);

        $names = [
            ['Alpha', 'Ana'], ['Bravo', 'Ben'], ['Charlie', 'Carla'], ['Delta', 'Dan'], ['Echo', 'Ella'],
            ['Foxtrot', 'Finn'], ['Golf', 'Gia'], ['Hotel', 'Hugo'], ['India', 'Iris'], ['Juliet', 'Jon'],
            ['Kilo', 'Kai'], ['Lima', 'Lea'],
        ];
        foreach ($names as $i => [$last, $first]) {
            $student = Student::firstOrCreate(
                ['student_number' => sprintf('DEMO-%04d', $i + 1)],
                ['last_name' => $last, 'first_name' => $first, 'program_id' => $program->id],
            );
            $offering->students()->syncWithoutDetaching([$student->id]);
        }
    }
}
