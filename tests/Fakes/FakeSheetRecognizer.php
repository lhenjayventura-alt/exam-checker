<?php

namespace Tests\Fakes;

use App\Services\Scanning\SheetRecognizer;

/**
 * Scripted recognizer for tests: layouts report a configurable page count, render writes a
 * stub PDF, and read returns the pages queued with queuePages(), creating the image files
 * those pages reference.
 */
class FakeSheetRecognizer implements SheetRecognizer
{
    public int $pagesPerSheet = 1;

    public bool $isAvailable = true;

    /** @var array<int, array> */
    public array $rendered = [];

    private array $pages = [];

    private ?string $failure = null;

    public function queuePages(array $pages): void
    {
        $this->pages = $pages;
    }

    public function failWith(string $error): void
    {
        $this->failure = $error;
    }

    public function available(): bool
    {
        return $this->isAvailable;
    }

    public function layout(array $spec): array
    {
        return ['pages' => $this->pagesPerSheet, 'items' => $spec['questions'], 'title' => $spec['title']];
    }

    public function render(array $layout, array $sheets, string $outputPath): void
    {
        $this->rendered[] = $sheets;
        file_put_contents($outputPath, "%PDF-1.4\n% fake answer sheets\n");
    }

    public function read(string $inputPath, string $workDir, array $layouts, ?int $defaultLayoutId, float $reviewBelow): array
    {
        if ($this->failure !== null) {
            return ['ok' => false, 'error' => $this->failure, 'pages' => []];
        }

        if (! is_dir($workDir)) {
            mkdir($workDir, 0777, true);
        }
        foreach ($this->pages as $i => &$page) {
            $page += ['index' => $i + 1, 'ok' => true, 'error' => null, 'warnings' => [], 'answers' => []];
            $page['aligned'] ??= $page['ok'] ? "page-{$page['index']}.jpg" : null;
            if ($page['aligned']) {
                file_put_contents($workDir.'/'.$page['aligned'], 'jpeg');
            }
            foreach ($page['answers'] as $answer) {
                if (! empty($answer['crop'])) {
                    file_put_contents($workDir.'/'.$answer['crop'], 'png');
                }
            }
        }

        return ['ok' => true, 'error' => null, 'pages' => $this->pages];
    }

    /** Helpers to build recognized items. */
    public static function bubbles(array $marked, float $confidence = 1.0, ?string $crop = null): array
    {
        return ['kind' => 'bubbles', 'marked' => $marked, 'fills' => array_fill_keys($marked, 0.9), 'confidence' => $confidence, 'crop' => $crop];
    }

    public static function written(bool $blank, string $crop): array
    {
        return ['kind' => 'write', 'blank' => $blank, 'ink' => $blank ? 0.0 : 0.03, 'confidence' => $blank ? 0.95 : 0.0, 'crop' => $crop];
    }
}
