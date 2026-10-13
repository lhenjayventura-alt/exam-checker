<?php

namespace App\Exports;

use App\Models\Exam;
use App\Support\SpreadsheetCell;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ScoreSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public const HEADINGS = ['Student Number', 'Last Name', 'First Name', 'Version', 'Score', 'Max', 'Percentage', 'Passed', 'Finalized At', 'Released At'];

    public function __construct(private readonly Exam $exam) {}

    public function title(): string
    {
        return 'Score sheet';
    }

    public function headings(): array
    {
        return self::HEADINGS;
    }

    public function array(): array
    {
        return self::rows($this->exam);
    }

    /** Shared by the CSV and Excel exports. */
    public static function rows(Exam $exam): array
    {
        return $exam->results()->with(['student', 'submission.version'])->get()
            ->sortBy(fn ($r) => $r->student->last_name.' '.$r->student->first_name)
            ->map(fn ($r) => SpreadsheetCell::row([
                $r->student->student_number, $r->student->last_name, $r->student->first_name,
                $r->submission->version->version_number,
                $r->total_score, $r->max_score, $r->percentage, $r->passed ? 'Yes' : 'No',
                $r->finalized_at?->toDateTimeString(), $r->released_at?->toDateTimeString(),
            ]))
            ->values()->all();
    }
}
