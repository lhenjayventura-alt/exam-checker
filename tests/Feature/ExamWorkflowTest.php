<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\GradingEvent;
use App\Models\GradingResult;
use App\Models\ReviewTask;
use App\Models\Student;
use App\Models\Submission;
use App\Services\ExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExamFixtures;
use Tests\TestCase;

/**
 * The first vertical slice end to end through the web dashboard:
 * build exam → publish → enter answers → automatic grading → review → finalize → release → results.
 */
class ExamWorkflowTest extends TestCase
{
    use BuildsExamFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_teacher_builds_and_publishes_an_exam_through_the_dashboard(): void
    {
        [$offering, $teacher] = $this->offeringWithTeacher();

        $this->actingAs($teacher)->post(route('exams.store', $offering), [
            'title' => 'Prelim Exam', 'exam_type' => 'prelim', 'passing_percentage' => 50,
        ])->assertRedirect();
        $exam = Exam::firstOrFail();
        $this->assertSame('draft', $exam->status);
        $this->assertSame(1, $exam->currentVersion->version_number);

        $this->post(route('exams.questions.store', $exam), [
            'type' => 'multiple_choice', 'points' => 2,
            'options' => [['label' => 'A', 'text' => 'x'], ['label' => 'B', 'text' => 'y'], ['label' => 'C', 'text' => '']],
            'correct' => ['C'],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('exams.questions.store', $exam), [
            'type' => 'true_false', 'points' => 1, 'correct' => ['false'],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $questions = $exam->currentVersion->questions()->with('answerKey')->get();
        $this->assertSame([1, 2], $questions->pluck('number')->all());
        $this->assertSame(['C'], $questions[0]->answerKey->correct_values);
        $this->assertSame(['false'], $questions[1]->answerKey->correct_values);

        $this->post(route('exams.publish', $exam))->assertSessionHasNoErrors()->assertRedirect();
        $exam->refresh();
        $this->assertSame('published', $exam->status);
        $this->assertNotNull($exam->currentVersion->locked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'exam.published', 'auditable_id' => $exam->id]);

        // Locked: no further question edits.
        $this->post(route('exams.questions.store', $exam), ['type' => 'true_false', 'points' => 1, 'correct' => ['true']])
            ->assertSessionHasErrors('exam');
        $this->assertSame(2, $exam->currentVersion->questions()->count());
    }

    public function test_question_validation_rejects_inconsistent_answer_keys(): void
    {
        [$offering, $teacher] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher, publish: false);
        $this->actingAs($teacher);

        $options = [['label' => 'A'], ['label' => 'B']];
        $this->post(route('exams.questions.store', $exam), ['type' => 'multiple_choice', 'points' => 1, 'options' => $options, 'correct' => ['D']])
            ->assertSessionHasErrors('correct');
        $this->post(route('exams.questions.store', $exam), ['type' => 'multiple_choice', 'points' => 1, 'options' => $options, 'correct' => ['A', 'B']])
            ->assertSessionHasErrors('correct');
        $this->post(route('exams.questions.store', $exam), ['type' => 'multiple_choice', 'points' => 1, 'options' => [['label' => 'A']], 'correct' => ['A']])
            ->assertSessionHasErrors('options');
        $this->post(route('exams.questions.store', $exam), ['type' => 'true_false', 'points' => 1, 'correct' => ['yes']])
            ->assertSessionHasErrors('correct');
        $this->post(route('exams.questions.store', $exam), ['type' => 'coding', 'points' => 1, 'correct' => ['x']])
            ->assertSessionHasErrors('type');
        $this->post(route('exams.questions.store', $exam), ['type' => 'true_false', 'points' => 0, 'correct' => ['true']])
            ->assertSessionHasErrors('points');

        $this->assertSame(3, $exam->currentVersion->questions()->count());
    }

    public function test_publishing_an_empty_exam_is_refused(): void
    {
        [$offering, $teacher] = $this->offeringWithTeacher();
        $this->actingAs($teacher)->post(route('exams.store', $offering), ['title' => 'Empty', 'exam_type' => 'quiz', 'passing_percentage' => 60]);
        $exam = Exam::firstOrFail();

        $this->post(route('exams.publish', $exam))->assertSessionHasErrors('exam');
        $this->assertSame('draft', $exam->fresh()->status);
    }

    public function test_digital_answers_are_graded_automatically_and_finalized(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);

        $this->actingAs($teacher)->post(route('exams.submissions.store', $exam), [
            'student_id' => $students[0]->id,
            'answers' => [1 => 'b', 2 => 'a, b, c', 3 => 'T'],
        ])->assertRedirect();

        $submission = Submission::firstOrFail();
        // Queue is synchronous in tests, so grading has already run.
        $this->assertSame('graded', $submission->status);

        $scores = $submission->gradingResults()->with('question')->get()->mapWithKeys(fn ($r) => [$r->question->number => $r->awarded_score]);
        $this->assertSame([1 => 1.0, 2 => 1.0, 3 => 1.0], $scores->all()); // Q2: 2 hits − 1 miss = 1/2 × 2

        // Original input is preserved next to the normalized value.
        $this->assertDatabaseHas('recognized_answers', ['submission_id' => $submission->id, 'raw_value' => 'a, b, c', 'normalized_value' => 'A,B,C']);
        $this->assertSame(3, GradingEvent::where('event', 'auto_graded')->count());

        $this->post(route('submissions.finalize', $submission))->assertSessionHasNoErrors()->assertRedirect();
        $result = ExamResult::firstOrFail();
        $this->assertSame(3.0, $result->total_score);
        $this->assertSame(4.0, $result->max_score);
        $this->assertSame(75.0, $result->percentage);
        $this->assertTrue($result->passed);
        $this->assertSame('finalized', $submission->fresh()->status);
    }

    public function test_ambiguous_answer_requires_review_before_finalization(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);
        $this->actingAs($teacher)->post(route('exams.submissions.store', $exam), [
            'student_id' => $students[0]->id,
            'answers' => [1 => 'A B', 2 => 'AC', 3 => 'maybe'],
        ]);
        $submission = Submission::firstOrFail();

        $this->assertSame('needs_review', $submission->status);
        $this->assertSame(2, ReviewTask::where('status', 'open')->count()); // Q1 double mark, Q3 unreadable
        $this->get(route('review-queue'))->assertOk()->assertSee('More than one option');

        $this->post(route('submissions.finalize', $submission))->assertSessionHasErrors('submission');
        $this->assertDatabaseCount('exam_results', 0);

        $q1 = $this->resultFor($submission, 1);
        $q3 = $this->resultFor($submission, 3);

        // Correcting the answer re-grades deterministically and keeps the original.
        $this->patch(route('grading-results.update', $q1), ['action' => 'correct_answer', 'answer' => 'b', 'reason' => 'Erasure on A'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $q1->refresh();
        $this->assertSame(1.0, $q1->awarded_score);
        $this->assertSame('reviewed', $q1->status);
        $this->assertDatabaseHas('recognized_answers', ['question_id' => $q1->question_id, 'normalized_value' => 'A,B', 'corrected_value' => 'B']);

        // An override without a reason is refused; with a reason it is recorded with the previous score.
        $this->patch(route('grading-results.update', $q3), ['action' => 'override', 'score' => 1])->assertSessionHasErrors('reason');
        $this->patch(route('grading-results.update', $q3), ['action' => 'override', 'score' => 5, 'reason' => 'x'])->assertSessionHasErrors('score');
        $this->patch(route('grading-results.update', $q3), ['action' => 'override', 'score' => 1, 'reason' => 'Student wrote "Tama"'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $event = GradingEvent::where('event', 'score_overridden')->firstOrFail();
        $this->assertSame($teacher->id, $event->user_id);
        $this->assertSame(0.0, $event->previous_score);
        $this->assertSame(1.0, $event->new_score);
        $this->assertSame('Student wrote "Tama"', $event->reason);
        $this->assertTrue(AuditLog::where('action', 'grading.score_overridden')->exists());

        $this->assertSame(0, ReviewTask::where('status', 'open')->count());
        $this->assertSame('graded', $submission->fresh()->status);

        $this->post(route('submissions.finalize', $submission))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(4.0, ExamResult::firstOrFail()->total_score);
    }

    public function test_correction_that_is_still_ungradable_is_refused(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);
        $this->actingAs($teacher)->post(route('exams.submissions.store', $exam), ['student_id' => $students[0]->id, 'answers' => [1 => 'AB']]);
        $q1 = $this->resultFor(Submission::firstOrFail(), 1);

        $this->patch(route('grading-results.update', $q1), ['action' => 'correct_answer', 'answer' => 'A,D'])->assertSessionHasErrors('answer');
        $this->assertSame('needs_review', $q1->fresh()->status);
        $this->assertNull($q1->fresh()->awarded_score);
    }

    public function test_finalized_scores_cannot_be_changed_and_release_is_recorded(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);
        $this->actingAs($teacher);
        foreach ($students as $student) {
            $this->post(route('exams.submissions.store', $exam), ['student_id' => $student->id, 'answers' => [1 => 'B', 2 => 'A,C', 3 => 'F']]);
        }

        $this->post(route('exams.finalize-all', $exam))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(3, ExamResult::count());

        $q1 = $this->resultFor(Submission::firstOrFail(), 1);
        $this->patch(route('grading-results.update', $q1), ['action' => 'override', 'score' => 0, 'reason' => 'late change'])
            ->assertSessionHasErrors('submission');
        $this->assertSame(1.0, $q1->fresh()->awarded_score);

        $this->post(route('exams.release', $exam))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(3, ExamResult::whereNotNull('released_at')->where('released_by', $teacher->id)->count());
        $this->post(route('exams.release', $exam))->assertSessionHasErrors('exam'); // nothing left to release
    }

    public function test_duplicate_submission_for_the_same_student_is_refused(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);
        $this->actingAs($teacher);

        $this->post(route('exams.submissions.store', $exam), ['student_id' => $students[0]->id, 'answers' => [1 => 'B']]);
        $this->post(route('exams.submissions.store', $exam), ['student_id' => $students[0]->id, 'answers' => [1 => 'C']])
            ->assertSessionHasErrors('student_id');

        $this->assertSame(1, Submission::count());
        $this->assertSame(3, GradingResult::count());
    }

    public function test_submissions_require_a_published_exam_and_a_rostered_student(): void
    {
        [$offering, $teacher] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher, publish: false);
        $outsider = Student::factory()->create();
        $this->actingAs($teacher);

        $this->post(route('exams.submissions.store', $exam), ['student_id' => $offering->students()->first()->id, 'answers' => []])
            ->assertSessionHasErrors('exam');

        app(ExamService::class)->publish($exam, $teacher);
        $this->post(route('exams.submissions.store', $exam), ['student_id' => $outsider->id, 'answers' => []])
            ->assertSessionHasErrors('student_id');
        $this->post(route('exams.submissions.store', $exam), ['student_id' => $offering->students()->first()->id, 'answers' => [99 => 'A']])
            ->assertSessionHasErrors('answers');

        $this->assertSame(0, Submission::count());
    }

    public function test_dashboard_results_and_export_pages_render(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);
        $this->actingAs($teacher);
        $this->post(route('exams.submissions.store', $exam), ['student_id' => $students[0]->id, 'answers' => [1 => 'B', 2 => 'A,C', 3 => 'T']]);
        $this->post(route('exams.finalize-all', $exam));

        $this->get(route('dashboard'))->assertOk()->assertSee('My classes');
        $this->get(route('offerings.show', $offering))->assertOk()->assertSee($students[0]->student_number);
        $this->get(route('exams.show', $exam))->assertOk()->assertSee('Synthetic Quiz');
        $this->get(route('exams.submissions.create', $exam))->assertOk();
        $this->get(route('submissions.show', Submission::first()))->assertOk()->assertSee('Per-question grading');
        $this->get(route('exams.results', $exam))->assertOk()->assertSee('Item analysis')->assertSee('Small sample');

        $csv = $this->get(route('exams.results.export', $exam))->assertOk()->streamedContent();
        $this->assertStringContainsString($students[0]->student_number.','.$students[0]->last_name, $csv);
        $this->assertStringContainsString(',4,4,100,Yes,', $csv);
    }

    private function resultFor(Submission $submission, int $number): GradingResult
    {
        return $submission->gradingResults()->whereHas('question', fn ($q) => $q->where('number', $number))->firstOrFail();
    }
}
