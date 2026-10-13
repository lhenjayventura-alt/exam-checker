<?php

namespace Tests\Unit;

use App\Models\AnswerKey;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Services\Grading\MultipleChoiceGrader;
use App\Services\Grading\TrueFalseGrader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeterministicGradersTest extends TestCase
{
    private function mc(array $key, float $points = 1, array $config = []): Question
    {
        $q = new Question(['type' => 'multiple_choice', 'points' => $points, 'config' => $config ?: null]);
        $q->setRelation('options', collect(['A', 'B', 'C', 'D'])->map(fn ($l) => new QuestionOption(['label' => $l])));
        $q->setRelation('answerKey', new AnswerKey(['correct_values' => $key]));

        return $q;
    }

    private function tf(string $key): Question
    {
        $q = new Question(['type' => 'true_false', 'points' => 1]);
        $q->setRelation('answerKey', new AnswerKey(['correct_values' => [$key]]));

        return $q;
    }

    public static function mcNormalization(): array
    {
        return [
            'lowercase' => ['b', 'B'],
            'spaced' => ['  c ', 'C'],
            'comma list unsorted' => ['C, a', 'A,C'],
            'run of letters' => ['ca', 'A,C'],
            'semicolon and duplicates' => ['a;A;c', 'A,C'],
            'blank' => ['   ', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('mcNormalization')]
    public function test_multiple_choice_normalization(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, (new MultipleChoiceGrader)->normalize($this->mc(['A']), $raw));
    }

    public function test_single_answer_correct_and_incorrect(): void
    {
        $grader = new MultipleChoiceGrader;
        $q = $this->mc(['B'], 2);

        $right = $grader->grade($q, 'B');
        $this->assertSame(2.0, $right->score);
        $this->assertTrue($right->isCorrect);
        $this->assertFalse($right->needsReview);

        $wrong = $grader->grade($q, 'C');
        $this->assertSame(0.0, $wrong->score);
        $this->assertFalse($wrong->isCorrect);
        $this->assertFalse($wrong->needsReview);
    }

    public function test_blank_answer_scores_zero_without_review(): void
    {
        $outcome = (new MultipleChoiceGrader)->grade($this->mc(['B']), null);

        $this->assertSame(0.0, $outcome->score);
        $this->assertFalse($outcome->needsReview);
    }

    public function test_two_marks_on_single_answer_question_go_to_review(): void
    {
        $outcome = (new MultipleChoiceGrader)->grade($this->mc(['B']), 'A,B');

        $this->assertTrue($outcome->needsReview);
        $this->assertStringContainsString('More than one option', $outcome->reviewReason);
    }

    public function test_label_that_is_not_an_option_goes_to_review(): void
    {
        $outcome = (new MultipleChoiceGrader)->grade($this->mc(['B']), 'E');

        $this->assertTrue($outcome->needsReview);
        $this->assertStringContainsString('E', $outcome->reviewReason);
    }

    public function test_multiple_select_all_or_nothing(): void
    {
        $grader = new MultipleChoiceGrader;
        $q = $this->mc(['A', 'C'], 2, ['multiple_select' => true, 'partial_credit' => 'none']);

        $this->assertSame(2.0, $grader->grade($q, 'A,C')->score);
        $this->assertSame(0.0, $grader->grade($q, 'A')->score);
        $this->assertSame(0.0, $grader->grade($q, 'A,B,C')->score);
    }

    public function test_multiple_select_proportional_credit_deducts_wrong_picks(): void
    {
        $grader = new MultipleChoiceGrader;
        $q = $this->mc(['A', 'C'], 2, ['multiple_select' => true, 'partial_credit' => 'proportional']);

        $this->assertSame(2.0, $grader->grade($q, 'A,C')->score);   // 2 hits, 0 misses
        $this->assertSame(1.0, $grader->grade($q, 'A')->score);     // 1 hit
        $this->assertSame(1.0, $grader->grade($q, 'A,B,C')->score); // 2 hits − 1 miss
        $this->assertSame(0.0, $grader->grade($q, 'A,B')->score);   // 1 hit − 1 miss
        $this->assertSame(0.0, $grader->grade($q, 'B,D')->score);   // floored at zero
    }

    public function test_missing_answer_key_goes_to_review(): void
    {
        $q = $this->mc([]);

        $this->assertTrue((new MultipleChoiceGrader)->grade($q, 'A')->needsReview);
    }

    public static function tfNormalization(): array
    {
        return [
            ['T', 'true'], ['true', 'true'], ['True.', 'true'], ['tama', 'true'],
            ['f', 'false'], ['FALSE', 'false'], ['Mali', 'false'],
            ['', null], ['maybe', 'MAYBE'],
        ];
    }

    #[DataProvider('tfNormalization')]
    public function test_true_false_normalization(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, (new TrueFalseGrader)->normalize($this->tf('true'), $raw));
    }

    public function test_true_false_grading(): void
    {
        $grader = new TrueFalseGrader;
        $q = $this->tf('false');

        $this->assertSame(1.0, $grader->grade($q, 'false')->score);
        $this->assertSame(0.0, $grader->grade($q, 'true')->score);
        $this->assertSame(0.0, $grader->grade($q, null)->score);
        $this->assertTrue($grader->grade($q, 'MAYBE')->needsReview);
    }
}
