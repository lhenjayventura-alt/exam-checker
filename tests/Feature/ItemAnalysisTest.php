<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Services\ExamAnalyticsService;
use App\Services\ExamResultService;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExamFixtures;
use Tests\TestCase;

/**
 * Verifies the documented statistics against hand-computed values.
 */
class ItemAnalysisTest extends TestCase
{
    use BuildsExamFixtures, RefreshDatabase;

    public function test_summary_difficulty_discrimination_and_distractors(): void
    {
        $this->seedRoles();
        [$offering, $teacher] = $this->offeringWithTeacher(0);
        $exam = $this->publishedExam($offering, $teacher);
        $students = Student::factory()->count(10)->create();
        $offering->students()->attach($students->pluck('id'));

        // Q1 key B (1 pt), Q2 key A,C proportional (2 pts), Q3 key true (1 pt).
        $sheets = [
            [1 => 'B', 2 => 'A,C', 3 => 'T'], // 4
            [1 => 'B', 2 => 'A,C', 3 => 'T'], // 4
            [1 => 'B', 2 => 'A,C', 3 => 'F'], // 3
            [1 => 'B', 2 => 'A',   3 => 'T'], // 3
            [1 => 'B', 2 => 'A',   3 => 'F'], // 2
            [1 => 'C', 2 => 'A',   3 => 'T'], // 2
            [1 => 'C', 2 => 'A,B', 3 => 'T'], // 1
            [1 => 'D', 2 => 'B',   3 => 'T'], // 1
            [1 => 'A', 2 => 'B',   3 => 'F'], // 0
            [1 => '',  2 => '',    3 => 'F'], // 0
        ];
        foreach ($students as $i => $student) {
            app(SubmissionService::class)->createDigital($exam, $student, $sheets[$i], $teacher);
        }
        $this->assertSame(10, app(ExamResultService::class)->finalizeAllGraded($exam, $teacher));

        $analytics = app(ExamAnalyticsService::class);
        $summary = $analytics->summary($exam);
        $this->assertSame(10, $summary['n']);
        $this->assertSame(2.0, $summary['mean']);
        $this->assertSame(2.0, $summary['median']);
        $this->assertSame(4.0, $summary['highest']);
        $this->assertSame(0.0, $summary['lowest']);
        $this->assertSame(1.49, $summary['std_dev']); // sqrt(20/9)
        $this->assertSame(4, $summary['passed']);    // ≥ 60%: scores 4,4,3,3
        $this->assertTrue($summary['small_sample']);
        $this->assertSame(2, $summary['distribution'][0]['count']); // two zeros
        $this->assertSame(2, $summary['distribution'][9]['count']); // two 100%

        $items = collect($analytics->itemAnalysis($exam)['items'])->keyBy('number');

        // Q1: 5 of 10 correct.
        $this->assertSame(0.5, $items[1]['difficulty']);
        $this->assertSame('proportion correct', $items[1]['difficulty_method']);
        // Groups of round(0.27 × 10) = 3. Top three (4,4,3) all correct; bottom three (1,0,0) none → D = 1.
        $this->assertSame(1.0, $items[1]['discrimination']);
        $q1 = collect($items[1]['distractors'])->keyBy('label');
        $this->assertSame([1, 5, 2, 1, 1], [$q1['A']['count'], $q1['B']['count'], $q1['C']['count'], $q1['D']['count'], $q1['Blank']['count']]);
        $this->assertTrue($q1['B']['is_key']);
        $this->assertSame(20.0, $q1['C']['percent']);

        // Q2 proportional: normalized scores 1,1,1,.5,.5,.5,0,0,0,0 → mean 0.45.
        $this->assertSame(0.45, $items[2]['difficulty']);
        $this->assertSame('normalized mean score', $items[2]['difficulty_method']);

        // Q3: 6 of 10 true.
        $this->assertSame(0.6, $items[3]['difficulty']);
        $this->assertNull($items[3]['distractors']);
    }

    public function test_discrimination_is_not_computed_for_small_groups(): void
    {
        $this->seedRoles();
        [$offering, $teacher, $students] = $this->offeringWithTeacher(3);
        $exam = $this->publishedExam($offering, $teacher);
        foreach ($students as $student) {
            app(SubmissionService::class)->createDigital($exam, $student, [1 => 'B'], $teacher);
        }
        app(ExamResultService::class)->finalizeAllGraded($exam, $teacher);

        $analysis = app(ExamAnalyticsService::class)->itemAnalysis($exam);
        $this->assertFalse($analysis['discrimination_computed']);
        $this->assertNull($analysis['items'][0]['discrimination']);
        $this->assertSame(1.0, $analysis['items'][0]['difficulty']);
    }

    public function test_unfinalized_submissions_are_excluded(): void
    {
        $this->seedRoles();
        [$offering, $teacher, $students] = $this->offeringWithTeacher(2);
        $exam = $this->publishedExam($offering, $teacher);
        app(SubmissionService::class)->createDigital($exam, $students[0], [1 => 'B'], $teacher);

        $this->assertSame(0, app(ExamAnalyticsService::class)->summary($exam)['n']);
        $this->assertSame([], app(ExamAnalyticsService::class)->itemAnalysis($exam)['items']);
    }
}
