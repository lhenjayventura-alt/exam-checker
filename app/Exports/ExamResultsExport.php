<?php

namespace App\Exports;

use App\Models\Exam;
use App\Services\ExamAnalyticsService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Workbook: class score sheet (all versions) plus one item-analysis sheet per version with results.
 */
class ExamResultsExport implements WithMultipleSheets
{
    public function __construct(private readonly Exam $exam) {}

    public function sheets(): array
    {
        $sheets = [new ScoreSheet($this->exam)];
        $analytics = app(ExamAnalyticsService::class);

        foreach ($this->exam->versions()->orderBy('version_number')->get() as $version) {
            $analysis = $analytics->itemAnalysis($this->exam, $version);
            if ($analysis['n'] > 0) {
                $sheets[] = new ItemAnalysisSheet($version->version_number, $analysis);
            }
        }

        return $sheets;
    }
}
