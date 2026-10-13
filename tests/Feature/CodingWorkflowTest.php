<?php

namespace Tests\Feature;

use App\Models\CodeExecutionResult;
use App\Models\Exam;
use App\Models\GradingResult;
use App\Models\Question;
use App\Models\Role;
use App\Models\Submission;
use App\Services\CodeExecution\CodeRunner;
use App\Services\Scanning\AnswerSheetService;
use App\Services\Scanning\SheetRecognizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsExamFixtures;
use Tests\Fakes\FakeCodeRunner;
use Tests\Fakes\FakeSheetRecognizer;
use Tests\TestCase;

class CodingWorkflowTest extends TestCase
{
    use BuildsExamFixtures, RefreshDatabase;

    private FakeCodeRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->runner = new FakeCodeRunner;
        $this->app->instance(CodeRunner::class, $this->runner);
    }

    /** Q1 MC (1 pt). Q2 coding, C: three tests (2 visible + 1 hidden, 2 pt each) + optional rubric. */
    private function codingExam(array $criteria = [], int $students = 3): array
    {
        [$offering, $teacher, $roster] = $this->offeringWithTeacher($students);
        $this->actingAs($teacher);
        $this->post(route('exams.store', $offering), ['title' => 'Programming Quiz', 'exam_type' => 'quiz', 'passing_percentage' => 50]);
        $exam = Exam::latest('id')->firstOrFail();
        $this->post(route('exams.questions.store', $exam), ['type' => 'multiple_choice', 'points' => 1,
            'options' => [['label' => 'A'], ['label' => 'B']], 'correct' => ['A']])->assertSessionHasNoErrors();
        $this->post(route('exams.questions.store', $exam), [
            'type' => 'coding', 'points' => 6 + collect($criteria)->sum('max_points'), 'language' => 'c',
            'time_limit_ms' => 1000, 'memory_mb' => 64, 'comparison' => 'trim',
            'prompt' => 'Read integers and print their sum.',
            'tests' => [
                ['name' => 'Two numbers', 'input' => "2 3\n", 'expected_output' => "5\n", 'points' => 2, 'hidden' => '0'],
                ['name' => 'Negatives', 'input' => "-4 1\n", 'expected_output' => "-3\n", 'points' => 2, 'hidden' => '0'],
                ['name' => 'Many', 'input' => "1 2 3 4\n", 'expected_output' => "10\n", 'points' => 2, 'hidden' => '1'],
            ],
            'criteria' => $criteria,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('exams.publish', $exam))->assertSessionHasNoErrors();

        return [$exam->fresh(), $teacher, $roster];
    }

    private function submit(Exam $exam, $student, string $code, array $extra = [])
    {
        return $this->post(route('exams.submissions.store', $exam), ['student_id' => $student->id, 'answers' => [1 => 'A', 2 => $code], ...$extra]);
    }

    private function codingResult(Submission $submission): GradingResult
    {
        return $submission->gradingResults()->whereHas('question', fn ($q) => $q->where('type', 'coding'))->firstOrFail();
    }

    public function test_coding_question_is_created_with_test_cases(): void
    {
        [$exam] = $this->codingExam();
        $q = Question::where('type', 'coding')->with('testCases')->firstOrFail();

        $this->assertSame('c', $q->setting('language'));
        $this->assertSame(1000, $q->setting('time_limit_ms'));
        $this->assertSame(6.0, $q->answerPoints());
        $this->assertSame([false, false, true], $q->testCases->pluck('is_hidden')->all());
        $this->assertNull($q->answerKey);
        $this->get(route('exams.show', $exam))->assertOk()->assertSee('Negatives')->assertSee('hidden');
    }

    public function test_coding_validation(): void
    {
        [$offering, $teacher] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher, publish: false);
        $this->actingAs($teacher);
        $base = ['type' => 'coding', 'points' => 4, 'language' => 'c'];

        $this->post(route('exams.questions.store', $exam), [...$base, 'tests' => [['name' => 'a', 'expected_output' => '1', 'points' => 1]]])
            ->assertSessionHasErrors('tests'); // 1 ≠ 4
        $this->post(route('exams.questions.store', $exam), [...$base, 'language' => 'java', 'tests' => [['name' => 'a', 'points' => 4]]])
            ->assertSessionHasErrors('language');
        $this->post(route('exams.questions.store', $exam), [...$base, 'time_limit_ms' => 60000, 'tests' => [['name' => 'a', 'points' => 4]]])
            ->assertSessionHasErrors('time_limit_ms');
        $this->post(route('exams.questions.store', $exam), [...$base, 'tests' => [['name' => 'a', 'points' => 2]],
            'criteria' => [['label' => 'Style', 'max_points' => 2]]])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_code_is_run_against_tests_and_scored_per_test(): void
    {
        // A "program" that fails the negatives test (ignores minus signs).
        $this->app->instance(CodeRunner::class, $this->runner = new FakeCodeRunner(function (string $input) {
            preg_match_all('/\d+/', $input, $m);

            return array_sum($m[0])."\n";
        }));
        [$exam, , $roster] = $this->codingExam();
        $code = "#include <stdio.h>\nint main(void) {\n    int a, s = 0;\n    while (scanf(\"%d\", &a) == 1) s += a;\n    printf(\"%d\\n\", s);\n}\n";

        $this->submit($exam, $roster[0], $code)->assertSessionHasNoErrors();

        $submission = Submission::firstOrFail();
        $this->assertSame('graded', $submission->status);
        $result = $this->codingResult($submission);
        $this->assertSame(4.0, $result->awarded_score);
        $this->assertFalse($result->is_correct);
        $this->assertSame('2 of 3 test(s) passed (4 of 6 pt).', $result->explanation);

        // Code stored verbatim (indentation kept); only a fingerprint in normalized_value.
        $answer = $submission->answers()->where('question_id', $result->question_id)->firstOrFail();
        $this->assertSame($code, $answer->raw_value);
        $this->assertSame('sha256:'.hash('sha256', $code), $answer->normalized_value);

        // Compile step, then one row per test.
        $this->assertSame(['compile', 'test', 'test', 'test'], CodeExecutionResult::orderBy('id')->pluck('step')->all());
        $this->assertSame(['passed', 'passed', 'failed', 'passed'], CodeExecutionResult::orderBy('id')->pluck('status')->all());

        // Limits passed to the sandbox come from the question; expected outputs are not sent.
        $call = $this->runner->calls[0];
        $this->assertSame(['time_ms' => 1000, 'memory_mb' => 64, 'output_kb' => 64], $call['limits']);
        $this->assertSame(["2 3\n", "-4 1\n", "1 2 3 4\n"], array_values($call['inputs']));

        $this->get(route('submissions.show', $submission))->assertOk()
            ->assertSee('int a, s = 0;')->assertSee('expected vs. actual')->assertSee('2 of 3 test(s) passed');
    }

    public function test_compile_error_scores_zero_and_records_compiler_output(): void
    {
        [$exam, , $roster] = $this->codingExam();
        $this->submit($exam, $roster[0], 'int main( { COMPILE_ERROR');

        $result = $this->codingResult(Submission::firstOrFail());
        $this->assertSame(0.0, $result->awarded_score);
        $this->assertSame('Compilation failed; no tests were run.', $result->explanation);
        $compile = CodeExecutionResult::where('step', 'compile')->firstOrFail();
        $this->assertSame('compile_error', $compile->status);
        $this->assertStringContainsString("expected ';'", $compile->stderr);
        $this->assertSame(3, CodeExecutionResult::where('status', 'skipped')->count());
    }

    public function test_timeouts_score_zero_on_the_affected_tests(): void
    {
        [$exam, , $roster] = $this->codingExam();
        $this->submit($exam, $roster[0], 'while(1); TIMEOUT');

        $this->assertSame(0.0, $this->codingResult(Submission::firstOrFail())->awarded_score);
        $this->assertSame(3, CodeExecutionResult::where('status', 'timeout')->count());
    }

    public function test_sandbox_outage_fails_the_job_and_never_scores_zero(): void
    {
        [$exam, , $roster] = $this->codingExam();
        try {
            $this->submit($exam, $roster[0], 'int main(){} INFRA');
        } catch (\Throwable) {
            // The synchronous test queue rethrows after marking the job failed.
        }

        $submission = Submission::firstOrFail();
        $this->assertSame('failed', $submission->status);
        $this->assertStringContainsString('Docker daemon not reachable', $submission->failure_reason);
        $this->assertSame(0, GradingResult::where('submission_id', $submission->id)->whereHas('question', fn ($q) => $q->where('type', 'coding'))->count());

        // Once the sandbox is back, reprocessing grades it.
        $answer = $submission->answers()->whereHas('question', fn ($q) => $q->where('type', 'coding'))->firstOrFail();
        $answer->update(['raw_value' => 'fixed program']);
        $this->post(route('submissions.reprocess', $submission))->assertSessionHasNoErrors();
        $this->assertSame(6.0, $this->codingResult($submission->fresh())->awarded_score);
    }

    public function test_source_file_upload(): void
    {
        [$exam, , $roster] = $this->codingExam();

        $this->post(route('exams.submissions.store', $exam), [
            'student_id' => $roster[0]->id, 'answers' => [1 => 'A'],
            'answer_files' => [2 => UploadedFile::fake()->createWithContent('sum.c', "int main(){}\n")],
        ])->assertSessionHasNoErrors();
        $this->assertSame("int main(){}\n", $this->runner->calls[0]['source']);

        $this->post(route('exams.submissions.store', $exam), [
            'student_id' => $roster[1]->id, 'answer_files' => [1 => UploadedFile::fake()->createWithContent('a.c', 'x')],
        ])->assertSessionHasErrors('answer_files.1'); // not a coding question
        $this->post(route('exams.submissions.store', $exam), [
            'student_id' => $roster[1]->id, 'answer_files' => [2 => UploadedFile::fake()->createWithContent('a.exe', "MZ\0\0")],
        ])->assertSessionHasErrors('answer_files.2');
        $this->assertSame(1, Submission::count());
    }

    public function test_code_quality_rubric_and_teacher_provided_code(): void
    {
        [$exam, , $roster] = $this->codingExam([['label' => 'Readable code', 'max_points' => 2]]);
        $this->submit($exam, $roster[0], '');

        $submission = Submission::firstOrFail();
        $result = $this->codingResult($submission);
        $this->assertSame('needs_review', $result->status);
        $this->assertSame(0.0, $result->proposed_score);
        $this->assertSame('No code submitted. Code quality (2 pt) requires rubric scoring.', $result->explanation);

        // The teacher provides the code (e.g. emailed by the student); tests re-run on the queue.
        $this->patch(route('grading-results.update', $result), ['action' => 'correct_answer', 'code' => "int main(){}\n", 'reason' => 'Submitted by email'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $result->refresh();
        $this->assertSame(6.0, $result->proposed_score);
        $this->assertSame('needs_review', $result->status);
        $this->assertSame('Code quality must be scored with the rubric.', $result->openReviewTask->reason);
        $this->assertDatabaseHas('grading_details', ['grading_result_id' => $result->id, 'label' => 'Automated tests', 'auto_score' => 6]);
        $this->assertDatabaseHas('grading_events', ['event' => 'answer_corrected', 'new_answer' => 'sha256:'.hash('sha256', "int main(){}\n")]);

        $criterion = Question::where('type', 'coding')->firstOrFail()->rubricCriteria->first();
        $this->patch(route('grading-results.update', $result), ['action' => 'score_rubric', 'criteria' => [$criterion->id => ['score' => 1.5]]])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(7.5, $result->fresh()->awarded_score);
        $this->assertSame('graded', $submission->fresh()->status);
    }

    public function test_scanned_sheets_leave_coding_answers_for_the_teacher(): void
    {
        Storage::fake('local');
        $recognizer = new FakeSheetRecognizer;
        $this->app->instance(SheetRecognizer::class, $recognizer);
        [$exam, , $roster] = $this->codingExam();

        $sheets = app(AnswerSheetService::class);
        $layout = $sheets->layoutFor($exam->currentVersion);
        $this->assertSame([1], array_column($layout->layout['items'], 'number'), 'Coding questions are not printed as bubbles or boxes.');

        $recognizer->queuePages([['qr' => $sheets->qrPayload($layout, $roster[0]->id, 1), 'answers' => ['1' => FakeSheetRecognizer::bubbles(['A'])]]]);
        $this->post(route('exams.scans.store', $exam), ['scan' => UploadedFile::fake()->createWithContent('s.pdf', "%PDF-1.4\nx")]);

        $result = $this->codingResult(Submission::firstOrFail());
        $this->assertSame("Coding answer is not on the paper sheet: provide the student's code.", $result->openReviewTask->reason);
        $this->assertSame([], $this->runner->calls);
    }

    public function test_hidden_tests_are_only_visible_to_graders_via_api(): void
    {
        [$exam, $teacher] = $this->codingExam();

        Sanctum::actingAs($teacher);
        $tests = $this->getJson("/api/v1/exams/{$exam->id}")->assertOk()->json('data.questions.1.test_cases');
        $this->assertCount(3, $tests);
        $this->assertSame("10\n", $tests[2]['expected_output']);

        Sanctum::actingAs($this->userWithRole(Role::PROGRAM_CHAIR, $exam->offering->course->program_id));
        $question = $this->getJson("/api/v1/exams/{$exam->id}")->assertOk()->json('data.questions.1');
        $this->assertArrayNotHasKey('test_cases', $question);
        $this->assertSame('c', $question['coding']['language']);
    }

    public function test_api_submission_with_code_and_execution_details(): void
    {
        [$exam, $teacher, $roster] = $this->codingExam();
        Sanctum::actingAs($teacher);

        $id = $this->postJson("/api/v1/exams/{$exam->id}/submissions", ['student_id' => $roster[0]->id, 'answers' => ['1' => 'B', '2' => "print(sum(map(int, input().split())))\n"]])
            ->assertStatus(202)->json('data.id');
        $resultId = $this->codingResult(Submission::findOrFail($id))->id;

        $this->getJson("/api/v1/grading-results/{$resultId}")->assertOk()
            ->assertJsonPath('data.awarded_score', 6)
            ->assertJsonPath('data.executions.0.step', 'compile')
            ->assertJsonCount(4, 'data.executions');
    }

    public function test_crlf_from_browser_textareas_becomes_lf(): void
    {
        [$offering, $teacher, $roster] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher, publish: false);
        $this->actingAs($teacher)->post(route('exams.questions.store', $exam), [
            'type' => 'coding', 'points' => 1, 'language' => 'python',
            'tests' => [['name' => 'a', 'input' => "  2 3\r\n", 'expected_output' => "  5\r\n", 'points' => 1]],
        ])->assertSessionHasNoErrors();
        $test = Question::where('type', 'coding')->firstOrFail()->testCases->first();
        $this->assertSame("  2 3\n", $test->input, 'Leading spaces are kept; CRLF becomes LF.');
        $this->assertSame("  5\n", $test->expected_output);

        $this->post(route('exams.publish', $exam));
        $this->post(route('exams.submissions.store', $exam), ['student_id' => $roster[0]->id, 'answers' => [4 => "if True:\r\n    print(1)\r\n"]]);
        $this->assertSame("if True:\n    print(1)\n", $this->runner->calls[0]['source']);
    }

    public function test_new_version_copies_test_cases(): void
    {
        [$exam] = $this->codingExam();
        $this->post(route('exams.versions.store', $exam))->assertSessionHasNoErrors();

        $copy = $exam->fresh()->currentVersion->questions()->where('type', 'coding')->firstOrFail();
        $this->assertSame(['Two numbers', 'Negatives', 'Many'], $copy->testCases->pluck('name')->all());
        $this->assertSame("10\n", $copy->testCases[2]->expected_output);
    }
}
