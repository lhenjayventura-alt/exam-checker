<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Program;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExamFixtures;
use Tests\TestCase;

/**
 * Server-side permission enforcement on the web dashboard.
 */
class AuthorizationTest extends TestCase
{
    use BuildsExamFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('review-queue'))->assertRedirect(route('login'));
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_teacher_cannot_access_another_teachers_class(): void
    {
        [$offering, $owner, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $owner);
        $this->actingAs($owner)->post(route('exams.submissions.store', $exam), ['student_id' => $students[0]->id, 'answers' => [1 => 'AB']]);
        $submission = Submission::firstOrFail();
        $result = $submission->gradingResults()->first();

        $other = $this->userWithRole(Role::TEACHER);
        $this->actingAs($other);

        $this->get(route('offerings.show', $offering))->assertForbidden();
        $this->get(route('exams.show', $exam))->assertForbidden();
        $this->get(route('submissions.show', $submission))->assertForbidden();
        $this->get(route('exams.results', $exam))->assertForbidden();
        $this->post(route('exams.store', $offering), ['title' => 'x', 'exam_type' => 'quiz', 'passing_percentage' => 50])->assertForbidden();
        $this->post(route('offerings.roster.store', $offering), ['student_number' => 'X-1', 'last_name' => 'A', 'first_name' => 'B'])->assertForbidden();
        $this->post(route('exams.submissions.store', $exam), ['student_id' => $students[1]->id, 'answers' => []])->assertForbidden();
        $this->patch(route('grading-results.update', $result), ['action' => 'override', 'score' => 1, 'reason' => 'x'])->assertForbidden();
        $this->post(route('submissions.finalize', $submission))->assertForbidden();
        $this->post(route('exams.release', $exam))->assertForbidden();

        $this->get(route('offerings.index'))->assertOk()->assertDontSee($offering->section);
        $this->get(route('review-queue'))->assertOk()->assertDontSee($students[0]->student_number);
    }

    public function test_program_chair_can_view_but_not_grade_or_see_the_answer_key(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);
        $this->actingAs($teacher)->post(route('exams.submissions.store', $exam), ['student_id' => $students[0]->id, 'answers' => [1 => 'C', 2 => 'A,C', 3 => 'T']]);
        $submission = Submission::firstOrFail();
        $result = $submission->gradingResults()->first();

        $chair = $this->userWithRole(Role::PROGRAM_CHAIR, $offering->course->program_id);
        $this->actingAs($chair);

        $this->get(route('dashboard'))->assertOk()->assertSee('Program oversight')->assertSee('Synthetic Quiz');
        $this->get(route('exams.show', $exam))->assertOk()->assertSee('Answer keys are visible only')->assertDontSee('Enter answers');
        $this->get(route('submissions.show', $submission))->assertOk()->assertDontSee('key is B')->assertDontSee('Override the score');
        $this->get(route('exams.results', $exam))->assertOk();

        $this->patch(route('grading-results.update', $result), ['action' => 'override', 'score' => 1, 'reason' => 'x'])->assertForbidden();
        $this->post(route('submissions.finalize', $submission))->assertForbidden();
        $this->post(route('exams.release', $exam))->assertForbidden();
        $this->post(route('exams.submissions.store', $exam), ['student_id' => $students[1]->id, 'answers' => []])->assertForbidden();
    }

    public function test_program_chair_of_a_different_program_has_no_access(): void
    {
        [$offering, $teacher] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);
        $otherProgram = Program::factory()->create();

        $this->actingAs($this->userWithRole(Role::PROGRAM_CHAIR, $otherProgram->id))
            ->get(route('exams.show', $exam))->assertForbidden();
    }

    public function test_administrator_manages_setup_but_cannot_grade_by_default(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);
        $this->actingAs($teacher)->post(route('exams.submissions.store', $exam), ['student_id' => $students[0]->id, 'answers' => [1 => 'B']]);
        $result = Submission::firstOrFail()->gradingResults()->first();

        $admin = $this->userWithRole(Role::ADMIN);
        $this->actingAs($admin);

        $this->get(route('admin.academics.index'))->assertOk();
        $this->get(route('admin.users.index'))->assertOk();
        $this->get(route('admin.audit-logs'))->assertOk()->assertSee('exam.published');
        $this->get(route('exams.show', $exam))->assertOk();
        $this->patch(route('grading-results.update', $result), ['action' => 'override', 'score' => 0, 'reason' => 'x'])->assertForbidden();
    }

    public function test_non_admins_cannot_reach_admin_screens(): void
    {
        $teacher = $this->userWithRole(Role::TEACHER);
        $this->actingAs($teacher);

        $this->get(route('admin.users.index'))->assertForbidden();
        $this->get(route('admin.academics.index'))->assertForbidden();
        $this->get(route('admin.audit-logs'))->assertForbidden();
        $this->post(route('admin.programs.store'), ['code' => 'X', 'name' => 'Y'])->assertForbidden();
    }

    public function test_admin_creates_users_with_scoped_roles_and_assigns_teachers(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $this->actingAs($admin);

        $this->post(route('admin.programs.store'), ['code' => 'BSCPE', 'name' => 'Computer Engineering'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('admin.terms.store'), ['academic_year' => '2026-2027', 'term' => 'first', 'is_current' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('admin.terms.store'), ['academic_year' => '2026-2028', 'term' => 'second'])->assertSessionHasErrors('academic_year');
        $program = Program::where('code', 'BSCPE')->firstOrFail();
        $this->post(route('admin.courses.store'), ['program_id' => $program->id, 'code' => 'CPE101', 'title' => 'Intro'])->assertSessionHasNoErrors()->assertRedirect();

        // Chair role without a program is rejected.
        $this->post(route('admin.users.store'), [
            'name' => 'Chair', 'email' => 'chair@example.test', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!',
            'is_active' => 1, 'roles' => [Role::PROGRAM_CHAIR],
        ])->assertSessionHasErrors('chair_program_ids');

        $this->post(route('admin.users.store'), [
            'name' => 'Teach', 'email' => 'teach@example.test', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!',
            'is_active' => 1, 'roles' => [Role::TEACHER],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $teacher = User::where('email', 'teach@example.test')->firstOrFail();
        $this->assertTrue($teacher->isTeacher());

        $this->post(route('admin.offerings.store'), [
            'course_id' => Course::first()->id,
            'academic_term_id' => AcademicTerm::first()->id,
            'section' => 'CPE-1A',
            'teacher_ids' => [$teacher->id, $admin->id], // admin is not a teacher
        ])->assertSessionHasErrors('teacher_ids.1');

        $this->post(route('admin.offerings.store'), [
            'course_id' => Course::first()->id,
            'academic_term_id' => AcademicTerm::first()->id,
            'section' => 'CPE-1A',
            'teacher_ids' => [$teacher->id],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(CourseOffering::firstOrFail()->isTaughtBy($teacher));

        // An admin cannot remove their own admin role.
        $this->put(route('admin.users.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'is_active' => 1, 'roles' => [Role::TEACHER]])
            ->assertSessionHasErrors('roles');
    }

    public function test_deactivated_users_cannot_log_in_and_are_signed_out(): void
    {
        $user = $this->userWithRole(Role::TEACHER);
        $user->update(['is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
