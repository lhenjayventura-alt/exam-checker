<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfferingResource;
use App\Http\Resources\StudentResource;
use App\Models\CourseOffering;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OfferingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return OfferingResource::collection(
            CourseOffering::visibleTo($request->user())->with(['course', 'term'])->orderByDesc('academic_term_id')->get()
        );
    }

    /** Class roster, searchable by student number or name (used for student selection while scanning). */
    public function students(CourseOffering $offering, Request $request): AnonymousResourceCollection
    {
        $this->authorize('view', $offering);
        $term = $request->string('q')->trim()->value();

        return StudentResource::collection(
            $offering->students()
                ->when($term, fn ($q) => $q->where(fn ($w) => $w
                    ->where('student_number', 'like', Student::canonicalNumber($term).'%')
                    ->orWhere('last_name', 'like', "{$term}%")
                    ->orWhere('first_name', 'like', "{$term}%")))
                ->orderBy('last_name')->orderBy('first_name')->get()
        );
    }
}
