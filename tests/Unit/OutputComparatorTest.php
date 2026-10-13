<?php

namespace Tests\Unit;

use App\Services\CodeExecution\OutputComparator;
use PHPUnit\Framework\TestCase;

class OutputComparatorTest extends TestCase
{
    public function test_trim_mode_ignores_trailing_spaces_blank_lines_and_crlf(): void
    {
        $this->assertTrue(OutputComparator::matches("3 \r\n5\n\n\n", "3\n5"));
        $this->assertFalse(OutputComparator::matches(" 3\n5", "3\n5"), 'Leading spaces are significant.');
        $this->assertFalse(OutputComparator::matches("3\n\n5", "3\n5"), 'Inner blank lines are significant.');
    }

    public function test_tokens_mode_ignores_all_whitespace_differences(): void
    {
        $this->assertTrue(OutputComparator::matches("1  2\n3\n", '1 2 3', 'tokens'));
        $this->assertFalse(OutputComparator::matches('1 2 4', '1 2 3', 'tokens'));
    }

    public function test_exact_mode(): void
    {
        $this->assertTrue(OutputComparator::matches("ok\r\n", "ok\n", 'exact'));
        $this->assertFalse(OutputComparator::matches('ok ', 'ok', 'exact'));
    }
}
