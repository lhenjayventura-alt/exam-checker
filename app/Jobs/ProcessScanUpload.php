<?php

namespace App\Jobs;

use App\Models\ScanUpload;
use App\Services\Scanning\ScanService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessScanUpload implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Recognition creates submissions; retrying a half-processed upload could duplicate work.
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $uploadId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->uploadId;
    }

    public function handle(ScanService $scans): void
    {
        $upload = ScanUpload::find($this->uploadId);
        if ($upload === null || $upload->status !== 'pending') {
            return;
        }
        $scans->process($upload);
    }

    public function failed(?Throwable $e): void
    {
        ScanUpload::whereKey($this->uploadId)->whereIn('status', ['pending', 'processing'])->update([
            'status' => 'failed',
            'error' => 'Processing failed: '.($e ? class_basename($e).' '.$e->getMessage() : 'unknown error'),
        ]);
    }
}
