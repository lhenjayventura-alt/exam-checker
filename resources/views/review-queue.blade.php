<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Review queue</h2>
        <p class="text-sm text-gray-500">Answers that could not be graded automatically with confidence, oldest first.</p>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-flash />
            <x-panel>
                @if ($tasks->isEmpty())
                    <p class="text-sm text-gray-500">Nothing to review.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs uppercase text-gray-500">
                                <tr><th class="py-2 pe-4">Exam</th><th class="pe-4">Student</th><th class="pe-4">Question</th><th class="pe-4">Reason</th><th class="pe-4">Proposed</th><th></th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($tasks as $task)
                                    @php $r = $task->gradingResult; $s = $r->submission; @endphp
                                    <tr>
                                        <td class="py-2 pe-4">{{ $s->exam->title }}<span class="block text-xs text-gray-500">{{ $s->exam->offering->course->code }} {{ $s->exam->offering->section }}</span></td>
                                        <td class="pe-4">{{ $s->student->fullName() }}<span class="block font-mono text-xs text-gray-500">{{ $s->student->student_number }}</span></td>
                                        <td class="pe-4">{{ $r->question->number }} · {{ \App\Models\Question::TYPES[$r->question->type] }}</td>
                                        <td class="pe-4 text-amber-800">{{ $task->reason }}</td>
                                        <td class="pe-4">{{ $r->proposed_score ?? '—' }} / {{ $r->max_points }}</td>
                                        <td class="text-right"><a href="{{ route('submissions.show', $s) }}" class="text-indigo-700 hover:underline">Review</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $tasks->links() }}</div>
                @endif
            </x-panel>
        </div>
    </div>
</x-app-layout>
