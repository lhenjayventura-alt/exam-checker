<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $offering->course->code }} · {{ $offering->course->title }}
        </h2>
        <p class="text-sm text-gray-500">Section {{ $offering->section }} · {{ $offering->term->label() }} · {{ $offering->teachers->pluck('name')->join(', ') ?: 'No teacher assigned' }}</p>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <x-flash />

            <x-panel title="Examinations">
                @if ($canCreateExam)
                    <x-slot name="actions">
                        <a href="{{ route('exams.create', $offering) }}" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">New exam</a>
                    </x-slot>
                @endif

                @forelse ($exams as $exam)
                    <a href="{{ route('exams.show', $exam) }}" class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-2 last:border-0 hover:bg-gray-50">
                        <span>
                            <span class="font-medium text-gray-900">{{ $exam->title }}</span>
                            <span class="block text-xs text-gray-500">{{ \App\Models\Exam::TYPES[$exam->exam_type] }} · {{ $exam->exam_date?->format('M j, Y') ?? 'No date' }} · {{ $exam->submissions_count }} submissions</span>
                        </span>
                        <x-status-badge :status="$exam->status" />
                    </a>
                @empty
                    <p class="text-sm text-gray-500">No exams have been created for this class.</p>
                @endforelse
            </x-panel>

            <x-panel title="Class roster ({{ $students->count() }})">
                @if ($canManageRoster)
                    <form method="POST" action="{{ route('offerings.roster.store', $offering) }}" class="mb-6 grid gap-3 rounded-md bg-gray-50 p-4 sm:grid-cols-5">
                        @csrf
                        <div>
                            <x-input-label for="student_number" value="Student number" />
                            <x-text-input id="student_number" name="student_number" class="mt-1 block w-full" :value="old('student_number')" required />
                        </div>
                        <div>
                            <x-input-label for="last_name" value="Last name" />
                            <x-text-input id="last_name" name="last_name" class="mt-1 block w-full" :value="old('last_name')" />
                        </div>
                        <div>
                            <x-input-label for="first_name" value="First name" />
                            <x-text-input id="first_name" name="first_name" class="mt-1 block w-full" :value="old('first_name')" />
                        </div>
                        <div>
                            <x-input-label for="middle_name" value="Middle name" />
                            <x-text-input id="middle_name" name="middle_name" class="mt-1 block w-full" :value="old('middle_name')" />
                        </div>
                        <div class="flex items-end">
                            <x-primary-button class="w-full justify-center">Add to roster</x-primary-button>
                        </div>
                        <p class="text-xs text-gray-500 sm:col-span-5">If the student number already exists, the existing record is added and the names are ignored.</p>
                    </form>
                @endif

                @if ($students->isEmpty())
                    <p class="text-sm text-gray-500">No students on the roster yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs uppercase text-gray-500">
                                <tr><th class="py-2 pe-4">Student number</th><th class="pe-4">Name</th>@if ($canManageRoster)<th></th>@endif</tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($students as $student)
                                    <tr>
                                        <td class="py-2 pe-4 font-mono">{{ $student->student_number }}</td>
                                        <td class="pe-4">{{ $student->fullName() }}</td>
                                        @if ($canManageRoster)
                                            <td class="text-right">
                                                <form method="POST" action="{{ route('offerings.roster.destroy', [$offering, $student]) }}" onsubmit="return confirm('Remove {{ addslashes($student->fullName()) }} from this roster?')">
                                                    @csrf @method('DELETE')
                                                    <button class="text-xs text-red-700 hover:underline">Remove</button>
                                                </form>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-panel>
        </div>
    </div>
</x-app-layout>
