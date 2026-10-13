<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Dashboard</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
            <x-flash />

            @if (! $teacher && ! $chair && ! $admin)
                <x-panel>
                    <p class="text-gray-700">Your account has no role assigned yet. Ask an administrator to assign you as a teacher, program chair or administrator.</p>
                </x-panel>
            @endif

            @if ($teacher)
                <div class="space-y-4">
                    <h3 class="text-lg font-semibold text-gray-800">Teaching</h3>
                    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <x-stat label="Assigned classes" :value="$teacher['offerings']->count()" />
                        <x-stat label="Awaiting review" :value="$teacher['open_reviews']" hint="Open review tasks" />
                        <x-stat label="Being processed" :value="($teacher['submission_status']['pending'] ?? 0) + ($teacher['submission_status']['processing'] ?? 0)" />
                        <x-stat label="Finalized submissions" :value="$teacher['submission_status']['finalized'] ?? 0" />
                    </div>

                    <div class="grid gap-6 lg:grid-cols-2">
                        <x-panel title="My classes">
                            @forelse ($teacher['offerings'] as $offering)
                                <a href="{{ route('offerings.show', $offering) }}" class="flex items-center justify-between border-b border-gray-100 py-2 last:border-0 hover:bg-gray-50">
                                    <span>
                                        <span class="font-medium text-gray-900">{{ $offering->course->code }}</span>
                                        <span class="text-gray-600">{{ $offering->course->title }} · {{ $offering->section }}</span>
                                        <span class="block text-xs text-gray-500">{{ $offering->term->label() }}</span>
                                    </span>
                                    <span class="text-xs text-gray-500">{{ $offering->students_count }} students · {{ $offering->exams_count }} exams</span>
                                </a>
                            @empty
                                <p class="text-sm text-gray-500">No classes are assigned to you yet. An administrator assigns teachers to course sections.</p>
                            @endforelse
                        </x-panel>

                        <x-panel title="Recent exams">
                            @forelse ($teacher['recent_exams'] as $exam)
                                <a href="{{ route('exams.show', $exam) }}" class="flex items-center justify-between border-b border-gray-100 py-2 last:border-0 hover:bg-gray-50">
                                    <span>
                                        <span class="font-medium text-gray-900">{{ $exam->title }}</span>
                                        <span class="block text-xs text-gray-500">{{ $exam->offering->course->code }} · {{ $exam->submissions_count }} submissions</span>
                                    </span>
                                    <x-status-badge :status="$exam->status" />
                                </a>
                            @empty
                                <p class="text-sm text-gray-500">No exams yet. Open a class to create one.</p>
                            @endforelse
                        </x-panel>
                    </div>
                </div>
            @endif

            @if ($chair)
                <div class="space-y-4">
                    <h3 class="text-lg font-semibold text-gray-800">Program oversight</h3>
                    <div class="grid grid-cols-2 gap-4 md:grid-cols-3">
                        <x-stat label="Program sections" :value="$chair['offering_count']" />
                        <x-stat label="Exams" :value="$chair['exams']->count()" />
                        <x-stat label="Unresolved review tasks" :value="$chair['open_reviews']" />
                    </div>
                    <x-panel title="Grading completion by exam">
                        @if ($chair['exams']->isEmpty())
                            <p class="text-sm text-gray-500">No exams have been created in your program yet.</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="text-left text-xs uppercase text-gray-500">
                                        <tr><th class="py-2 pe-4">Exam</th><th class="pe-4">Class</th><th class="pe-4">Teacher(s)</th><th class="pe-4">Status</th><th class="pe-4 text-right">Finalized</th><th class="text-right">Needs review</th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($chair['exams'] as $exam)
                                            <tr>
                                                <td class="py-2 pe-4"><a class="text-indigo-700 hover:underline" href="{{ route('exams.show', $exam) }}">{{ $exam->title }}</a></td>
                                                <td class="pe-4">{{ $exam->offering->course->code }} {{ $exam->offering->section }}</td>
                                                <td class="pe-4">{{ $exam->offering->teachers->pluck('name')->join(', ') ?: '—' }}</td>
                                                <td class="pe-4"><x-status-badge :status="$exam->status" /></td>
                                                <td class="pe-4 text-right">{{ $exam->finalized_count }} / {{ $exam->submissions_count }}</td>
                                                <td class="text-right">{{ $exam->review_count }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-panel>
                </div>
            @endif

            @if ($admin)
                <div class="space-y-4">
                    <h3 class="text-lg font-semibold text-gray-800">System</h3>
                    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
                        <x-stat label="Answer-sheet scanner" :value="$admin['scanner_ready'] ? 'Ready' : 'Not installed'" :hint="$admin['scanner_ready'] ? 'Python + OpenCV worker responding' : 'See README: Scanner setup'" />
                        <x-stat label="Users" :value="$admin['users']" />
                        <x-stat label="Course sections" :value="$admin['offerings']" />
                        <x-stat label="Exams" :value="$admin['exams']" />
                        <x-stat label="Pending grading jobs" :value="$admin['pending_jobs']" hint="Requires a running queue worker" />
                        <x-stat label="Open review tasks" :value="$admin['open_reviews']" />
                    </div>
                    <x-panel title="Failed grading jobs">
                        @forelse ($admin['failed'] as $s)
                            <div class="border-b border-gray-100 py-2 text-sm last:border-0">
                                <span class="font-medium">{{ $s->exam->title }}</span> · {{ $s->student->student_number }}
                                <span class="block text-xs text-red-700">{{ $s->failure_reason }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">No failed grading jobs.</p>
                        @endforelse
                    </x-panel>
                    <x-panel title="Failed scan uploads">
                        @forelse ($admin['failed_scans'] as $scan)
                            <div class="border-b border-gray-100 py-2 text-sm last:border-0">
                                <span class="font-medium">{{ $scan->exam->title }}</span> · {{ $scan->original_name }} · {{ $scan->created_at->format('M j, Y g:i A') }}
                                <span class="block text-xs text-red-700">{{ $scan->error }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">No failed scan uploads.</p>
                        @endforelse
                    </x-panel>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
