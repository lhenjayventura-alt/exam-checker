<?php

namespace Tests\Concerns;

use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\ExamService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Collection;

/**
 * Synthetic fixtures for exam workflow tests. No real student data.
 */
trait BuildsExamFixtures
{
    protected function seedRoles(): void
    {
        $this->seed(RoleSeeder::class);
    }

    protected function userWithRole(string $slug, ?int $programId = null): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', $slug)->value('id'), ['program_id' => $programId]);

        return $user;
    }

    /** @return array{0: CourseOffering, 1: User, 2: Collection<int, Student>} */
    protected function offeringWithTeacher(int $students = 3): array
    {
        $teacher = $this->userWithRole(Role::TEACHER);
        $offering = CourseOffering::factory()->create();
        $offering->teachers()->attach($teacher);
        $roster = Student::factory()->count($students)->create();
        $offering->students()->attach($roster->pluck('id'));

        return [$offering, $teacher, $roster];
    }

    /**
     * Exam with: Q1 MC (A–D, key B, 1 pt), Q2 MC multiple-select (A–D, key A,C, 2 pts, proportional),
     * Q3 TF (key true, 1 pt). Total 4 points.
     */
    protected function publishedExam(CourseOffering $offering, User $teacher, bool $publish = true): Exam
    {
        $service = app(ExamService::class);
        $exam = $service->create($offering, [
            'title' => 'Synthetic Quiz', 'exam_type' => 'quiz', 'passing_percentage' => 60,
        ], $teacher);

        $options = fn () => array_map(fn ($l) => ['label' => $l, 'text' => "Option {$l}"], ['A', 'B', 'C', 'D']);
        $service->addQuestion($exam, ['type' => 'multiple_choice', 'points' => 1, 'options' => $options(), 'correct' => ['B']]);
        $service->addQuestion($exam, [
            'type' => 'multiple_choice', 'points' => 2, 'options' => $options(), 'correct' => ['A', 'C'],
            'multiple_select' => true, 'partial_credit' => 'proportional',
        ]);
        $service->addQuestion($exam, ['type' => 'true_false', 'points' => 1, 'correct' => ['true']]);

        return $publish ? $service->publish($exam, $teacher) : $exam->fresh();
    }
}
