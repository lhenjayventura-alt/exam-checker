<?php

namespace Tests\Fakes;

use App\Services\CodeExecution\CodeRunner;
use App\Services\CodeExecution\CodeRunnerUnavailable;
use Closure;

/**
 * Scripted sandbox. Source markers drive the outcome:
 *   "COMPILE_ERROR" → compile_error, "INFRA" → CodeRunnerUnavailable,
 *   "TIMEOUT" → every test times out; otherwise $behaviour(input) produces stdout.
 * Default behaviour: print the sum of the integers in the input.
 */
class FakeCodeRunner implements CodeRunner
{
    public bool $isAvailable = true;

    public array $calls = [];

    private Closure $behaviour;

    public function __construct(?Closure $behaviour = null)
    {
        $this->behaviour = $behaviour ?? function (string $input): string {
            preg_match_all('/-?\d+/', $input, $m);

            return array_sum($m[0])."\n";
        };
    }

    public function available(): bool
    {
        return $this->isAvailable;
    }

    public function run(string $language, string $source, array $inputs, array $limits): array
    {
        $this->calls[] = compact('language', 'source', 'inputs', 'limits');

        if (str_contains($source, 'INFRA') || ! $this->isAvailable) {
            throw new CodeRunnerUnavailable('Docker daemon not reachable.');
        }
        if (str_contains($source, 'COMPILE_ERROR')) {
            return ['compile' => ['status' => 'compile_error', 'output' => "main.c:3: error: expected ';'", 'duration_ms' => 120],
                'tests' => array_map(fn () => ['status' => 'skipped', 'stdout' => '', 'stderr' => '', 'exit_code' => null, 'duration_ms' => 0], $inputs)];
        }

        $tests = [];
        foreach ($inputs as $id => $input) {
            $tests[$id] = str_contains($source, 'TIMEOUT')
                ? ['status' => 'timeout', 'stdout' => '', 'stderr' => '', 'exit_code' => 124, 'duration_ms' => $limits['time_ms']]
                : ['status' => 'ok', 'stdout' => ($this->behaviour)($input, $source), 'stderr' => '', 'exit_code' => 0, 'duration_ms' => 5];
        }

        return ['compile' => ['status' => 'ok', 'output' => '', 'duration_ms' => 100], 'tests' => $tests];
    }
}
