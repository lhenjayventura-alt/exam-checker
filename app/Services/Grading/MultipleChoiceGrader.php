<?php

namespace App\Services\Grading;

use App\Models\Question;

/**
 * Grades multiple-choice questions against option labels in the answer key.
 *
 * Single-answer questions are all-or-nothing. Multiple-select questions use the
 * question's "partial_credit" setting:
 *  - "none" (default): full points only for the exact set of correct options;
 *  - "proportional": (correct selected - incorrect selected) / number of correct options,
 *    floored at zero, times the question's points.
 */
class MultipleChoiceGrader implements QuestionGrader
{
    public function name(): string
    {
        return 'multiple_choice@1';
    }

    public function supports(Question $question): bool
    {
        return $question->type === 'multiple_choice';
    }

    /** Accepts "b", "A, C", "a c", "A;C" or "AC" and returns sorted labels joined by commas, e.g. "A,C". */
    public function normalize(Question $question, ?string $raw): ?string
    {
        $raw = strtoupper(trim((string) $raw));
        if ($raw === '') {
            return null;
        }

        $parts = preg_split('/[\s,;\/|]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) === 1 && preg_match('/^[A-Z]{2,}$/', $parts[0])) {
            $parts = str_split($parts[0]);
        }

        $labels = array_values(array_unique($parts));
        sort($labels);

        return implode(',', $labels);
    }

    public function grade(Question $question, ?string $answer): GradeOutcome
    {
        $key = $question->answerKey?->correct_values;
        if (empty($key)) {
            return GradeOutcome::review('No answer key is configured for this question.');
        }

        $points = (float) $question->points;
        if ($answer === null || $answer === '') {
            return new GradeOutcome(0.0, false, 'No answer given.');
        }

        $selected = explode(',', $answer);
        $valid = $question->options->pluck('label')->all();
        $unknown = array_diff($selected, $valid);
        if ($unknown !== []) {
            return GradeOutcome::review('Response contains labels that are not options: '.implode(', ', $unknown).'.');
        }

        $correct = array_values($key);
        sort($correct);

        if (! $question->isMultipleSelect()) {
            if (count($selected) > 1) {
                return GradeOutcome::review('More than one option was marked on a single-answer question.');
            }
            $isCorrect = $selected[0] === $correct[0];

            return new GradeOutcome(
                $isCorrect ? $points : 0.0,
                $isCorrect,
                $isCorrect ? "Answer {$selected[0]} matches the key." : "Answer {$selected[0]}; key is {$correct[0]}.",
            );
        }

        $hits = count(array_intersect($selected, $correct));
        $misses = count(array_diff($selected, $correct));
        $exact = $hits === count($correct) && $misses === 0;
        $keyText = implode(',', $correct);

        if ($question->setting('partial_credit', 'none') === 'proportional') {
            $fraction = max(0, $hits - $misses) / count($correct);
            $score = round($points * $fraction, 2);

            return new GradeOutcome(
                $score,
                $exact,
                "Selected {$answer}; key is {$keyText}. {$hits} correct and {$misses} incorrect selection(s); proportional credit {$score}/{$points}.",
            );
        }

        return new GradeOutcome(
            $exact ? $points : 0.0,
            $exact,
            $exact ? "Selected {$answer} matches the key." : "Selected {$answer}; key is {$keyText} (all-or-nothing).",
        );
    }
}
