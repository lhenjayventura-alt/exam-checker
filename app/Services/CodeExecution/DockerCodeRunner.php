<?php

namespace App\Services\CodeExecution;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Runs code in throwaway Docker containers: one for compiling, one per test.
 *
 * Every container: no network, fixed memory with no swap, 1 CPU, PID limit, read-only root
 * filesystem, small noexec /tmp, unprivileged user, all capabilities dropped, no privilege
 * escalation, no logs kept. The source directory is mounted read-only for test runs.
 * Output is read incrementally and the container is killed when it exceeds the output limit.
 *
 * Set DOCKER_HOST to run containers on a separate execution host instead of the app server.
 */
class DockerCodeRunner implements CodeRunner
{
    private const COMPILE_SECONDS = 20;

    private const STARTUP_ALLOWANCE_SECONDS = 15;

    public function __construct(
        private readonly string $docker,
        private readonly string $image,
        private readonly string $workRoot,
    ) {}

    public function available(): bool
    {
        try {
            $server = Process::timeout(15)->run([$this->docker, 'version', '--format', '{{.Server.Version}}']);
            $image = Process::timeout(15)->run([$this->docker, 'image', 'inspect', '--format', '{{.Id}}', $this->image]);
        } catch (\Throwable) {
            return false;
        }

        return $server->successful() && $image->successful();
    }

    public function run(string $language, string $source, array $inputs, array $limits): array
    {
        if (! in_array($language, ['c', 'python'], true)) {
            throw new \InvalidArgumentException("Unsupported language: {$language}");
        }
        if (! $this->available()) {
            throw new CodeRunnerUnavailable("The code sandbox is not available (Docker not running or image {$this->image} not built).");
        }

        $id = Str::lower(Str::random(12));
        $dir = rtrim($this->workRoot, '/\\').DIRECTORY_SEPARATOR.$id;
        File::ensureDirectoryExists($dir);

        try {
            $file = $language === 'c' ? 'main.c' : 'main.py';
            File::put($dir.DIRECTORY_SEPARATOR.$file, $source);

            $compile = $this->compile($language, $dir, $id, $limits);
            $tests = [];
            foreach ($inputs as $testId => $input) {
                $tests[$testId] = $compile['status'] === 'ok'
                    ? $this->runTest($language, $dir, $id.'-'.Str::lower(Str::random(6)), (string) $input, $limits)
                    : ['status' => 'skipped', 'stdout' => '', 'stderr' => '', 'exit_code' => null, 'duration_ms' => 0];
            }

            return ['compile' => $compile, 'tests' => $tests];
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** Arguments shared by every sandbox container. Public for tests that verify the isolation flags. */
    public function sandboxArgs(string $name, string $dir, bool $writable, array $limits): array
    {
        $memory = max(32, (int) $limits['memory_mb']).'m';

        return [
            $this->docker, 'run', '--rm', '-i',
            '--name', $name,
            '--network', 'none',
            '--memory', $memory, '--memory-swap', $memory,
            '--cpus', '1',
            '--pids-limit', '64',
            '--read-only',
            '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,size=16m',
            '--user', '65534:65534',
            '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges',
            '--ulimit', 'nofile=64:64',
            '--ulimit', 'fsize=4194304:4194304',
            '--log-driver', 'none',
            '--env', 'PYTHONDONTWRITEBYTECODE=1',
            '--workdir', '/tmp',
            '--mount', 'type=bind,source='.$dir.',target=/work'.($writable ? '' : ',readonly'),
            $this->image,
        ];
    }

    private function compile(string $language, string $dir, string $id, array $limits): array
    {
        $command = $language === 'c'
            ? ['timeout', '-k', '2', (string) self::COMPILE_SECONDS, 'gcc', '-O2', '-std=c11', '-Wall', '-o', '/work/prog', '/work/main.c', '-lm']
            : ['timeout', '-k', '2', (string) self::COMPILE_SECONDS, 'python3', '-I', '-c',
                "import sys\ncompile(open('/work/main.py', encoding='utf-8').read(), 'main.py', 'exec')"];

        // The compiler needs a writable /work for the binary; nothing student-written runs here.
        $limits['memory_mb'] = max(256, (int) $limits['memory_mb']);
        $result = $this->execute(array_merge($this->sandboxArgs($id.'-compile', $dir, true, $limits), $command), '', self::COMPILE_SECONDS, 64);

        if ($result['infra']) {
            throw new CodeRunnerUnavailable('Sandbox failure while compiling: '.$result['stderr']);
        }

        return [
            'status' => $result['exit_code'] === 0 ? 'ok' : 'compile_error',
            'output' => $result['exit_code'] === 124 ? 'Compilation timed out.' : $result['stderr'].$result['stdout'],
            'duration_ms' => $result['duration_ms'],
        ];
    }

    private function runTest(string $language, string $dir, string $name, string $input, array $limits): array
    {
        $seconds = max(1, (int) ceil($limits['time_ms'] / 1000));
        $program = $language === 'c' ? ['/work/prog'] : ['python3', '-I', '-B', '/work/main.py'];
        $command = array_merge(['timeout', '-k', '1', (string) $seconds], $program);

        $result = $this->execute(array_merge($this->sandboxArgs($name, $dir, false, $limits), $command), $input, $seconds, (int) $limits['output_kb']);
        if ($result['infra']) {
            throw new CodeRunnerUnavailable('Sandbox failure while running a test: '.$result['stderr']);
        }

        $status = match (true) {
            $result['output_exceeded'] => 'output_limit',
            $result['timed_out'], $result['exit_code'] === 124 => 'timeout',
            $result['exit_code'] === 137 && $result['duration_ms'] >= $seconds * 1000 => 'timeout',
            $result['exit_code'] === 137 => 'memory_limit',
            $result['exit_code'] === 0 => 'ok',
            default => 'runtime_error',
        };

        return [
            'status' => $status,
            'stdout' => $result['stdout'],
            'stderr' => $result['stderr'],
            'exit_code' => $result['exit_code'],
            'duration_ms' => $result['duration_ms'],
        ];
    }

    /**
     * @return array{exit_code: ?int, stdout: string, stderr: string, duration_ms: int, timed_out: bool, output_exceeded: bool, infra: bool}
     */
    private function execute(array $args, string $input, int $seconds, int $outputKb): array
    {
        $name = $args[array_search('--name', $args, true) + 1];
        $limit = $outputKb * 1024;
        $started = hrtime(true);
        $stdout = $stderr = '';
        $timedOut = $exceeded = false;

        try {
            $process = Process::timeout($seconds + self::STARTUP_ALLOWANCE_SECONDS)->input($input)->start($args);
            while ($process->running()) {
                $stdout .= $process->latestOutput();
                $stderr .= $process->latestErrorOutput();
                if (strlen($stdout) > $limit || strlen($stderr) > $limit) {
                    $exceeded = true;
                    $this->kill($name);
                    break;
                }
                usleep(20_000);
            }
            $result = $process->wait();
            $stdout = $result->output();
            $stderr = $result->errorOutput();
            $exit = $result->exitCode();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
            $this->kill($name);
            $exit = null;
        }

        // 125: docker itself failed; 126/127: the command in the image could not be started.
        // A lost daemon connection makes the CLI exit 1, which must not look like a compile error.
        $daemonError = (bool) preg_match('/Cannot connect to the Docker daemon|error during connect|docker: Error response from daemon/i', $stderr);
        $infra = ! $timedOut && ! $exceeded && (in_array($exit, [125, 126, 127], true) || $daemonError);

        return [
            'exit_code' => $exit,
            'stdout' => substr($stdout, 0, $limit),
            'stderr' => substr($stderr, 0, 8192),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'timed_out' => $timedOut,
            'output_exceeded' => $exceeded,
            'infra' => $infra,
        ];
    }

    private function kill(string $name): void
    {
        Process::timeout(15)->run([$this->docker, 'kill', $name]);
    }
}
