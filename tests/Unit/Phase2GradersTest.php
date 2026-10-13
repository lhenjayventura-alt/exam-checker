<?php

namespace Tests\Unit;

use App\Models\AnswerKey;
use App\Models\Question;
use App\Models\RubricCriterion;
use App\Services\Grading\IdentificationGrader;
use App\Services\Grading\NumericalProblemGrader;
use App\Services\Grading\NumericAnswer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Phase2GradersTest extends TestCase
{
    private function identification(array $accepted, array $config = []): Question
    {
        $q = new Question(['type' => 'identification', 'points' => 2, 'config' => $config ?: null]);
        $q->setRelation('answerKey', new AnswerKey(['correct_values' => $accepted]));

        return $q;
    }

    private function problem(float $expected, array $config = [], array $criteria = [], float $points = 5): Question
    {
        $q = new Question(['type' => 'problem_solving', 'points' => $points, 'config' => $config ?: null]);
        $q->setRelation('answerKey', new AnswerKey(['correct_values' => [(string) $expected]]));
        $q->setRelation('rubricCriteria', collect($criteria)->map(fn ($pts, $label) => new RubricCriterion(['label' => $label, 'max_points' => $pts])));

        return $q;
    }

    // Identification

    public static function identificationNormalization(): array
    {
        return [
            'case and spaces' => ["  Ohm's   LAW ", 'ohm s law'],
            'punctuation' => ['flip-flop.', 'flip flop'],
            'keeps + and #' => ['C++', 'c++'],
            'blank' => ['  ', null],
        ];
    }

    #[DataProvider('identificationNormalization')]
    public function test_identification_normalization(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, (new IdentificationGrader)->normalize($this->identification(['x']), $raw));
    }

    public function test_identification_matches_any_accepted_answer(): void
    {
        $grader = new IdentificationGrader;
        $q = $this->identification(["Ohm's law", 'Ohms law']);

        $this->assertSame(2.0, $grader->grade($q, $grader->normalize($q, 'OHMS LAW'))->score);
        $this->assertSame(2.0, $grader->grade($q, $grader->normalize($q, "ohm's law"))->score);
        $wrong = $grader->grade($q, $grader->normalize($q, 'Kirchhoff'));
        $this->assertSame(0.0, $wrong->score);
        $this->assertFalse($wrong->needsReview);
    }

    public function test_c_plus_plus_is_not_confused_with_c(): void
    {
        $grader = new IdentificationGrader;
        $q = $this->identification(['C++']);

        $this->assertSame(0.0, $grader->grade($q, $grader->normalize($q, 'C'))->score);
        $this->assertSame(2.0, $grader->grade($q, $grader->normalize($q, 'c++'))->score);
    }

    public function test_case_sensitive_identification(): void
    {
        $grader = new IdentificationGrader;
        $q = $this->identification(['Co'], ['case_sensitive' => true]);

        $this->assertSame(2.0, $grader->grade($q, $grader->normalize($q, 'Co'))->score);
        $this->assertSame(0.0, $grader->grade($q, $grader->normalize($q, 'CO'))->score);
    }

    public function test_fuzzy_near_miss_goes_to_review_and_is_never_auto_awarded(): void
    {
        $grader = new IdentificationGrader;
        $fuzzy = $this->identification(['Transistor'], ['fuzzy' => true]);
        $strict = $this->identification(['Transistor']);

        $near = $grader->grade($fuzzy, $grader->normalize($fuzzy, 'transistr'));
        $this->assertTrue($near->needsReview);
        $this->assertNull($near->isCorrect);
        $this->assertStringContainsString('Transistor', $near->reviewReason);

        // Too far from the accepted answer: plain wrong, no review.
        $far = $grader->grade($fuzzy, $grader->normalize($fuzzy, 'resistor'));
        $this->assertFalse($far->needsReview);
        $this->assertSame(0.0, $far->score);

        // Fuzzy disabled: near miss is simply wrong.
        $this->assertFalse($grader->grade($strict, $grader->normalize($strict, 'transistr'))->needsReview);
    }

    // Numeric parsing

    public static function numericForms(): array
    {
        return [
            ['12.5', 12.5, null],
            ['-0.25 A', -0.25, 'A'],
            ['1,250 ohm', 1250.0, 'ohm'],
            ['.5', 0.5, null],
            ['1.2e-3 F', 0.0012, 'F'],
            ['4.7 x 10^3 Hz', 4700.0, 'Hz'],
            ['4.7×10^-3', 0.0047, null],
            ['3/4', 0.75, null],
            ['−2 V', -2.0, 'V'],
            ['4.7 kΩ', 4.7, 'kΩ'],
        ];
    }

    #[DataProvider('numericForms')]
    public function test_numeric_parsing(string $raw, float $value, ?string $unit): void
    {
        $parsed = NumericAnswer::parse($raw);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta($value, $parsed->value, 1e-12);
        $this->assertSame($unit, $parsed->unit);
    }

    public function test_unreadable_numbers_are_rejected(): void
    {
        $this->assertNull(NumericAnswer::parse('about twelve'));
        $this->assertNull(NumericAnswer::parse('V'));
        $this->assertNull(NumericAnswer::parse('1/0'));
    }

    // Problem solving

    public function test_absolute_and_percent_tolerance(): void
    {
        $grader = new NumericalProblemGrader;
        $abs = $this->problem(10, ['tolerance' => 0.1]);
        $pct = $this->problem(200, ['tolerance' => 2, 'tolerance_type' => 'percent']);
        $exact = $this->problem(0.3);

        $this->assertSame(5.0, $grader->grade($abs, '10.1')->score);
        $this->assertSame(0.0, $grader->grade($abs, '10.11')->score);
        $this->assertSame(5.0, $grader->grade($pct, '196')->score);
        $this->assertSame(0.0, $grader->grade($pct, '195.9')->score);
        $this->assertSame(5.0, $grader->grade($exact, (string) (0.1 + 0.2))->score, 'Floating-point noise must not fail an exact match.');
    }

    public function test_unit_rules(): void
    {
        $grader = new NumericalProblemGrader;
        $required = $this->problem(12, ['units' => ['V', 'volts'], 'unit_required' => true]);
        $optional = $this->problem(12, ['units' => ['V']]);

        $this->assertFalse($grader->grade($required, '12 V')->needsReview);
        $this->assertFalse($grader->grade($required, '12volts')->needsReview);
        $this->assertSame('The required unit is missing from the final answer.', $grader->grade($required, '12')->reviewReason);
        $this->assertStringContainsString('"mV" is not an accepted unit', $grader->grade($required, '12 mV')->reviewReason);
        $this->assertFalse($grader->grade($optional, '12')->needsReview);
        $this->assertTrue($grader->grade($optional, '12 A')->needsReview);
    }

    public function test_unreadable_answer_goes_to_review(): void
    {
        $outcome = (new NumericalProblemGrader)->grade($this->problem(5), 'see solution');

        $this->assertTrue($outcome->needsReview);
        $this->assertFalse($outcome->rubricPending);
        $this->assertNull($outcome->details[0]['score']);
    }

    public function test_rubric_question_proposes_final_answer_points_and_waits_for_teacher(): void
    {
        $q = $this->problem(10, ['answer_points' => 2], ['Correct formula' => 2, 'Substitution' => 1]);
        $outcome = (new NumericalProblemGrader)->grade($q, '10');

        $this->assertSame(2.0, $outcome->score);
        $this->assertTrue($outcome->needsReview);
        $this->assertTrue($outcome->rubricPending);
        $this->assertSame([['label' => 'Final answer', 'max_points' => 2.0, 'score' => 2.0]], $outcome->details);
    }

    public function test_numeric_normalization_produces_canonical_text(): void
    {
        $grader = new NumericalProblemGrader;
        $q = $this->problem(1);

        $this->assertSame('0.0012 F', $grader->normalize($q, '1.2e-3 F'));
        $this->assertSame('1250', $grader->normalize($q, '1,250'));
        $this->assertSame('see solution', $grader->normalize($q, 'see solution'));
    }
}
