<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\GradingResult;
use App\Models\Role;
use App\Models\ScanSheet;
use App\Models\ScanUpload;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Services\Scanning\AnswerSheetService;
use App\Services\Scanning\SheetRecognizer;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsExamFixtures;
use Tests\Fakes\FakeSheetRecognizer;
use Tests\TestCase;

/**
 * Scanning workflow with a scripted recognizer (the real Python worker is covered by
 * ScannerIntegrationTest and scanner/tests).
 */
class ScanWorkflowTest extends TestCase
{
    use BuildsExamFixtures, RefreshDatabase;

    private FakeSheetRecognizer $recognizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('local');
        $this->recognizer = new FakeSheetRecognizer;
        $this->app->instance(SheetRecognizer::class, $this->recognizer);
    }

    /** Exam: Q1 MC (key B), Q2 identification ("Ohm's law"), Q3 TF (key true). */
    private function scanExam(int $students = 3): array
    {
        [$offering, $teacher, $roster] = $this->offeringWithTeacher($students);
        $this->actingAs($teacher);
        $this->post(route('exams.store', $offering), ['title' => 'Scan Quiz', 'exam_type' => 'quiz', 'passing_percentage' => 50]);
        $exam = Exam::latest('id')->firstOrFail();
        $this->post(route('exams.questions.store', $exam), ['type' => 'multiple_choice', 'points' => 1,
            'options' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C'], ['label' => 'D']], 'correct' => ['B']]);
        $this->post(route('exams.questions.store', $exam), ['type' => 'identification', 'points' => 2, 'accepted_answers' => "Ohm's law"]);
        $this->post(route('exams.questions.store', $exam), ['type' => 'true_false', 'points' => 1, 'correct' => ['true']]);
        $this->post(route('exams.publish', $exam))->assertSessionHasNoErrors();

        return [$exam->fresh(), $teacher, $roster];
    }

    private function qr(Exam $exam, ?int $studentId, int $page = 1): string
    {
        $service = app(AnswerSheetService::class);

        return $service->qrPayload($service->layoutFor($exam->currentVersion), $studentId, $page);
    }

    private function upload(Exam $exam, string $name = 'scan.pdf')
    {
        return $this->post(route('exams.scans.store', $exam), [
            'scan' => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".uniqid('', true)),
        ]);
    }

    private function sheetAnswers(array $q1, bool $writtenBlank = false, array $q3 = ['T'], float $q1Confidence = 1.0): array
    {
        return [
            '1' => FakeSheetRecognizer::bubbles($q1, $q1Confidence, $q1Confidence < 0.85 ? 'p1-q1.png' : null),
            '2' => FakeSheetRecognizer::written($writtenBlank, 'p1-q2.png'),
            '3' => FakeSheetRecognizer::bubbles($q3),
        ];
    }

    public function test_teacher_downloads_generic_and_named_answer_sheets(): void
    {
        [$exam, , $roster] = $this->scanExam();

        $this->get(route('exams.answer-sheets', $exam))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('exams.answer-sheets', [$exam, 'for' => 'roster']))->assertOk();

        [$generic, $named] = $this->recognizer->rendered;
        $this->assertCount(1, $generic);
        $this->assertNull($generic[0]['name']);
        $this->assertStringContainsString('|0|1|', $generic[0]['qr'][0]);
        $this->assertCount(3, $named);
        $this->assertSame($roster->sortBy(fn ($s) => $s->last_name.' '.$s->first_name)->first()->fullName(), $named[0]['name']);
        $this->assertNotNull(app(AnswerSheetService::class)->verify($named[0]['qr'][0]));

        $this->actingAs($this->userWithRole(Role::TEACHER))->get(route('exams.answer-sheets', $exam))->assertForbidden();
    }

    public function test_sheets_require_a_published_exam(): void
    {
        [$offering, $teacher] = $this->offeringWithTeacher();
        $exam = $this->publishedExam($offering, $teacher, publish: false);

        $this->actingAs($teacher)->get(route('exams.answer-sheets', $exam))->assertSessionHasErrors('exam');
    }

    public function test_named_sheet_is_matched_graded_and_routes_uncertain_answers_to_review(): void
    {
        [$exam, $teacher, $roster] = $this->scanExam();
        $student = $roster[0];
        $this->recognizer->queuePages([
            ['qr' => $this->qr($exam, $student->id), 'layout_id' => '1', 'sheet_page' => 1,
                'answers' => $this->sheetAnswers(['B'], q1Confidence: 0.4)],
        ]);

        $this->upload($exam)->assertRedirect(route('exams.scans.index', $exam));

        $upload = ScanUpload::firstOrFail();
        $this->assertSame('completed', $upload->status);
        Storage::disk('local')->assertExists($upload->path);
        $this->assertStringStartsWith('scans/'.$exam->id.'/originals/', $upload->path);

        $sheet = ScanSheet::firstOrFail();
        $this->assertSame('matched', $sheet->status);
        $submission = Submission::firstOrFail();
        $this->assertSame($student->id, $submission->student_id);
        $this->assertSame('scan', $submission->source);
        $this->assertSame('needs_review', $submission->status);

        $answers = $submission->answers()->with('question')->get()->keyBy(fn ($a) => $a->question->number);
        $this->assertSame('ocr', $answers[1]->source);
        $this->assertSame(0.4, $answers[1]->confidence);
        $this->assertSame(['B' => 0.9], $answers[1]->recognition_meta['fills']);
        $this->assertTrue($answers[2]->needsTranscription());
        $this->assertSame('true', $answers[3]->normalized_value);

        $results = $submission->gradingResults()->with(['question', 'openReviewTask'])->get()->keyBy(fn ($r) => $r->question->number);
        $this->assertStringContainsString('Low recognition confidence (0.40', $results[1]->openReviewTask->reason);
        $this->assertSame(1.0, $results[1]->proposed_score);
        $this->assertSame('Handwritten answer: transcribe it from the scanned image.', $results[2]->openReviewTask->reason);
        $this->assertSame(1.0, $results[3]->awarded_score);

        // Page image and crops are attached and visible on the review page.
        $this->assertSame(1, SubmissionFile::where('kind', 'aligned_page')->count());
        $this->assertSame(2, SubmissionFile::where('kind', 'answer_crop')->count());
        $crop = SubmissionFile::where('kind', 'answer_crop')->where('question_id', $results[2]->question_id)->firstOrFail();
        $this->get(route('submissions.show', $submission))->assertOk()
            ->assertSee(route('submission-files.show', $crop))
            ->assertSee('Handwritten: transcribe from the image');
        $this->get(route('submission-files.show', $crop))->assertOk()->assertHeader('cache-control', 'no-store, private');

        // The teacher transcribes the handwriting and confirms the uncertain bubble.
        $this->patch(route('grading-results.update', $results[2]), ['action' => 'correct_answer', 'answer' => 'Ohms Law', 'reason' => 'Transcribed'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->patch(route('grading-results.update', $results[1]), ['action' => 'confirm', 'reason' => 'Mark is clear on paper'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('graded', $submission->fresh()->status);
        $this->assertSame(4.0, (float) GradingResult::sum('awarded_score'));

        // Another teacher cannot see the files.
        $this->actingAs($this->userWithRole(Role::TEACHER))->get(route('submission-files.show', $crop))->assertForbidden();
    }

    public function test_blank_written_answer_is_scored_zero_without_review(): void
    {
        [$exam, , $roster] = $this->scanExam();
        $this->recognizer->queuePages([['qr' => $this->qr($exam, $roster[0]->id), 'answers' => $this->sheetAnswers(['B'], writtenBlank: true)]]);
        $this->upload($exam);

        $submission = Submission::firstOrFail();
        $this->assertSame('graded', $submission->status);
        $this->assertSame(2.0, (float) $submission->gradingResults()->sum('awarded_score'));
    }

    public function test_generic_sheet_is_assigned_by_the_teacher(): void
    {
        [$exam, , $roster] = $this->scanExam();
        $this->recognizer->queuePages([['qr' => $this->qr($exam, null), 'answers' => $this->sheetAnswers(['C'], writtenBlank: true)]]);
        $this->upload($exam);

        $sheet = ScanSheet::firstOrFail();
        $this->assertSame('needs_student', $sheet->status);
        $this->assertSame(0, Submission::count());
        $this->get(route('exams.scans.index', $exam))->assertOk()->assertSee('Sheets that need you')->assertSee(route('scan-sheets.page', [$sheet, 1]));
        $this->get(route('scan-sheets.page', [$sheet, 1]))->assertOk();

        $this->post(route('scan-sheets.assign', $sheet), ['student_id' => $roster[1]->id])->assertSessionHasNoErrors()->assertRedirect();
        $sheet->refresh();
        $this->assertSame('assigned', $sheet->status);
        $this->assertSame($roster[1]->id, Submission::firstOrFail()->student_id);
        $this->assertSame($sheet->submission_id, Submission::first()->id);
        $this->post(route('scan-sheets.assign', $sheet), ['student_id' => $roster[2]->id])->assertSessionHasErrors('student_id');
    }

    public function test_duplicates_forgeries_and_unreadable_pages_create_no_submissions(): void
    {
        [$exam, $teacher, $roster] = $this->scanExam();
        app(SubmissionService::class)->createDigital($exam, $roster[0], [1 => 'B'], $teacher);
        $forged = preg_replace('/\|[0-9a-f]{12}$/', '|000000000000', $this->qr($exam, $roster[1]->id));

        $this->recognizer->queuePages([
            ['qr' => $this->qr($exam, $roster[0]->id), 'answers' => $this->sheetAnswers(['A'])],  // already has a submission
            ['qr' => $forged, 'answers' => $this->sheetAnswers(['A'])],                             // bad signature
            ['ok' => false, 'error' => 'Could not find all four corner markers.', 'qr' => null],  // unreadable
        ]);
        $this->upload($exam);

        $sheets = ScanSheet::orderBy('first_page')->get();
        $this->assertSame(['duplicate', 'unreadable', 'unreadable'], $sheets->pluck('status')->all());
        $this->assertStringContainsString('already has a submission', $sheets[0]->message);
        $this->assertStringContainsString('not from this system', $sheets[1]->message);
        $this->assertStringContainsString('corner markers', $sheets[2]->message);
        $this->assertSame(1, Submission::count());
        $this->assertSame(['duplicate' => 1, 'unreadable' => 2], ScanUpload::first()->summary);
    }

    public function test_multi_page_sheets_are_grouped_and_incomplete_ones_flagged(): void
    {
        $this->recognizer->pagesPerSheet = 2;
        [$exam, , $roster] = $this->scanExam();
        $a = $this->sheetAnswers(['B'], writtenBlank: true);

        $this->recognizer->queuePages([
            ['qr' => $this->qr($exam, $roster[0]->id, 1), 'answers' => ['1' => $a['1'], '2' => $a['2']]],
            ['qr' => $this->qr($exam, $roster[0]->id, 2), 'answers' => ['3' => $a['3']]],
            ['qr' => $this->qr($exam, $roster[1]->id, 1), 'answers' => ['1' => $a['1'], '2' => $a['2']]], // page 2 missing
        ]);
        $this->upload($exam);

        $sheets = ScanSheet::orderBy('first_page')->get();
        $this->assertCount(2, $sheets);
        $this->assertSame([1, 2], [$sheets[0]->first_page, $sheets[0]->page_count]);
        $this->assertSame('matched', $sheets[0]->status);
        $this->assertSame('unreadable', $sheets[1]->status);
        $this->assertSame('Incomplete sheet: found 1 of 2 page(s).', $sheets[1]->message);
        $this->assertSame(2, Submission::firstOrFail()->files()->where('kind', 'aligned_page')->count());
        $this->assertSame(4.0 - 2.0, (float) Submission::first()->gradingResults()->sum('awarded_score')); // Q2 blank
    }

    public function test_sheets_from_an_older_version_are_rejected(): void
    {
        [$exam, $teacher, $roster] = $this->scanExam();
        $oldQr = $this->qr($exam, $roster[0]->id);
        $this->post(route('exams.versions.store', $exam));
        $this->post(route('exams.publish', $exam))->assertSessionHasNoErrors();
        app(AnswerSheetService::class)->layoutFor($exam->fresh()->currentVersion);

        $this->recognizer->queuePages([['qr' => $oldQr, 'answers' => $this->sheetAnswers(['B'])]]);
        $this->upload($exam);

        $this->assertSame('wrong_exam', ScanSheet::firstOrFail()->status);
        $this->assertSame(0, Submission::count());
    }

    public function test_upload_validation_and_duplicate_files(): void
    {
        [$exam] = $this->scanExam();
        $this->recognizer->queuePages([]);

        $this->post(route('exams.scans.store', $exam), ['scan' => UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')])
            ->assertSessionHasErrors('scan');
        $this->post(route('exams.scans.store', $exam), ['scan' => UploadedFile::fake()->create('huge.pdf', 30000, 'application/pdf')])
            ->assertSessionHasErrors('scan');

        $file = UploadedFile::fake()->createWithContent('same.pdf', "%PDF-1.4\nsame content");
        $this->post(route('exams.scans.store', $exam), ['scan' => $file])->assertSessionHasNoErrors();
        $this->post(route('exams.scans.store', $exam), ['scan' => UploadedFile::fake()->createWithContent('copy.pdf', "%PDF-1.4\nsame content")])
            ->assertSessionHasErrors('scan');
        $this->assertStringContainsString('already uploaded', session('errors')->first('scan'));
        $this->assertSame(1, ScanUpload::count());
    }

    public function test_recognition_failure_marks_upload_failed(): void
    {
        [$exam] = $this->scanExam();
        $this->recognizer->failWith('The PDF has 250 pages; the limit is 200.');
        $this->upload($exam);

        $upload = ScanUpload::firstOrFail();
        $this->assertSame('failed', $upload->status);
        $this->assertSame('The PDF has 250 pages; the limit is 200.', $upload->error);
        $this->get(route('exams.scans.index', $exam))->assertOk()->assertSee('the limit is 200');
    }

    public function test_scanner_not_installed_is_reported(): void
    {
        [$exam] = $this->scanExam();
        $this->recognizer->isAvailable = false;

        $this->get(route('exams.scans.index', $exam))->assertOk()->assertSee('scanner is not installed');
    }

    public function test_program_chair_cannot_upload_or_download_sheets(): void
    {
        [$exam] = $this->scanExam();
        $chair = $this->userWithRole(Role::PROGRAM_CHAIR, $exam->offering->course->program_id);
        $this->actingAs($chair);

        $this->get(route('exams.scans.index', $exam))->assertForbidden();
        $this->get(route('exams.answer-sheets', $exam))->assertForbidden();
        $this->upload($exam)->assertForbidden();
    }

    public function test_api_upload_status_and_assign(): void
    {
        [$exam, $teacher, $roster] = $this->scanExam();
        $this->recognizer->queuePages([['qr' => $this->qr($exam, null), 'answers' => $this->sheetAnswers(['B'], writtenBlank: true)]]);
        Sanctum::actingAs($teacher);

        $upload = $this->post("/api/v1/exams/{$exam->id}/scans", ['scan' => UploadedFile::fake()->createWithContent('photo.png', $this->png())],
            ['Accept' => 'application/json'])->assertStatus(202)->json('data');
        $status = $this->getJson("/api/v1/scan-uploads/{$upload['id']}")->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.sheets.0.status', 'needs_student')
            ->assertJsonPath('data.sheets.0.can_be_assigned', true)
            ->json('data');

        $this->postJson("/api/v1/scan-sheets/{$status['sheets'][0]['id']}/assign", ['student_id' => $roster[0]->id])
            ->assertOk()->assertJsonPath('data.status', 'assigned');
        $fileId = SubmissionFile::firstOrFail()->id;
        $this->get("/api/v1/submission-files/{$fileId}")->assertOk();
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }
}
