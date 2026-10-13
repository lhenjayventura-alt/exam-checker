<?php

namespace App\Services\CodeExecution;

/**
 * Executes untrusted student code in an isolated sandbox. Implementations must never run the
 * code in the PHP process or directly on the host.
 */
interface CodeRunner
{
    /** True when the sandbox is installed and responding. */
    public function available(): bool;

    /**
     * Compile (when needed) and run the program once per test input.
     *
     * Only inputs are passed in; expected outputs never enter the sandbox.
     *
     * @param  array<int|string, string>  $inputs  stdin per test, keyed by test id.
     * @param  array{time_ms: int, memory_mb: int, output_kb: int}  $limits
     * @return array{
     *     compile: array{status: string, output: string, duration_ms: int},
     *     tests: array<int|string, array{status: string, stdout: string, stderr: string, exit_code: ?int, duration_ms: int}>
     * }  compile.status: ok | compile_error. tests.*.status: ok | runtime_error | timeout | memory_limit | output_limit | skipped.
     *
     * @throws CodeRunnerUnavailable when the sandbox itself fails (not the student's code).
     */
    public function run(string $language, string $source, array $inputs, array $limits): array;
}
