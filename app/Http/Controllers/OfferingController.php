<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OfferingController extends Controller
{
    public function index(Request $request): View
    {
        $offerings = CourseOffering::visibleTo($request->user())
            ->with(['course.program', 'term', 'teachers'])
            ->withCount(['students', 'exams'])
            ->orderByDesc('academic_term_id')->get();

        return view('offerings.index', compact('offerings'));
    }

    public function show(CourseOffering $offering, Request $request): View
    {
        $this->authorize('view', $offering);

        $offering->load(['course.program', 'term', 'teachers']);

        return view('offerings.show', [
            'offering' => $offering,
            'students' => $offering->students()->orderBy('last_name')->orderBy('first_name')->get(),
            'exams' => $offering->exams()->withCount('submissions')->latest()->get(),
            'canManageRoster' => $request->user()->can('manageRoster', $offering),
            'canCreateExam' => $request->user()->can('createExam', $offering),
        ]);
    }
}
