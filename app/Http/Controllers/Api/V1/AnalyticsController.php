<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Services\ExamAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private readonly ExamAnalyticsService $analytics) {}

    public function summary(Exam $exam): JsonResponse
    {
        $this->authorize('view', $exam);

        return response()->json(['data' => $this->analytics->summary($exam), 'basis' => 'finalized results only']);
    }

    public function items(Exam $exam, Request $request): JsonResponse
    {
        $this->authorize('view', $exam);
        $data = $this->analytics->itemAnalysis($exam);

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
