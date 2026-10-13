<?php

namespace App\Services\Scanning;

use App\Models\AnswerSheetLayout;
use App\Models\Exam;
use App\Models\ExamVersion;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Printable answer sheets: layout per locked version and signed QR payloads.
 *
 * QR payload: EC1|<layout id>|<student id or 0>|<page>|<signature>. The signature (HMAC with
 * the app key) stops a sheet from another exam, a tampered code or a misread code from being
 * matched to a student.
 */
class AnswerSheetService
{
    public function __construct(private readonly SheetRecognizer $recognizer) {}

    public function layoutFor(ExamVersion $version): AnswerSheetLayout
    {
        if (! $version->isLocked()) {
            throw ValidationException::withMessages(['exam' => 'Publish the exam before printing answer sheets; the questions must be final.']);
        }
        if ($existing = $version->sheetLayout) {
            return $existing;
        }

        $exam = $version->exam->loadMissing('offering.course');
        $questions = $version->questions()->with('options')->get()->map(fn ($q) => array_filter([
            'number' => $q->number,
            'type' => $q->type,
            'labels' => $q->type === 'multiple_choice' ? $q->options->pluck('label')->all() : null,
        ], fn ($v) => $v !== null))->values()->all();

        $layout = $this->recognizer->layout([
            'title' => $exam->title,
            'subtitle' => $exam->offering->course->code.' · Section '.$exam->offering->section.' · Version '.$version->version_number,
            'questions' => $questions,
        ]);

        return AnswerSheetLayout::firstOrCreate(
            ['exam_version_id' => $version->id],
            ['layout' => $layout, 'page_count' => $layout['pages']],
        );
    }

    public function qrPayload(AnswerSheetLayout $layout, ?int $studentId, int $page): string
    {
        $base = 'EC1|'.$layout->id.'|'.($studentId ?? 0).'|'.$page;

        return $base.'|'.$this->signature($base);
    }

    /** @return array{layout_id: int, student_id: ?int, page: int}|null */
    public function verify(?string $payload): ?array
    {
        $parts = explode('|', (string) $payload);
        if (count($parts) !== 5 || $parts[0] !== 'EC1' || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2]) || ! ctype_digit($parts[3])) {
            return null;
        }
        $base = implode('|', array_slice($parts, 0, 4));
        if (! hash_equals($this->signature($base), $parts[4])) {
            return null;
        }

        return ['layout_id' => (int) $parts[1], 'student_id' => (int) $parts[2] ?: null, 'page' => (int) $parts[3]];
    }

    /**
     * Generate a PDF of sheets: one generic sheet, or one personalised sheet per student.
     *
     * @param  Collection<int, Student>|null  $students
     */
    public function renderPdf(Exam $exam, ?Collection $students, string $outputPath): void
    {
        $layout = $this->layoutFor($exam->currentVersion);
        $pages = range(1, $layout->page_count);

        $sheets = $students === null
            ? [['qr' => array_map(fn ($p) => $this->qrPayload($layout, null, $p), $pages), 'name' => null, 'student_number' => null]]
            : $students->map(fn (Student $s) => [
                'qr' => array_map(fn ($p) => $this->qrPayload($layout, $s->id, $p), $pages),
                'name' => $s->fullName(),
                'student_number' => $s->student_number,
            ])->values()->all();

        $this->recognizer->render($layout->layout, $sheets, $outputPath);
    }

    private function signature(string $base): string
    {
        return substr(hash_hmac('sha256', $base, (string) config('app.key')), 0, 12);
    }
}
