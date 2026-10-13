<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\ReviewTask;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $offeringIds = CourseOffering::visibleTo($user)->pluck('id');
        $examIds = Exam::whereIn('course_offering_id', $offeringIds)->pluck('id');

        return response()->json(['data' => [
            'offerings' => $offeringIds->count(),
            'exams' => $examIds->count(),
            'submissions_by_status' => Submission::whereIn('exam_id', $examIds)
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'open_review_tasks' => $user->isTeacher() ? ReviewTask::openForTeacher($user)->count() : null,
        ]]);
    }
}
