<?php

namespace Tests\Feature;

use App\Services\CodeExecution\CodeRunner;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Runs real (and deliberately hostile) programs in the Docker sandbox. Skipped until Docker is
 * running and the image is built: docker build -t exam-checker-runner:1 runner/
 */
#[Group('sandbox')]
class DockerSandboxIntegrationTest extends TestCase
{
    private const LIMITS = ['time_ms' => 2000, 'memory_mb' => 64, 'output_kb' => 64];

    private CodeRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = app(CodeRunner::class);
        if (! $this->runner->available()) {
            $this->markTestSkipped('Docker sandbox not available (start Docker and build runner/Dockerfile).');
        }
    }

    private function one(string $language, string $source, string $input = ''): array
    {
        return $this->runner->run($language, $source, [1 => $input], self::LIMITS);
    }

    public function test_c_and_python_programs_run_with_stdin(): void
    {
        $c = $this->one('c', "#include <stdio.h>\nint main(void){int a,b; scanf(\"%d %d\",&a,&b); printf(\"%d\\n\",a+b); return 0;}", "2 3\n");
        $this->assertSame('ok', $c['compile']['status'], $c['compile']['output']);
        $this->assertSame("5\n", $c['tests'][1]['stdout']);

        $py = $this->one('python', "a, b = map(int, input().split())\nprint(a + b)\n", "4 5\n");
        $this->assertSame("9\n", $py['tests'][1]['stdout']);
    }

    public function test_compile_and_syntax_errors(): void
    {
        $this->assertSame('compile_error', $this->one('c', 'int main( {')['compile']['status']);
        $py = $this->one('python', "def f(:\n  pass\n");
        $this->assertSame('compile_error', $py['compile']['status']);
        $this->assertStringContainsString('SyntaxError', $py['compile']['output']);
    }

    public function test_infinite_loop_times_out(): void
    {
        $this->assertSame('timeout', $this->one('c', 'int main(void){for(;;){} }')['tests'][1]['status']);
    }

    public function test_memory_hog_is_stopped(): void
    {
        $status = $this->one('python', "x = bytearray(512 * 1024 * 1024)\nprint(len(x))\n")['tests'][1]['status'];
        $this->assertContains($status, ['memory_limit', 'runtime_error']); // OOM kill or MemoryError
    }

    public function test_output_flood_is_cut_off(): void
    {
        $this->assertSame('output_limit', $this->one('python', "while True:\n    print('x' * 1000)\n")['tests'][1]['status']);
    }

    public function test_no_network_no_writes_no_fork_bomb(): void
    {
        $net = $this->one('python', "import socket\ntry:\n    socket.create_connection(('1.1.1.1', 53), timeout=1)\n    print('connected')\nexcept OSError:\n    print('blocked')\n");
        $this->assertSame("blocked\n", $net['tests'][1]['stdout']);

        $write = $this->one('python', "try:\n    open('/work/hack.txt', 'w').write('x')\n    print('wrote')\nexcept OSError:\n    print('read-only')\n");
        $this->assertSame("read-only\n", $write['tests'][1]['stdout']);

        $fork = $this->one('c', "#include <unistd.h>\nint main(void){for(;;) fork();}");
        $this->assertContains($fork['tests'][1]['status'], ['timeout', 'runtime_error']);

        $user = $this->one('python', "import os\nprint(os.getuid())\n");
        $this->assertSame("65534\n", $user['tests'][1]['stdout']);
    }
}
