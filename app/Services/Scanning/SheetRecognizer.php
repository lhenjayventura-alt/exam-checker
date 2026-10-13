<?php

namespace App\Services\Scanning;

/**
 * Replaceable answer-sheet recognition engine. The default implementation is the local
 * Python/OpenCV worker; another engine (a different OMR library or an OCR service) can be
 * bound in AppServiceProvider without changing grading.
 */
interface SheetRecognizer
{
    /** True when the engine is installed and responding. */
    public function available(): bool;

    /**
     * Compute sheet geometry for a version.
     *
     * @param  array{title: string, subtitle: string, questions: list<array{number: int, type: string, labels?: list<string>}>}  $spec
     */
    public function layout(array $spec): array;

    /**
     * Write a printable PDF to $outputPath.
     *
     * @param  list<array{qr: list<string>, name: ?string, student_number: ?string}>  $sheets  One entry per printed sheet.
     */
    public function render(array $layout, array $sheets, string $outputPath): void;

    /**
     * Read an uploaded PDF or image. Derived images are written into $workDir.
     *
     * @param  array<int|string, array>  $layouts  Layouts the pages may belong to, keyed by layout id.
     * @return array{ok: bool, error: ?string, pages: list<array>}
     */
    public function read(string $inputPath, string $workDir, array $layouts, ?int $defaultLayoutId, float $reviewBelow): array;
}
