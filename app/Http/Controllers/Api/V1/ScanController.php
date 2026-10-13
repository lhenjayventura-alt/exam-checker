<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ScanController as WebScanController;
use App\Models\Exam;
use App\Models\ScanSheet;
use App\Models\ScanUpload;
use App\Models\Student;
use App\Models\SubmissionFile;
use App\Services\Scanning\ScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScanController extends Controller
{
    public function __construct(private readonly ScanService $scans) {}

    /** Multipart field "scan": PDF, JPEG or PNG. Returns 202; poll GET /scan-uploads/{id}. */
    public function store(Exam $exam, Request $request): JsonResponse
    {
        $this->authorize('grade', $exam);
        $request->validate(['scan' => WebScanController::fileRules()]);

        $upload = $this->scans->upload($exam, $request->file('scan'), $request->user());

        return response()->json(['data' => $this->uploadData($upload->fresh('sheets'))], 202);
    }

    public function show(ScanUpload $upload): JsonResponse
    {
        $this->authorize('grade', $upload->exam);

        return response()->json(['data' => $this->uploadData($upload->load('sheets.student'))]);
    }

    public function assign(ScanSheet $sheet, Request $request): JsonResponse
    {
        $this->authorize('grade', $sheet->upload->exam);
        $data = $request->validate(['student_id' => ['required', 'integer', 'exists:students,id']]);

        $sheet = $this->scans->assign($sheet, Student::findOrFail($data['student_id']), $request->user());

        return response()->json(['data' => $this->sheetData($sheet)]);
    }

    public function file(SubmissionFile $file): StreamedResponse
    {
        $this->authorize('view', $file->submission->exam);

        return Storage::disk($file->disk)->response($file->path, null, ['Cache-Control' => 'private, no-store']);
    }

    private function uploadData(ScanUpload $upload): array
    {
        return [
            'id' => $upload->id,
            'exam_id' => $upload->exam_id,
            'original_name' => $upload->original_name,
            'status' => $upload->status,
            'page_count' => $upload->page_count,
            'summary' => $upload->summary,
            'error' => $upload->error,
            'created_at' => $upload->created_at?->toIso8601String(),
            'sheets' => $upload->sheets->map(fn ($s) => $this->sheetData($s))->values(),
        ];
    }

    private function sheetData(ScanSheet $sheet): array
    {
        return [
            'id' => $sheet->id,
            'first_page' => $sheet->first_page,
            'page_count' => $sheet->page_count,
            'status' => $sheet->status,
            'message' => $sheet->message,
            'student_id' => $sheet->student_id,
            'submission_id' => $sheet->submission_id,
            'can_be_assigned' => $sheet->canBeAssigned(),
        ];
    }
}
