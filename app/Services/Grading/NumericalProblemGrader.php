<?php

namespace App\Services\Grading;

use App\Models\Question;

/**
 * Problem-solving questions: the final numeric answer is checked automatically against the
 * key within a tolerance; solution-method points come from rubric criteria a teacher scores.
 *
 * Settings: tolerance (number), tolerance_type ("absolute" | "percent"), units (accepted unit
 * spellings, e.g. ["V", "volts"]), unit_required (bool), answer_points (points for the final
 * answer; the remainder equals the sum of rubric criteria).
 *
 * The final answer earns all of answer_points or none; partial credit belongs in the rubric.
 * Unit conversion (e.g. mA to A) is not performed: a different unit is sent to review.
 */
class NumericalProblemGrader implements QuestionGrader
{
    public function name(): string
    {
        return 'problem_solving@1';
    }

    public function supports(Question $question): bool
    {
        return $question->type === 'problem_solving';
    }

    public function normalize(Question $question, ?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $parsed = NumericAnswer::parse($raw);

        // Unparseable text is kept as-is so the teacher sees exactly what was written.
        return mb_substr($parsed?->canonical() ?? $raw, 0, 255);
    }

    public function grade(Question $question, ?string $answer): GradeOutcome
    {
        $expected = $question->answerKey?->correct_values[0] ?? null;
        if (! is_numeric($expected)) {
            return GradeOutcome::review('No expected numeric answer is configured for this question.');
        }
        $expected = (float) $expected;

        $answerPoints = $question->answerPoints();
        $criteria = $question->relationLoaded('rubricCriteria') ? $question->rubricCriteria : $question->rubricCriteria()->get();
        $methodPoints = (float) $criteria->sum('max_points');
        $rubricNote = $criteria->isNotEmpty()
            ? sprintf(' Solution method (%s pt) requires rubric scoring.', $this->fmt($methodPoints))
            : '';

        [$answerScore, $isCorrect, $explanation, $answerProblem] = $this->checkFinalAnswer($question, $expected, $answer, $answerPoints);

        $details = $answerPoints > 0
            ? [['label' => 'Final answer', 'max_points' => $answerPoints, 'score' => $answerProblem ? null : $answerScore]]
            : [];

        if ($answerProblem !== null) {
            // The answer itself needs a decision first, so this is not only a pending rubric.
            return new GradeOutcome($answerScore, null, $explanation.$rubricNote, true, $answerProblem, $details, false);
        }
        if ($criteria->isNotEmpty()) {
            return new GradeOutcome(
                $answerScore, null, $explanation.$rubricNote, true,
                'Solution method must be scored with the rubric.', $details, true,
            );
        }

        return new GradeOutcome($answerScore, $isCorrect, $explanation, false, null, $details);
    }

    /** @return array{0: float, 1: ?bool, 2: string, 3: ?string} score, correct, explanation, review reason */
    private function checkFinalAnswer(Question $question, float $expected, ?string $answer, float $answerPoints): array
    {
        $units = array_values(array_filter((array) $question->setting('units', [])));
        $keyText = $this->fmt($expected).($units ? ' '.$units[0] : '');
        $toleranceText = $this->toleranceText($question);

        if ($answer === null || $answer === '') {
            return [0.0, false, 'No final answer given.', null];
        }

        $parsed = NumericAnswer::parse($answer);
        if ($parsed === null) {
            return [0.0, null, "Final answer \"{$answer}\" is not a readable number.", "Final answer \"{$answer}\" could not be read as a number."];
        }

        $within = $this->withinTolerance($question, $parsed->value, $expected);
        $base = sprintf('Final answer %s; key %s%s.', $parsed->canonical(), $keyText, $toleranceText);

        if ($units !== []) {
            $unitOk = $parsed->unit !== null && collect($units)->contains(fn ($u) => NumericAnswer::sameUnit($u, $parsed->unit));
            if ($parsed->unit === null && $question->setting('unit_required', false)) {
                return [$within ? $answerPoints : 0.0, null, $base, 'The required unit is missing from the final answer.'];
            }
            if ($parsed->unit !== null && ! $unitOk) {
                return [$within ? $answerPoints : 0.0, null, $base, "Unit \"{$parsed->unit}\" is not an accepted unit (".implode(', ', $units).').'];
            }
        }

        return $within
            ? [$answerPoints, true, $base.' Within tolerance.', null]
            : [0.0, false, $base.' Outside tolerance.', null];
    }

    private function withinTolerance(Question $question, float $value, float $expected): bool
    {
        $tolerance = (float) $question->setting('tolerance', 0);
        $allowed = $question->setting('tolerance_type', 'absolute') === 'percent'
            ? abs($expected) * $tolerance / 100
            : $tolerance;

        // A tiny epsilon absorbs floating-point representation error at the boundary.
        return abs($value - $expected) <= $allowed + 1e-9 * max(1.0, abs($expected));
    }

    private function toleranceText(Question $question): string
    {
        $tolerance = (float) $question->setting('tolerance', 0);
        if ($tolerance <= 0) {
            return ' (exact)';
        }

        return $question->setting('tolerance_type', 'absolute') === 'percent'
            ? ' ± '.$this->fmt($tolerance).'%'
            : ' ± '.$this->fmt($tolerance);
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');
    }
}
