<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodeExecutionResult extends Model
{
    public const STATUSES = ['passed', 'failed', 'compile_error', 'runtime_error', 'timeout', 'memory_limit', 'output_limit', 'skipped'];

    protected $fillable = [
        'grading_result_id', 'coding_test_case_id', 'step', 'status', 'points',
        'exit_code', 'duration_ms', 'stdout', 'stderr',
    ];

    protected function casts(): array
    {
        return ['points' => 'float'];
    }

    public function gradingResult(): BelongsTo
    {
        return $this->belongsTo(GradingResult::class);
    }

    public function testCase(): BelongsTo
    {
        return $this->belongsTo(CodingTestCase::class, 'coding_test_case_id');
    }
}
