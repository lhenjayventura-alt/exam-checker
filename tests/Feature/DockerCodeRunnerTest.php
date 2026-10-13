<?php

namespace Tests\Feature;

use App\Services\CodeExecution\CodeRunnerUnavailable;
use App\Services\CodeExecution\DockerCodeRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Verifies the Docker invocations without Docker: isolation flags on every container, the
 * read-only source mount for test runs, and how exit codes map to outcomes.
 */
class DockerCodeRunnerTest extends TestCase
{
    private const LIMITS = ['time_ms' => 2000, 'memory_mb' => 128, 'output_kb' => 64];

    private function runner(): DockerCodeRunner
    {
        return new DockerCodeRunner('docker', 'exam-checker-runner:1', storage_path('framework/testing/code-runs'));
    }

    /** @param  array<string, array{0: int, 1?: string, 2?: string}>  $byStep  exit code, stdout, stderr per step */
    private function fakeDocker(array $byStep): void
    {
        Process::fake(function (PendingProcess $process) use ($byStep) {
            $cmd = $process->command;
            if ($cmd[1] === 'version' || $cmd[1] === 'image') {
                return Process::result('27.0.1');
            }
            if ($cmd[1] === 'kill') {
                return Process::result();
            }
            $name = $cmd[array_search('--name', $cmd, true) + 1];
            $step = str_ends_with($name, '-compile') ? 'compile' : 'test';
            [$exit, $out, $err] = $byStep[$step] + [0, '', ''];

            return Process::result($out, $err, $exit);
        });
    }

    public function test_every_container_is_isolated_and_tests_mount_source_read_only(): void
    {
        $this->fakeDocker(['compile' => [0], 'test' => [0, "5\n"]]);

        $report = $this->runner()->run('c', 'int main(){}', [7 => "2 3\n", 8 => "1 4\n"], self::LIMITS);

        $this->assertSame('ok', $report['compile']['status']);
        $this->assertSame(['ok', 'ok'], array_column($report['tests'], 'status'));
        $this->assertSame("5\n", $report['tests'][7]['stdout']);

        $runs = [];
        Process::assertRan(function (PendingProcess $p) use (&$runs) {
            if (($p->command[1] ?? null) === 'run') {
                $runs[] = $p;
            }

            return true;
        });
        $this->assertCount(3, $runs); // compile + 2 tests

        foreach ($runs as $i => $p) {
            $cmd = implode(' ', $p->command);
            foreach (['--network none', '--read-only', '--user 65534:65534', '--cap-drop ALL', '--security-opt no-new-privileges',
                '--pids-limit 64', '--cpus 1', '--log-driver none', '--rm'] as $flag) {
                $this->assertStringContainsString($flag, $cmd, "Missing {$flag}");
            }
            $this->assertMatchesRegularExpression('/--memory (\d+m) --memory-swap \1/', $cmd, 'Swap must equal memory.');
            $this->assertStringContainsString('noexec', $cmd);
            $this->assertStringContainsString('exam-checker-runner:1', $cmd);
            $mount = $p->command[array_search('--mount', $p->command, true) + 1];
            if ($i === 0) {
                $this->assertStringNotContainsString('readonly', $mount, 'The compiler writes the binary.');
                $this->assertContains('gcc', $p->command);
            } else {
                $this->assertStringEndsWith(',readonly', $mount);
                $this->assertSame(['timeout', '-k', '1', '2', '/work/prog'], array_slice($p->command, -5));
            }
        }
        // Expected outputs are not part of any invocation; only inputs are piped in.
        $this->assertSame("2 3\n", $runs[1]->input);
    }

    public function test_exit_codes_map_to_outcomes(): void
    {
        $cases = [124 => 'timeout', 137 => 'memory_limit', 1 => 'runtime_error', 139 => 'runtime_error'];
        foreach ($cases as $exit => $status) {
            $this->fakeDocker(['compile' => [0], 'test' => [$exit]]);
            $report = $this->runner()->run('python', 'print(1)', [1 => ''], self::LIMITS);
            $this->assertSame($status, $report['tests'][1]['status'], "exit {$exit}");
        }
    }

    public function test_compile_error_skips_tests_and_is_an_academic_result(): void
    {
        $this->fakeDocker(['compile' => [1, '', "main.c:1: error: expected ';'"]]);

        $report = $this->runner()->run('c', 'int main(){', [1 => '', 2 => ''], self::LIMITS);

        $this->assertSame('compile_error', $report['compile']['status']);
        $this->assertStringContainsString("expected ';'", $report['compile']['output']);
        $this->assertSame(['skipped', 'skipped'], array_column($report['tests'], 'status'));
    }

    public function test_sandbox_failures_are_infrastructure_errors_not_wrong_answers(): void
    {
        $this->fakeDocker(['compile' => [125, '', 'docker: Error response from daemon: No such image']]);
        $this->expectException(CodeRunnerUnavailable::class);
        $this->runner()->run('c', 'int main(){}', [1 => ''], self::LIMITS);
    }

    public function test_lost_daemon_connection_is_not_a_compile_error(): void
    {
        $this->fakeDocker(['compile' => [1, '', 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock']]);
        $this->expectException(CodeRunnerUnavailable::class);
        $this->runner()->run('c', 'int main(){}', [1 => ''], self::LIMITS);
    }

    public function test_unavailable_docker_is_reported_before_running(): void
    {
        Process::fake(['*' => Process::result('', 'docker: command not found', 127)]);

        $this->assertFalse($this->runner()->available());
        $this->expectException(CodeRunnerUnavailable::class);
        $this->runner()->run('c', 'int main(){}', [1 => ''], self::LIMITS);
    }

    public function test_work_directory_is_removed_after_the_run(): void
    {
        $this->fakeDocker(['compile' => [0], 'test' => [0]]);
        $this->runner()->run('c', 'int main(){}', [1 => ''], self::LIMITS);

        $this->assertSame([], glob(storage_path('framework/testing/code-runs/*')) ?: []);
    }
}
