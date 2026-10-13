<?php

namespace App\Services\Grading;

use App\Models\Question;
use App\Models\RecognizedAnswer;
use App\Services\CodeExecution\CodeRunner;
use App\Services\CodeExecution\OutputComparator;

/**
 * Coding questions: the program is compiled and run against each test case in the sandbox;
 * each passing test earns its points. A compile error scores 0 on the tests (an academic
 * result). Sandbox failures throw CodeRunnerUnavailable so the job retries instead of
 * scoring. Code-quality rubric criteria, when present, are scored by the teacher.
 *
 * Settings: language (c|python), time_limit_ms, memory_mb, comparison (trim|tokens|exact).
 */
class CodingGrader implements AnswerAwareGrader
{
    public const OUTPUT_KB = 64;

    public function __construct(private readonly CodeRunner $runner) {}

    public function name(): string
    {
        return 'coding@1';
    }

    public function supports(Question $question): bool
    {
        return $question->type === 'coding';
    }

    /** Code is stored in raw_value; the normalized value is only a short fingerprint. */
    public function normalize(Question $question, ?string $raw): ?string
    {
        return $raw === null || trim($raw) === '' ? null : 'sha256:'.hash('sha256', $raw);
    }

    public function grade(Question $question, ?string $answer): GradeOutcome
    {
        return GradeOutcome::review('Coding answers are graded from the submitted source code.', null);
    }

    public function gradeAnswer(Question $question, ?RecognizedAnswer $answer): GradeOutcome
    {
        $tests = $question->relationLoaded('testCases') ? $question->testCases : $question->testCases()->get();
        $criteria = $question->relationLoaded('rubricCriteria') ? $question->rubricCriteria : $question->rubricCriteria()->get();
        $testPoints = (float) $tests->sum('points');
        $rubricNote = $criteria->isNotEmpty()
            ? sprintf(' Code quality (%s pt) requires rubric scoring.', rtrim(rtrim(number_format($criteria->sum('max_points'), 2), '0'), '.'))
            : '';

        $code = $answer?->code();
        if ($code === null) {
            if ($answer?->recognition_meta['not_on_sheet'] ?? false) {
                return new GradeOutcome(0.0, null, 'No source code was provided.', true,
                    'Coding answer is not on the paper sheet: provide the student\'s code.', [], false);
            }

            return $this->finish(0.0, $testPoints, false, 'No code submitted.'.$rubricNote, $criteria->isNotEmpty(), []);
        }

        $report = $this->runner->run(
            (string) $question->setting('language', 'c'),
            $code,
            $tests->mapWithKeys(fn ($t) => [$t->id => (string) $t->input])->all(),
            [
                'time_ms' => (int) $question->setting('time_limit_ms', 2000),
                'memory_mb' => (int) $question->setting('memory_mb', 128),
                'output_kb' => self::OUTPUT_KB,
            ],
        );

        $executions = [[
            'test_case_id' => null, 'step' => 'compile',
            'status' => $report['compile']['status'] === 'ok' ? 'passed' : 'compile_error',
            'points' => null, 'exit_code' => null, 'duration_ms' => $report['compile']['duration_ms'],
            'stdout' => null, 'stderr' => mb_substr($report['compile']['output'], 0, 8000),
        ]];

        if ($report['compile']['status'] !== 'ok') {
            foreach ($tests as $t) {
                $executions[] = ['test_case_id' => $t->id, 'step' => 'test', 'status' => 'skipped', 'points' => 0.0,
                    'exit_code' => null, 'duration_ms' => null, 'stdout' => null, 'stderr' => null];
            }

            return $this->finish(0.0, $testPoints, false, 'Compilation failed; no tests were run.'.$rubricNote, $criteria->isNotEmpty(), $executions);
        }

        $mode = (string) $question->setting('comparison', 'trim');
        $earned = 0.0;
        $passed = 0;
        foreach ($tests as $t) {
            $run = $report['tests'][$t->id] ?? ['status' => 'skipped', 'stdout' => '', 'stderr' => '', 'exit_code' => null, 'duration_ms' => 0];
            $status = match ($run['status']) {
                'ok' => OutputComparator::matches($run['stdout'], $t->expected_output, $mode) ? 'passed' : 'failed',
                default => $run['status'],
            };
            $points = $status === 'passed' ? (float) $t->points : 0.0;
            $earned += $points;
            $passed += $status === 'passed' ? 1 : 0;
            $executions[] = [
                'test_case_id' => $t->id, 'step' => 'test', 'status' => $status, 'points' => $points,
                'exit_code' => $run['exit_code'], 'duration_ms' => $run['duration_ms'],
                'stdout' => mb_substr($run['stdout'], 0, 4000), 'stderr' => mb_substr($run['stderr'], 0, 4000),
            ];
        }

        $explanation = sprintf('%d of %d test(s) passed (%s of %s pt).', $passed, $tests->count(), $earned + 0, $testPoints + 0).$rubricNote;

        return $this->finish(round($earned, 2), $testPoints, $passed === $tests->count(), $explanation, $criteria->isNotEmpty(), $executions);
    }

    private function finish(float $score, float $testPoints, bool $allPassed, string $explanation, bool $hasRubric, array $executions): GradeOutcome
    {
        $details = $testPoints > 0 ? [['label' => 'Automated tests', 'max_points' => $testPoints, 'score' => $score]] : [];

        return new GradeOutcome(
            $score,
            $hasRubric ? null : $allPassed,
            $explanation,
            $hasRubric,
            $hasRubric ? 'Code quality must be scored with the rubric.' : null,
            $details,
            $hasRubric,
            $executions,
        );
    }
}
