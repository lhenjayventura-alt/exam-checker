<?php

namespace App\Services\Scanning;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Runs scanner/omr_cli.py in a separate process. Arguments are passed as an array (no shell),
 * input as JSON on stdin, and output is a single JSON object on stdout.
 */
class PythonOmrRecognizer implements SheetRecognizer
{
    public function __construct(
        private readonly string $python,
        private readonly string $script,
        private readonly int $timeout,
    ) {}

    public function available(): bool
    {
        if (! is_file($this->python) || ! is_file($this->script)) {
            return false;
        }

        try {
            return ($this->run('version', [], 30)['ok'] ?? false) === true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function layout(array $spec): array
    {
        return $this->run('layout', $spec)['layout'];
    }

    public function render(array $layout, array $sheets, string $outputPath): void
    {
        $this->run('render', ['layout' => $layout, 'sheets' => $sheets, 'output' => $outputPath]);
    }

    public function read(string $inputPath, string $workDir, array $layouts, ?int $defaultLayoutId, float $reviewBelow): array
    {
        return $this->run('read', [
            'input' => $inputPath,
            'work_dir' => $workDir,
            'layouts' => (object) $layouts,
            'default_layout' => $defaultLayoutId,
            'review_below' => $reviewBelow,
        ]);
    }

    private function run(string $command, array $payload, ?int $timeout = null): array
    {
        if (! is_file($this->python)) {
            throw new RuntimeException('The answer-sheet scanner is not installed (Python environment not found). See README, "Scanner setup".');
        }

        $result = Process::timeout($timeout ?? $this->timeout)
            ->input(json_encode($payload, JSON_THROW_ON_ERROR))
            ->run([$this->python, $this->script, $command]);

        $output = json_decode(trim($result->output()), true);
        if (! is_array($output)) {
            throw new RuntimeException('The scanner returned no readable result. '.trim(mb_substr($result->errorOutput(), -500)));
        }
        if (($output['ok'] ?? false) !== true && $command !== 'read') {
            throw new RuntimeException('Scanner error: '.($output['error'] ?? 'unknown error'));
        }

        return $output;
    }
}
