<?php

namespace App\Services\Grading;

use App\Models\Question;

/**
 * Grades short text answers against a list of accepted answers.
 *
 * Comparison ignores case (unless "case_sensitive"), punctuation and repeated spaces;
 * "+" and "#" are kept so answers like C++ and C# stay distinct. With "fuzzy" enabled,
 * a near miss (edit distance within 20% of the accepted answer, minimum 1) is never
 * awarded automatically: it is proposed at full credit and sent to teacher review.
 */
class IdentificationGrader implements QuestionGrader
{
    public function name(): string
    {
        return 'identification@1';
    }

    public function supports(Question $question): bool
    {
        return $question->type === 'identification';
    }

    public function normalize(Question $question, ?string $raw): ?string
    {
        return self::canonical((string) $raw, (bool) $question->setting('case_sensitive', false));
    }

    public static function canonical(string $text, bool $caseSensitive = false): ?string
    {
        $text = $caseSensitive ? $text : mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}+#]+/u', ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    public function grade(Question $question, ?string $answer): GradeOutcome
    {
        $caseSensitive = (bool) $question->setting('case_sensitive', false);
        $accepted = collect($question->answerKey?->correct_values ?? [])
            ->mapWithKeys(fn ($a) => [self::canonical((string) $a, $caseSensitive) ?? '' => $a])
            ->forget('');

        if ($accepted->isEmpty()) {
            return GradeOutcome::review('No accepted answers are configured for this question.');
        }
        if ($answer === null || $answer === '') {
            return new GradeOutcome(0.0, false, 'No answer given.');
        }

        $points = (float) $question->points;
        if ($accepted->has($answer)) {
            return new GradeOutcome($points, true, "Matches accepted answer \"{$accepted[$answer]}\".");
        }

        if ($question->setting('fuzzy', false)) {
            $closest = null;
            $closestDistance = PHP_INT_MAX;
            foreach ($accepted as $canonical => $display) {
                $distance = levenshtein($answer, (string) $canonical);
                if ($distance < $closestDistance) {
                    [$closest, $closestDistance] = [$display, $distance];
                }
            }
            $allowed = max(1, (int) floor(mb_strlen((string) self::canonical((string) $closest, $caseSensitive)) * 0.2));
            if ($closestDistance <= $allowed) {
                return new GradeOutcome(
                    $points, null,
                    "Close to accepted answer \"{$closest}\" ({$closestDistance} character(s) different).",
                    true,
                    "Possible misspelling of \"{$closest}\"; confirm to award credit or override.",
                );
            }
        }

        $list = $accepted->values()->map(fn ($a) => "\"{$a}\"")->join(', ');

        return new GradeOutcome(0.0, false, "Does not match the accepted answer(s): {$list}.");
    }
}
