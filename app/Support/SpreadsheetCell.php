<?php

namespace App\Support;

/**
 * Neutralizes values a spreadsheet would interpret as formulas (CSV/formula injection).
 */
final class SpreadsheetCell
{
    public static function safe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /** @param  array<int, mixed>  $row */
    public static function row(array $row): array
    {
        return array_map([self::class, 'safe'], $row);
    }
}
