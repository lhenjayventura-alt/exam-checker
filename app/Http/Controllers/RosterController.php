<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\Student;
use App\Services\RosterImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RosterController extends Controller
{
    /**
     * Add a student to the roster. An existing student record is reused when the student
     * number already exists; otherwise a new record is created from the given name.
     */
    public function store(CourseOffering $offering, Request $request): RedirectResponse
    {
        $this->authorize('manageRoster', $offering);

        $existing = Student::withNumber($request->input('student_number'))->first();
        $data = $request->validate([
            'student_number' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-]+$/'],
            'last_name' => [Rule::requiredIf($existing === null), 'nullable', 'string', 'max:100'],
            'first_name' => [Rule::requiredIf($existing === null), 'nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
        ], ['student_number.regex' => 'Student numbers may contain only letters, digits and hyphens.']);

        $student = DB::transaction(function () use ($existing, $data, $offering) {
            $student = $existing ?? Student::create([
                'student_number' => trim($data['student_number']),
                'last_name' => trim($data['last_name']),
                'first_name' => trim($data['first_name']),
                'middle_name' => isset($data['middle_name']) ? trim($data['middle_name']) : null,
                'program_id' => $offering->course->program_id,
            ]);
            $offering->students()->syncWithoutDetaching([$student->id]);
            AuditLog::record('roster.added', $offering, [], ['student_id' => $student->id]);

            return $student;
        });

        $message = $existing
            ? "Existing student {$student->fullName()} ({$student->student_number}) added to the roster."
            : "Student {$student->fullName()} created and added to the roster.";

        return back()->with('status', $message);
    }

    public function import(CourseOffering $offering, Request $request, RosterImportService $importer): RedirectResponse
    {
        $this->authorize('manageRoster', $offering);
        $request->validate([
            'roster_file' => ['required', 'file', 'mimes:csv,txt', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel', 'max:1024'],
        ], ['roster_file.mimes' => 'Upload a .csv file.', 'roster_file.max' => 'The file must not be larger than 1 MB.']);

        $report = $importer->import($offering, $request->file('roster_file')->getRealPath());
        if (! $report['ok']) {
            return back()->withErrors(['roster_file' => array_slice($report['errors'], 0, 20)])
                ->with('import_error_count', count($report['errors']));
        }

        $message = "Roster imported: {$report['created']} new student(s) created, {$report['linked']} existing student(s) added, {$report['already']} already on the roster.";

        return back()->with('status', $message)->with('import_warnings', $report['mismatched']);
    }

    /** CSV template for roster import. */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            echo implode(',', RosterImportService::HEADERS).PHP_EOL;
        }, 'roster-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function destroy(CourseOffering $offering, Student $student, Request $request): RedirectResponse
    {
        $this->authorize('manageRoster', $offering);

        $hasSubmissions = $offering->exams()->whereHas('submissions', fn ($q) => $q->where('student_id', $student->id))->exists();
        if ($hasSubmissions) {
            return back()->withErrors(['roster' => 'This student has exam submissions in this class and cannot be removed.']);
        }

        $offering->students()->detach($student->id);
        AuditLog::record('roster.removed', $offering, ['student_id' => $student->id]);

        return back()->with('status', "{$student->fullName()} removed from the roster.");
    }
}
