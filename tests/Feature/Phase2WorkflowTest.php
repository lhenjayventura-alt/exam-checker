<?php

namespace Tests\Feature;

use App\Exports\ExamResultsExport;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\GradingDetail;
use App\Models\GradingEvent;
use App\Models\GradingResult;
use App\Models\Question;
use App\Models\ReviewTask;
use App\Models\Role;
use App\Models\Submission;
use App\Support\SpreadsheetCell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsExamFixtures;
use Tests\TestCase;

/**
 * Identification and problem-solving questions, rubric scoring, versioning and exports.
 */
class Phase2WorkflowTest extends TestCase
{
    use BuildsExamFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** Q1 identification (2 pt, fuzzy), Q2 problem solving (5 pt: 2 final answer + rubric 2 + 1, V required, ±1%). */
    private function phase2Exam($offering, $teacher, bool $publish = true): Exam
    {
        $this->actingAs($teacher);
        $this->post(route('exams.store', $offering), ['title' => 'Circuits Quiz', 'exam_type' => 'quiz', 'passing_percentage' => 60]);
        $exam = Exam::latest('id')->firstOrFail();

        $this->post(route('exams.questions.store', $exam), [
            'type' => 'identification', 'points' => 2, 'fuzzy' => 1,
            'accepted_answers' => "Ohm's law\nOhms law",
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('exams.questions.store', $exam), [
            'type' => 'problem_solving', 'points' => 5, 'expected' => 12, 'tolerance' => 1, 'tolerance_type' => 'percent',
            'units' => 'V, volts', 'unit_required' => 1, 'answer_points' => 2,
            'criteria' => [
                ['label' => 'Correct formula', 'description' => 'V = IR', 'max_points' => 2],
                ['label' => 'Substitution', 'max_points' => 1],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        if ($publish) {
            $this->post(route('exams.publish', $exam))->assertSessionHasNoErrors()->assertRedirect();
        }

        return $exam->fresh();
    }

    private function resultFor(Submission $submission, int $number): GradingResult
    {
        return $submission->gradingResults()->whereHas('question', fn ($q) => $q->where('number', $number))->firstOrFail();
    }

    public function test_question_validation_for_new_types(): void
    {
        [$offering, $teacher] = $this->offeringWithTeacher();
        $exam = $this->phase2Exam($offering, $teacher, publish: false);

        $this->post(route('exams.questions.store', $exam), ['type' => 'identification', 'points' => 1, 'accepted_answers' => "  \n "])
            ->assertSessionHasErrors('accepted_answers');
        $this->post(route('exams.questions.store', $exam), [
            'type' => 'problem_solving', 'points' => 5, 'expected' => 3, 'answer_points' => 2,
            'criteria' => [['label' => 'Method', 'max_points' => 2]],
        ])->assertSessionHasErrors('criteria'); // 2 + 2 ≠ 5
        $this->post(route('exams.questions.store', $exam), ['type' => 'problem_solving', 'points' => 1, 'expected' => 'twelve'])
            ->assertSessionHasErrors('expected');
        $this->post(route('exams.questions.store', $exam), ['type' => 'problem_solving', 'points' => 1, 'expected' => 1, 'unit_required' => 1])
            ->assertSessionHasErrors('units');

        $q2 = Question::where('number', 2)->with(['answerKey', 'rubricCriteria'])->firstOrFail();
        $this->assertSame(['12'], $q2->answerKey->correct_values);
        $this->assertSame(['V', 'volts'], $q2->setting('units'));
        $this->assertSame(2.0, $q2->answerPoints());
        $this->assertSame(['Correct formula', 'Substitution'], $q2->rubricCriteria->pluck('label')->all());
        $this->assertSame(["Ohm's law", 'Ohms law'], Question::where('number', 1)->first()->answerKey->correct_values);

        $this->get(route('exams.show', $exam))->assertOk()->assertSee('Accepted:')->assertSee('Correct formula')->assertSee('V = IR');
    }

    public function test_identification_and_rubric_scoring_through_to_final_result(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->phase2Exam($offering, $teacher);

        $this->post(route('exams.submissions.store', $exam), [
            'student_id' => $students[0]->id,
            'answers' => [1 => 'ohms lw', 2 => '12.1 V'],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $submission = Submission::firstOrFail();
        $this->assertSame('needs_review', $submission->status);

        $q1 = $this->resultFor($submission, 1);
        $q2 = $this->resultFor($submission, 2);
        $this->assertStringContainsString('Possible misspelling', $q1->openReviewTask->reason);
        $this->assertSame(2.0, $q2->proposed_score);
        $this->assertNull($q2->awarded_score);
        $this->assertSame('Solution method must be scored with the rubric.', $q2->openReviewTask->reason);
        $this->assertDatabaseHas('grading_details', ['grading_result_id' => $q2->id, 'rubric_criterion_id' => null, 'auto_score' => 2, 'score' => 2]);

        $this->get(route('submissions.show', $submission))->assertOk()->assertSee('Score the solution rubric');

        // Accept the near miss.
        $this->patch(route('grading-results.update', $q1), ['action' => 'confirm', 'reason' => 'Spelling slip'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2.0, $q1->fresh()->awarded_score);

        // Confirming a rubric question's proposed score is refused: it covers only the final answer.
        $this->patch(route('grading-results.update', $q2), ['action' => 'confirm'])->assertSessionHasErrors('score');

        $criteria = Question::where('number', 2)->firstOrFail()->rubricCriteria->pluck('id')->all();
        $this->patch(route('grading-results.update', $q2), ['action' => 'score_rubric', 'criteria' => [$criteria[0] => ['score' => 3]]])
            ->assertSessionHasErrors(["criteria.{$criteria[0]}.score", "criteria.{$criteria[1]}.score"]);
        // Changing the automatically checked final answer needs a reason.
        $this->patch(route('grading-results.update', $q2), [
            'action' => 'score_rubric', 'answer_score' => 1,
            'criteria' => [$criteria[0] => ['score' => 2], $criteria[1] => ['score' => 0.5]],
        ])->assertSessionHasErrors('reason');

        $this->patch(route('grading-results.update', $q2), [
            'action' => 'score_rubric',
            'criteria' => [$criteria[0] => ['score' => 2, 'comment' => 'Correct'], $criteria[1] => ['score' => 0.5, 'comment' => 'Units dropped mid-way']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $q2->refresh();
        $this->assertSame(4.5, $q2->awarded_score);
        $this->assertSame('reviewed', $q2->status);
        $this->assertSame(3, GradingDetail::where('grading_result_id', $q2->id)->count());
        $this->assertTrue(GradingEvent::where('event', 'rubric_scored')->where('new_score', 4.5)->exists());
        $this->assertSame('graded', $submission->fresh()->status);

        $this->post(route('submissions.finalize', $submission))->assertSessionHasNoErrors()->assertRedirect();
        $result = ExamResult::firstOrFail();
        $this->assertSame(6.5, $result->total_score);
        $this->assertSame(7.0, $result->max_score);
        $this->assertSame(92.86, $result->percentage);
    }

    public function test_correcting_a_rubric_question_answer_keeps_the_rubric_pending(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->phase2Exam($offering, $teacher);
        $this->post(route('exams.submissions.store', $exam), ['student_id' => $students[0]->id, 'answers' => [1 => 'Ohms law', 2 => '12']]);
        $q2 = $this->resultFor(Submission::firstOrFail(), 2);

        $this->assertSame('The required unit is missing from the final answer.', $q2->openReviewTask->reason);

        $this->patch(route('grading-results.update', $q2), ['action' => 'correct_answer', 'answer' => '12 V', 'reason' => 'Unit on next line'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $q2->refresh();
        $this->assertSame('needs_review', $q2->status);
        $this->assertSame('Solution method must be scored with the rubric.', $q2->openReviewTask->reason);
        $this->assertSame(1, ReviewTask::where('grading_result_id', $q2->id)->where('status', 'open')->count());
        $this->assertDatabaseHas('recognized_answers', ['question_id' => $q2->question_id, 'normalized_value' => '12', 'corrected_value' => '12 V']);
        $this->assertSame(2.0, GradingDetail::where('grading_result_id', $q2->id)->whereNull('rubric_criterion_id')->value('score'));
    }

    public function test_unreadable_final_answer_must_be_scored_by_the_teacher(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->phase2Exam($offering, $teacher);
        $this->post(route('exams.submissions.store', $exam), ['student_id' => $students[0]->id, 'answers' => [2 => 'see work']]);
        $q2 = $this->resultFor(Submission::firstOrFail(), 2);
        $criteria = Question::where('number', 2)->firstOrFail()->rubricCriteria->pluck('id')->all();
        $scores = [$criteria[0] => ['score' => 1], $criteria[1] => ['score' => 1]];

        $this->patch(route('grading-results.update', $q2), ['action' => 'score_rubric', 'criteria' => $scores])
            ->assertSessionHasErrors('answer_score');
        $this->patch(route('grading-results.update', $q2), ['action' => 'score_rubric', 'answer_score' => 0, 'criteria' => $scores, 'reason' => 'No final value'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2.0, $q2->fresh()->awarded_score);
    }

    public function test_new_version_keeps_earlier_submissions_and_analytics_are_per_version(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher); // v1: 3 questions, 4 points
        $this->actingAs($teacher);
        $this->post(route('exams.submissions.store', $exam), ['student_id' => $students[0]->id, 'answers' => [1 => 'B', 2 => 'A,C', 3 => 'T']]);
        $this->post(route('exams.finalize-all', $exam));

        $this->post(route('exams.versions.store', $exam))->assertSessionHasNoErrors()->assertRedirect();
        $exam->refresh();
        $this->assertSame('draft', $exam->status);
        $this->assertSame(2, $exam->currentVersion->version_number);
        $this->assertSame(3, $exam->currentVersion->questions()->count());
        $this->assertSame(['B'], $exam->currentVersion->questions()->where('number', 1)->first()->answerKey->correct_values);

        // Edit v2: drop question 3, then publish.
        $q3 = $exam->currentVersion->questions()->where('number', 3)->firstOrFail();
        $this->delete(route('exams.questions.destroy', [$exam, $q3]))->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('exams.publish', $exam))->assertSessionHasNoErrors()->assertRedirect();

        $this->post(route('exams.submissions.store', $exam), ['student_id' => $students[1]->id, 'answers' => [1 => 'C', 2 => 'A,C']]);
        $this->post(route('exams.finalize-all', $exam));

        $v1 = Submission::where('student_id', $students[0]->id)->firstOrFail();
        $v2 = Submission::where('student_id', $students[1]->id)->firstOrFail();
        $this->assertSame(1, $v1->version->version_number);
        $this->assertSame(4.0, $v1->result->max_score);
        $this->assertSame(2, $v2->version->version_number);
        $this->assertSame(3.0, $v2->result->max_score);

        $this->get(route('exams.results', $exam))->assertOk()->assertSee('version 2', false);
        $this->get(route('exams.results', [$exam, 'version' => 1]))->assertOk()->assertSee('version 1', false)->assertSee($students[0]->student_number);
        $this->get(route('exams.results', [$exam, 'version' => 9]))->assertNotFound();

        Sanctum::actingAs($teacher);
        $this->getJson("/api/v1/exams/{$exam->id}/analytics?version=1")->assertOk()->assertJsonPath('data.mean', 4)->assertJsonPath('data.version', 1);
        $this->getJson("/api/v1/exams/{$exam->id}/analytics")->assertOk()->assertJsonPath('data.mean', 2)->assertJsonPath('data.version', 2);

        $csv = $this->get(route('exams.results.export', $exam))->assertOk()->streamedContent();
        $this->assertStringContainsString('Version', $csv);
        $this->assertStringContainsString($students[0]->student_number.','.$students[0]->last_name.','.$students[0]->first_name.',1,4,4,100', $csv);
    }

    public function test_new_version_requires_a_published_exam(): void
    {
        [$offering, $teacher] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher, publish: false);

        $this->actingAs($teacher)->post(route('exams.versions.store', $exam))->assertSessionHasErrors('exam');
        $this->actingAs($this->userWithRole(Role::TEACHER))->post(route('exams.versions.store', $exam))->assertForbidden();
    }

    public function test_excel_export_contains_score_sheet_and_item_analysis(): void
    {
        Excel::fake();
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);
        $this->actingAs($teacher)->post(route('exams.submissions.store', $exam), ['student_id' => $students[0]->id, 'answers' => [1 => 'B']]);
        $this->post(route('exams.finalize-all', $exam));

        $this->get(route('exams.results.export', [$exam, 'format' => 'xlsx']))->assertOk();

        $filename = str($offering->course->code.'-'.$offering->section.'-'.$exam->title)->slug().'-results.xlsx';
        Excel::assertDownloaded($filename, function (ExamResultsExport $export) use ($students) {
            $sheets = $export->sheets();
            $this->assertCount(2, $sheets); // score sheet + item analysis for v1
            $this->assertSame(['Score sheet', 'Items v1'], array_map(fn ($s) => $s->title(), $sheets));
            $rows = $sheets[0]->array();
            $this->assertSame($students[0]->student_number, $rows[0][0]);
            $this->assertSame(1, $rows[0][3]); // version
            $this->assertCount(3, array_filter($sheets[1]->array(), fn ($r) => is_int($r[0] ?? null))); // one row per item

            return true;
        });
    }

    public function test_spreadsheet_cells_neutralize_formulas(): void
    {
        $this->assertSame("'=HYPERLINK(1)", SpreadsheetCell::safe('=HYPERLINK(1)'));
        $this->assertSame("'-1+2", SpreadsheetCell::safe('-1+2'));
        $this->assertSame('Dela Cruz', SpreadsheetCell::safe('Dela Cruz'));
        $this->assertSame(-1.5, SpreadsheetCell::safe(-1.5));
    }

    public function test_api_creates_problem_question_and_scores_rubric(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        Sanctum::actingAs($teacher);
        $examId = $this->postJson("/api/v1/offerings/{$offering->id}/exams", ['title' => 'API', 'exam_type' => 'quiz', 'passing_percentage' => 50])->json('data.id');

        $this->postJson("/api/v1/exams/{$examId}/questions", [
            'type' => 'problem_solving', 'points' => 3, 'expected' => 0.5, 'units' => ['A'], 'answer_points' => 1,
            'criteria' => [['label' => 'Method', 'max_points' => 2]],
        ])->assertCreated()->assertJsonPath('data.answer_key', ['0.5'])->assertJsonPath('data.rubric.0.label', 'Method');
        $this->postJson("/api/v1/exams/{$examId}/publish")->assertOk();

        $this->postJson("/api/v1/exams/{$examId}/submissions", ['student_id' => $students[0]->id, 'answers' => ['1' => '500e-3 A']])->assertStatus(202);
        $task = $this->getJson('/api/v1/review-tasks')->assertOk()->json('data.0');
        $criterionId = Question::firstOrFail()->rubricCriteria->first()->id;

        $this->patchJson("/api/v1/grading-results/{$task['grading_result_id']}", [
            'action' => 'score_rubric', 'criteria' => [$criterionId => ['score' => 1.5, 'comment' => 'ok']],
        ])->assertOk()
            ->assertJsonPath('data.awarded_score', 2.5)
            ->assertJsonPath('data.details.0.label', 'Final answer')
            ->assertJsonPath('data.details.1.score', 1.5);
    }
}
