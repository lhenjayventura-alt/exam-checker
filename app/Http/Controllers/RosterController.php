<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RosterController extends Controller
{
    /**
     * Add a student to the roster. An existing student record is reused when the student
     * number already exists; otherwise a new record is created from the given name.
     */
    public function store(CourseOffering $offering, Request $request): RedirectResponse
    {
        $this->authorize('manageRoster', $offering);

        $existing = Student::where('student_number', trim((string) $request->input('student_number')))->first();
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
