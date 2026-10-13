<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Programs, academic terms, courses, course offerings (sections) and teaching assignments.
 */
class AcademicController extends Controller
{
    public function index(): View
    {
        return view('admin.academics', [
            'programs' => Program::withCount('courses')->orderBy('code')->get(),
            'terms' => AcademicTerm::orderByDesc('academic_year')->orderBy('term')->get(),
            'courses' => Course::with('program')->orderBy('code')->get(),
            'offerings' => CourseOffering::with(['course', 'term', 'teachers'])->withCount('students')
                ->orderByDesc('academic_term_id')->get(),
            'teachers' => User::whereHas('roles', fn ($q) => $q->where('slug', Role::TEACHER))
                ->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeProgram(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:programs,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $program = Program::create($data);
        AuditLog::record('program.created', $program, [], $data);

        return back()->with('status', "Program {$program->code} added.");
    }

    public function storeTerm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'academic_year' => ['required', 'regex:/^\d{4}-\d{4}$/', Rule::unique('academic_terms')->where('term', $request->input('term'))],
            'term' => ['required', Rule::in(array_keys(AcademicTerm::TERMS))],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'is_current' => ['boolean'],
        ], [
            'academic_year.regex' => 'Use the format 2026-2027.',
            'academic_year.unique' => 'That term already exists.',
        ]);
        [$start, $end] = array_map('intval', explode('-', $data['academic_year']));
        if ($end !== $start + 1) {
            return back()->withErrors(['academic_year' => 'The second year must follow the first (e.g. 2026-2027).'])->withInput();
        }

        $term = DB::transaction(function () use ($data, $request) {
            if ($request->boolean('is_current')) {
                AcademicTerm::where('is_current', true)->update(['is_current' => false]);
            }

            return AcademicTerm::create([...$data, 'is_current' => $request->boolean('is_current')]);
        });
        AuditLog::record('term.created', $term, [], $data);

        return back()->with('status', $term->label().' added.');
    }

    public function storeCourse(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'program_id' => ['required', 'exists:programs,id'],
            'code' => ['required', 'string', 'max:30', Rule::unique('courses')->where('program_id', $request->input('program_id'))],
            'title' => ['required', 'string', 'max:255'],
        ]);
        $course = Course::create($data);
        AuditLog::record('course.created', $course, [], $data);

        return back()->with('status', "Course {$course->code} added.");
    }

    public function storeOffering(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'academic_term_id' => ['required', 'exists:academic_terms,id'],
            'section' => ['required', 'string', 'max:30', Rule::unique('course_offerings')
                ->where('course_id', $request->input('course_id'))
                ->where('academic_term_id', $request->input('academic_term_id'))],
            'teacher_ids' => ['array'],
            'teacher_ids.*' => ['integer', Rule::exists('role_user', 'user_id')->where('role_id', Role::where('slug', Role::TEACHER)->value('id'))],
        ], ['section.unique' => 'That section already exists for this course and term.']);

        $offering = DB::transaction(function () use ($data) {
            $offering = CourseOffering::create(collect($data)->only(['course_id', 'academic_term_id', 'section'])->all());
            $offering->teachers()->sync($data['teacher_ids'] ?? []);
            AuditLog::record('offering.created', $offering, [], $data);

            return $offering;
        });

        return back()->with('status', 'Section '.$offering->load('course')->label().' added.');
    }

    public function assignTeachers(Request $request, CourseOffering $offering): RedirectResponse
    {
        $data = $request->validate([
            'teacher_ids' => ['array'],
            'teacher_ids.*' => ['integer', Rule::exists('role_user', 'user_id')->where('role_id', Role::where('slug', Role::TEACHER)->value('id'))],
        ]);
        $old = $offering->teachers()->pluck('users.id')->all();
        $offering->teachers()->sync($data['teacher_ids'] ?? []);
        AuditLog::record('offering.teachers_assigned', $offering, ['teacher_ids' => $old], ['teacher_ids' => $data['teacher_ids'] ?? []]);

        return back()->with('status', 'Teaching assignment updated for '.$offering->load('course')->label().'.');
    }
}
