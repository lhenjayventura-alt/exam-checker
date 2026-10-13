<?php

namespace App\Services\Grading;

use App\Models\Question;

class TrueFalseGrader implements QuestionGrader
{
    private const TRUE_WORDS = ['T', 'TRUE', 'TAMA'];

    private const FALSE_WORDS = ['F', 'FALSE', 'MALI'];

    public function name(): string
    {
        return 'true_false@1';
    }

    public function supports(Question $question): bool
    {
        return $question->type === 'true_false';
    }

    /** Maps T/True/F/False (any case) to "true"/"false"; other text is kept uppercased so it is flagged for review. */
    public function normalize(Question $question, ?string $raw): ?string
    {
        $raw = strtoupper(trim((string) $raw, " \t\n\r\0\x0B."));
        if ($raw === '') {
            return null;
        }

        return match (true) {
            in_array($raw, self::TRUE_WORDS, true) => 'true',
            in_array($raw, self::FALSE_WORDS, true) => 'false',
            default => $raw,
        };
    }

    public function grade(Question $question, ?string $answer): GradeOutcome
    {
        $key = $question->answerKey?->correct_values[0] ?? null;
        if (! in_array($key, ['true', 'false'], true)) {
            return GradeOutcome::review('No valid answer key is configured for this question.');
        }

        if ($answer === null || $answer === '') {
            return new GradeOutcome(0.0, false, 'No answer given.');
        }

        if (! in_array($answer, ['true', 'false'], true)) {
            return GradeOutcome::review("Unrecognized true/false response \"{$answer}\".");
        }

        $isCorrect = $answer === $key;

        return new GradeOutcome(
            $isCorrect ? (float) $question->points : 0.0,
            $isCorrect,
            $isCorrect ? "Answer {$answer} matches the key." : "Answer {$answer}; key is {$key}.",
        );
    }
}
