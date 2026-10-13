<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamVersion;
use App\Services\ExamAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private readonly ExamAnalyticsService $analytics) {}

    public function summary(Exam $exam, Request $request): JsonResponse
    {
        $this->authorize('view', $exam);

        return response()->json([
            'data' => $this->analytics->summary($exam, $this->version($exam, $request)),
            'basis' => 'finalized results of one exam version (default: newest version with results)',
        ]);
    }

    /** Optional ?version=N; defaults to the newest version that has finalized results. */
    private function version(Exam $exam, Request $request): ?ExamVersion
    {
        if (! $request->filled('version')) {
            return null;
        }

        return $exam->versions()->where('version_number', $request->integer('version'))->firstOr(fn () => abort(404, 'No such exam version.'));
    }

    public function items(Exam $exam, Request $request): JsonResponse
    {
        $this->authorize('view', $exam);
        $data = $this->analytics->itemAnalysis($exam, $this->version($exam, $request));

        // Users without grading rights must not learn the key from the distractor table.
        if (! $request->user()->can('grade', $exam)) {
            foreach ($data['items'] as &$item) {
                foreach ($item['distractors'] ?? [] as $i => $row) {
                    unset($item['distractors'][$i]['is_key']);
                }
            }
            unset($item);
        }

        return response()->json([
            'data' => $data,
            'methods' => [
                'difficulty' => 'Mean of awarded/max points; equals proportion correct for all-or-nothing items.',
                'discrimination' => 'Upper-lower index using top and bottom 27% by total score; null when n < '.ExamAnalyticsService::MIN_N_DISCRIMINATION.'.',
            ],
        ]);
    }
}
