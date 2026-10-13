<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsExamFixtures;
use Tests\TestCase;

class ApiWorkflowTest extends TestCase
{
    use BuildsExamFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_login_issues_a_token_and_logout_revokes_it(): void
    {
        $teacher = $this->userWithRole(Role::TEACHER);

        $this->postJson('/api/v1/auth/login', ['email' => $teacher->email, 'password' => 'wrong', 'device_name' => 'phone'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $token = $this->postJson('/api/v1/auth/login', ['email' => $teacher->email, 'password' => 'password', 'device_name' => 'phone'])
            ->assertOk()
            ->assertJsonPath('user.roles.0.slug', Role::TEACHER)
            ->assertJsonStructure(['token', 'expires_at'])
            ->json('token');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', $teacher->email);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $teacher->tokens()->count());
    }

    public function test_deactivated_user_cannot_obtain_a_token(): void
    {
        $user = $this->userWithRole(Role::TEACHER);
        $user->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'phone'])
            ->assertStatus(422);
    }

    public function test_unauthenticated_requests_receive_401_json(): void
    {
        $this->getJson('/api/v1/exams')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_full_api_workflow_from_exam_creation_to_analytics(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        Sanctum::actingAs($teacher);

        $examId = $this->postJson("/api/v1/offerings/{$offering->id}/exams", [
            'title' => 'API Quiz', 'exam_type' => 'quiz', 'passing_percentage' => 50,
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->postJson("/api/v1/exams/{$examId}/questions", [
            'type' => 'multiple_choice', 'points' => 2,
            'options' => [['label' => 'a'], ['label' => 'b'], ['label' => 'c']], 'correct' => ['b'],
        ])->assertCreated()->assertJsonPath('data.answer_key', ['B']);
        $this->postJson("/api/v1/exams/{$examId}/questions", ['type' => 'true_false', 'points' => 1, 'correct' => ['false']])
            ->assertCreated();
        $this->postJson("/api/v1/exams/{$examId}/questions", ['type' => 'true_false', 'points' => 1, 'correct' => 'maybe'])
            ->assertStatus(422)->assertJsonValidationErrors('correct');

        $this->postJson("/api/v1/exams/{$examId}/publish")->assertOk()
            ->assertJsonPath('data.status', 'published')->assertJsonPath('data.version.locked', true);

        $this->getJson('/api/v1/exams')->assertOk()->assertJsonPath('data.0.id', $examId);
        $this->getJson("/api/v1/offerings/{$offering->id}/students?q=".urlencode(substr($students[0]->student_number, 0, 7)))
            ->assertOk()->assertJsonFragment(['student_number' => $students[0]->student_number]);

        // Submit by student number.
        $submissionId = $this->postJson("/api/v1/exams/{$examId}/submissions", [
            'student_number' => $students[0]->student_number, 'answers' => ['1' => 'b', '2' => 'X'],
        ])->assertStatus(202)->json('data.id');

        $this->getJson("/api/v1/submissions/{$submissionId}/status")->assertOk()
            ->assertJsonPath('data.status', 'needs_review')->assertJsonPath('data.open_review_tasks', 1);

        // A retried upload does not create a duplicate.
        $this->postJson("/api/v1/exams/{$examId}/submissions", ['student_id' => $students[0]->id, 'answers' => ['1' => 'c']])
            ->assertStatus(409)->assertJsonPath('data.id', $submissionId);
        $this->assertSame(1, Submission::count());

        $task = $this->getJson('/api/v1/review-tasks')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(2, $task['question_number']);

        $this->postJson("/api/v1/submissions/{$submissionId}/finalize")->assertStatus(422);

        $this->patchJson("/api/v1/grading-results/{$task['grading_result_id']}", ['action' => 'override', 'score' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->patchJson("/api/v1/grading-results/{$task['grading_result_id']}", ['action' => 'correct_answer', 'answer' => 'F'])
            ->assertOk()->assertJsonPath('data.awarded_score', 1)->assertJsonPath('data.status', 'reviewed');

        $this->postJson("/api/v1/submissions/{$submissionId}/finalize")->assertCreated()
            ->assertJsonPath('data.total_score', 3)->assertJsonPath('data.percentage', 100);
        $this->postJson("/api/v1/exams/{$examId}/release")->assertOk()->assertJsonPath('data.released', 1);

        $this->getJson("/api/v1/exams/{$examId}/analytics")->assertOk()
            ->assertJsonPath('data.n', 1)->assertJsonPath('data.mean', 3)->assertJsonPath('data.small_sample', true);
        $this->getJson("/api/v1/exams/{$examId}/item-analysis")->assertOk()
            ->assertJsonPath('data.items.0.difficulty', 1)
            ->assertJsonPath('data.items.0.discrimination', null)
            ->assertJsonPath('data.items.0.distractors.1.is_key', true);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.open_review_tasks', 0);
    }

    public function test_api_enforces_course_authorization_and_hides_answer_keys(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);

        Sanctum::actingAs($this->userWithRole(Role::TEACHER));
        $this->getJson("/api/v1/exams/{$exam->id}")->assertForbidden();
        $this->postJson("/api/v1/exams/{$exam->id}/submissions", ['student_id' => $students[0]->id, 'answers' => []])->assertForbidden();
        $this->getJson('/api/v1/exams')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($teacher);
        $this->getJson("/api/v1/exams/{$exam->id}")->assertOk()->assertJsonPath('data.questions.0.answer_key', ['B']);
        $this->postJson("/api/v1/exams/{$exam->id}/submissions", ['student_id' => $students[0]->id, 'answers' => ['1' => 'C']])->assertStatus(202);
        $resultId = Submission::firstOrFail()->gradingResults()->first()->id;

        $chair = $this->userWithRole(Role::PROGRAM_CHAIR, $offering->course->program_id);
        Sanctum::actingAs($chair);
        $exam_json = $this->getJson("/api/v1/exams/{$exam->id}")->assertOk()->json('data.questions.0');
        $this->assertArrayNotHasKey('answer_key', $exam_json);
        $this->getJson("/api/v1/grading-results/{$resultId}")->assertForbidden();
        $this->patchJson("/api/v1/grading-results/{$resultId}", ['action' => 'confirm'])->assertForbidden();
        $submission = $this->getJson('/api/v1/submissions/'.Submission::first()->id)->assertOk()->json('data');
        $this->assertArrayNotHasKey('grading_results', $submission);
    }
}
