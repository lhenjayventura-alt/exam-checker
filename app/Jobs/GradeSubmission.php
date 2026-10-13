<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\Grading\GradingOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GradeSubmission implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // Coding questions run compile and test containers; allow time for them.
    public int $timeout = 900;

    public array $backoff = [10, 60];

    public function __construct(public readonly int $submissionId)
    {
        $this->afterCommit();
    }

    /** Prevent the same submission from being graded by two workers at once. */
    public function uniqueId(): string
    {
        return (string) $this->submissionId;
    }

    public function handle(GradingOrchestrator $orchestrator): void
    {
        $submission = Submission::find($this->submissionId);
        if ($submission === null || $submission->isFinalized()) {
            return;
        }

        $submission->update(['status' => 'processing']);
        $orchestrator->grade($submission);
    }

    public function failed(?Throwable $e): void
    {
        Submission::whereKey($this->submissionId)
            ->where('status', '!=', 'finalized')
            ->update([
                'status' => 'failed',
                'failure_reason' => 'Grading failed: '.($e ? class_basename($e).' '.$e->getMessage() : 'unknown error'),
            ]);
    }
}
