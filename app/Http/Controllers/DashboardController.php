<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\ReviewTask;
use App\Models\ScanUpload;
use App\Models\Submission;
use App\Models\User;
use App\Services\Scanning\SheetRecognizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('dashboard', [
            'teacher' => $user->isTeacher() ? $this->teacherData($user) : null,
            'chair' => $user->isProgramChair() ? $this->chairData($user) : null,
            'admin' => $user->isAdmin() ? $this->adminData() : null,
        ]);
    }

    private function teacherData(User $user): array
    {
        $offerings = $user->teachingOfferings()->with(['course', 'term'])
            ->withCount(['students', 'exams'])->get();
        $examIds = Exam::whereIn('course_offering_id', $offerings->pluck('id'))->pluck('id');

        return [
            'offerings' => $offerings,
            'open_reviews' => ReviewTask::openForTeacher($user)->count(),
            'submission_status' => Submission::whereIn('exam_id', $examIds)
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'recent_exams' => Exam::whereIn('id', $examIds)->with('offering.course')
                ->withCount('submissions')->latest()->take(5)->get(),
        ];
    }

    private function chairData(User $user): array
    {
        $programIds = $user->chairedProgramIds();
        $offeringIds = CourseOffering::whereHas('course', fn ($q) => $q->whereIn('program_id', $programIds))->pluck('id');

        $exams = Exam::whereIn('course_offering_id', $offeringIds)
            ->with(['offering.course', 'offering.teachers'])
            ->withCount([
                'submissions',
                'submissions as finalized_count' => fn ($q) => $q->where('status', 'finalized'),
                'submissions as review_count' => fn ($q) => $q->where('status', 'needs_review'),
            ])
            ->latest()->get();

        return [
            'offering_count' => $offeringIds->count(),
            'exams' => $exams,
            'open_reviews' => ReviewTask::where('status', 'open')
                ->whereHas('gradingResult.submission.exam', fn ($q) => $q->whereIn('course_offering_id', $offeringIds))
                ->count(),
        ];
    }

    private function adminData(): array
    {
        return [
            'users' => User::count(),
            'offerings' => CourseOffering::count(),
            'exams' => Exam::count(),
            'pending_jobs' => Submission::whereIn('status', ['pending', 'processing'])->count(),
            'failed' => Submission::where('status', 'failed')->with(['exam', 'student'])->latest()->take(10)->get(),
            'open_reviews' => ReviewTask::where('status', 'open')->count(),
            'scanner_ready' => Cache::remember('scanner.available', 60, fn () => app(SheetRecognizer::class)->available()),
            'failed_scans' => ScanUpload::where('status', 'failed')->with('exam')->latest()->take(10)->get(),
        ];
    }
}
