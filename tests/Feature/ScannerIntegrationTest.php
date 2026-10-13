<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ScanSheet;
use App\Models\ScanUpload;
use App\Models\Submission;
use App\Services\Scanning\AnswerSheetService;
use App\Services\Scanning\SheetRecognizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsExamFixtures;
use Tests\TestCase;

/**
 * End to end with the real Python/OpenCV worker: print named sheets, fill them in, upload,
 * recognize, grade. Skipped when the scanner environment is not installed.
 */
#[Group('scanner')]
class ScannerIntegrationTest extends TestCase
{
    use BuildsExamFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app(SheetRecognizer::class)->available()) {
            $this->markTestSkipped('Scanner environment not installed (scanner/.venv).');
        }
        $this->seedRoles();
        Storage::fake('local');
    }

    public function test_printed_sheets_are_read_and_graded(): void
    {
        [$offering, $teacher, $roster] = $this->offeringWithTeacher(3);
        $this->actingAs($teacher);
        $this->post(route('exams.store', $offering), ['title' => 'Integration Quiz', 'exam_type' => 'quiz', 'passing_percentage' => 50]);
        $exam = Exam::firstOrFail();
        $options = [['label' => 'A'], ['label' => 'B'], ['label' => 'C'], ['label' => 'D']];
        $this->post(route('exams.questions.store', $exam), ['type' => 'multiple_choice', 'points' => 1, 'options' => $options, 'correct' => ['B']]);
        $this->post(route('exams.questions.store', $exam), ['type' => 'true_false', 'points' => 1, 'correct' => ['false']]);
        $this->post(route('exams.questions.store', $exam), ['type' => 'identification', 'points' => 2, 'accepted_answers' => "Ohm's law"]);
        $this->post(route('exams.publish', $exam))->assertSessionHasNoErrors();
        $exam->refresh();

        // 1. Print named sheets through the app (real PDF rendering).
        $response = $this->get(route('exams.answer-sheets', [$exam, 'for' => 'roster']))->assertOk();
        $rendered = tempnam(sys_get_temp_dir(), 'sheets').'.pdf';
        copy($response->baseResponse->getFile()->getPathname(), $rendered);
        $students = $exam->offering->students()->orderBy('last_name')->orderBy('first_name')->get();

        // 2. "Students" fill them in; the third sheet is a skewed, noisy phone photo.
        $layout = app(AnswerSheetService::class)->layoutFor($exam->currentVersion)->layout;
        $scanned = tempnam(sys_get_temp_dir(), 'scan').'.pdf';
        $fill = Process::input(json_encode(['layout' => $layout, 'sheets' => [
            ['marks' => ['1' => ['B'], '2' => ['F']], 'write' => ['3' => 'Ohms law']],
            ['marks' => ['1' => ['A', 'C'], '2' => ['T']]],
            ['marks' => ['1' => ['B'], '2' => ['T']], 'photo' => true],
        ]]))->run([config('exam_checker.scanner.python'), base_path('scanner/tests/fill_sheets.py'), $rendered, $scanned]);
        $this->assertTrue($fill->successful(), $fill->errorOutput());

        // 3. Upload and let the queue (synchronous in tests) run the real recognizer.
        $this->post(route('exams.scans.store', $exam), [
            'scan' => new UploadedFile($scanned, 'scanner-output.pdf', 'application/pdf', null, true),
        ])->assertSessionHasNoErrors();

        $upload = ScanUpload::firstOrFail();
        $this->assertSame('completed', $upload->status, (string) $upload->error);
        $this->assertSame(3, $upload->page_count);
        $this->assertSame(['matched'], ScanSheet::pluck('status')->unique()->values()->all());

        $byStudent = Submission::with(['answers.question', 'gradingResults.question'])->get()->keyBy('student_id');
        $answers = fn ($student) => $byStudent[$student->id]->answers->mapWithKeys(fn ($a) => [$a->question->number => $a->normalized_value])->all();

        // Sheet 1: all correct bubbles, written answer awaits transcription.
        $this->assertSame([1 => 'B', 2 => 'false', 3 => null], $answers($students[0]));
        $this->assertTrue($byStudent[$students[0]->id]->answers->firstWhere('question.number', 3)->needsTranscription());
        // Sheet 2: double mark on Q1 goes to review; blank written box is not.
        $this->assertSame('A,C', $answers($students[1])[1]);
        $q1 = $byStudent[$students[1]->id]->gradingResults->firstWhere('question.number', 1);
        $this->assertSame('needs_review', $q1->status);
        $this->assertFalse($byStudent[$students[1]->id]->answers->firstWhere('question.number', 3)->needsTranscription());
        // Sheet 3: phone photo still read correctly.
        $this->assertSame([1 => 'B', 2 => 'true'], array_slice($answers($students[2]), 0, 2, true));

        @unlink($rendered);
        @unlink($scanned);
    }
}
