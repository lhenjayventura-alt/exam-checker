<?php

namespace App\Services\CodeExecution;

/**
 * Compares program output with the expected output.
 *
 *  - trim (default): line endings normalized; trailing spaces on each line and trailing blank
 *    lines ignored.
 *  - tokens: any whitespace difference ignored ("1  2\n3" equals "1 2 3").
 *  - exact: byte-for-byte after normalizing CRLF to LF.
 */
class OutputComparator
{
    public static function matches(string $actual, string $expected, string $mode = 'trim'): bool
    {
        $actual = str_replace("\r\n", "\n", $actual);
        $expected = str_replace("\r\n", "\n", $expected);

        return match ($mode) {
            'exact' => $actual === $expected,
            'tokens' => self::tokens($actual) === self::tokens($expected),
            default => self::trimLines($actual) === self::trimLines($expected),
        };
    }

    private static function trimLines(string $text): string
    {
        $lines = array_map('rtrim', explode("\n", $text));

        return rtrim(implode("\n", $lines), "\n");
    }

    private static function tokens(string $text): array
    {
        return preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    }
}
