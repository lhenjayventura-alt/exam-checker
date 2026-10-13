<?php

namespace Tests\Feature;

use App\Jobs\GradeSubmission;
use App\Models\GradingEvent;
use App\Models\GradingResult;
use App\Models\ReviewTask;
use App\Models\Submission;
use App\Services\Grading\GradingOrchestrator;
use App\Services\ReviewService;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsExamFixtures;
use Tests\TestCase;

class GradingEngineTest extends TestCase
{
    use BuildsExamFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_submission_queues_a_grading_job(): void
    {
        Queue::fake();
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);

        $submission = app(SubmissionService::class)->createDigital($exam, $students[0], [1 => 'B'], $teacher);

        $this->assertSame('pending', $submission->status);
        Queue::assertPushed(GradeSubmission::class, fn ($job) => $job->submissionId === $submission->id);
        $this->assertSame(0, GradingResult::count());
    }

    public function test_regrading_is_idempotent_and_preserves_teacher_decisions(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);
        $submission = app(SubmissionService::class)->createDigital($exam, $students[0], [1 => 'A', 2 => 'A,C', 3 => 'T'], $teacher);

        $q1 = $submission->gradingResults()->whereHas('question', fn ($q) => $q->where('number', 1))->firstOrFail();
        app(ReviewService::class)->overrideScore($q1, 0.5, 'Partial credit for shown reasoning', $teacher);

        $eventsBefore = GradingEvent::count();
        $orchestrator = app(GradingOrchestrator::class);
        $orchestrator->grade($submission);
        $orchestrator->grade($submission);

        $this->assertSame(3, GradingResult::where('submission_id', $submission->id)->count());
        $this->assertSame($eventsBefore, GradingEvent::count(), 'Unchanged results must not add history entries.');
        $this->assertSame(0.5, $q1->fresh()->awarded_score);
        $this->assertSame('overridden', $q1->fresh()->status);
    }

    public function test_low_confidence_recognition_goes_to_review_even_when_answer_matches(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);
        $submission = app(SubmissionService::class)->createDigital($exam, $students[0], [1 => 'B', 2 => 'A,C', 3 => 'T'], $teacher);

        // Simulate OCR output for question 1.
        $answer = $submission->answers()->whereHas('question', fn ($q) => $q->where('number', 1))->firstOrFail();
        $answer->update(['source' => 'ocr', 'confidence' => 0.42]);
        app(GradingOrchestrator::class)->grade($submission);

        $q1 = $submission->gradingResults()->where('question_id', $answer->question_id)->firstOrFail();
        $this->assertSame('needs_review', $q1->status);
        $this->assertSame(1.0, $q1->proposed_score);
        $this->assertNull($q1->awarded_score);
        $this->assertStringContainsString('Low recognition confidence (0.42', $q1->openReviewTask->reason);
        $this->assertSame('needs_review', $submission->fresh()->status);

        // Teacher confirms the proposed score.
        app(ReviewService::class)->confirm($q1, $teacher, 'Checked the paper');
        $this->assertSame(1.0, $q1->fresh()->awarded_score);
        $this->assertSame('graded', $submission->fresh()->status);
        $this->assertSame(0, ReviewTask::where('status', 'open')->count());
    }

    public function test_job_failure_marks_submission_failed_with_reason(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);
        Queue::fake();
        $submission = app(SubmissionService::class)->createDigital($exam, $students[0], [], $teacher);

        (new GradeSubmission($submission->id))->failed(new \RuntimeException('OCR service unavailable'));

        $submission->refresh();
        $this->assertSame('failed', $submission->status);
        $this->assertStringContainsString('OCR service unavailable', $submission->failure_reason);

        Queue::fake();
        app(SubmissionService::class)->reprocess($submission);
        $this->assertSame('pending', $submission->fresh()->status);
        Queue::assertPushed(GradeSubmission::class);
    }

    public function test_blank_submission_scores_zero_without_review(): void
    {
        [$offering, $teacher, $students] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher);
        $submission = app(SubmissionService::class)->createDigital($exam, $students[0], [], $teacher);

        $this->assertSame('graded', $submission->fresh()->status);
        $this->assertSame(0.0, (float) GradingResult::sum('awarded_score'));
        $this->assertSame(3, Submission::first()->answers()->whereNull('raw_value')->count());
    }
}
