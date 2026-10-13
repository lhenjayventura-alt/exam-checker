<?php

namespace App\Services\Grading;

/**
 * Parses a numeric answer with an optional trailing unit.
 *
 * Accepted forms: "12.5", "-0.25 A", "1,250 ohm", ".5", "1.2e-3 F", "1.2E3",
 * "4.7 x 10^3 Hz", "4.7×10^-3", "3/4", "-1/2 m".
 */
final class NumericAnswer
{
    private function __construct(
        public readonly float $value,
        public readonly ?string $unit,
    ) {}

    public static function parse(?string $raw): ?self
    {
        $text = trim(str_replace(["\u{2212}", "\u{00D7}"], ['-', 'x'], (string) $raw));
        if ($text === '') {
            return null;
        }

        // Fraction: a/b
        if (preg_match('/^([+-]?\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)\s*(.*)$/u', $text, $m)) {
            if ((float) $m[2] == 0.0) {
                return null;
            }

            return new self((float) $m[1] / (float) $m[2], self::unit($m[3]));
        }

        $pattern = '/^([+-]?(?:\d{1,3}(?:,\d{3})+|\d+)?(?:\.\d+)?)'   // mantissa, optional thousands separators
            .'(?:\s*[eE]\s*([+-]?\d+)|\s*[xX*]\s*10\s*\^\s*([+-]?\d+))?' // exponent: e-3 or x 10^-3
            .'\s*(.*)$/u';
        if (! preg_match($pattern, $text, $m) || ! preg_match('/\d/', $m[1])) {
            return null;
        }

        $value = (float) str_replace(',', '', $m[1]);
        $exponent = ($m[2] ?? '') !== '' ? (int) $m[2] : (($m[3] ?? '') !== '' ? (int) $m[3] : 0);

        return new self($value * (10 ** $exponent), self::unit($m[4] ?? ''));
    }

    private static function unit(string $rest): ?string
    {
        $rest = trim($rest);

        return $rest === '' ? null : $rest;
    }

    /** Canonical text stored as the normalized answer, e.g. "0.0012 F". */
    public function canonical(): string
    {
        $number = rtrim(rtrim(sprintf('%.10F', $this->value), '0'), '.');
        if (abs($this->value) >= 1e12 || (abs($this->value) < 1e-6 && $this->value != 0.0)) {
            $number = sprintf('%.10G', $this->value);
        }

        return $this->unit === null ? $number : $number.' '.$this->unit;
    }

    /** Units match ignoring surrounding/inner spaces; case matters (mA vs MA). */
    public static function sameUnit(?string $a, ?string $b): bool
    {
        $strip = fn (?string $u) => preg_replace('/\s+/u', '', (string) $u);

        return $strip($a) === $strip($b);
    }
}
