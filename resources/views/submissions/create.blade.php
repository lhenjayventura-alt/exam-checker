<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Enter answers · {{ $exam->title }}</h2>
        <p class="text-sm text-gray-500">{{ $exam->offering->course->code }} · Section {{ $exam->offering->section }}. Answers are graded on the server after you save.</p>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-flash />

            @if ($students->isEmpty())
                <x-panel>
                    <p class="text-sm text-gray-600">Every student on the roster already has a submission, or the roster is empty.</p>
                    <a href="{{ route('offerings.show', $exam->offering) }}" class="mt-2 inline-block text-sm text-indigo-700 hover:underline">Manage the class roster</a>
                </x-panel>
            @else
                <x-panel>
                    <form method="POST" action="{{ route('exams.submissions.store', $exam) }}" enctype="multipart/form-data" class="space-y-6">
                        @csrf
                        <div class="max-w-md">
                            <x-input-label for="student_id" value="Student" />
                            <select id="student_id" name="student_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Select a student…</option>
                                @foreach ($students as $student)
                                    <option value="{{ $student->id }}" @selected(old('student_id') == $student->id)>{{ $student->student_number }} · {{ $student->fullName() }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">Only students without a submission are listed.</p>
                        </div>

                        <div>
                            <p class="mb-2 text-sm text-gray-600">
                                Type the letter(s) for multiple choice (e.g. <code>B</code> or <code>A,C</code>), <code>T</code>/<code>F</code> for true or false,
                                the written term for identification, and the final answer with its unit for problem solving (e.g. <code>4.7 kΩ</code>, <code>1.2e-3 A</code>).
                                Leave a box empty for a blank answer. Unclear responses are sent to review instead of being scored.
                            </p>
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                                @foreach ($questions as $q)
                                    @php
                                        $short = in_array($q->type, ['multiple_choice', 'true_false'], true);
                                        $hint = match ($q->type) {
                                            'true_false' => 'T/F',
                                            'multiple_choice' => $q->options->pluck('label')->join(''),
                                            'identification' => 'text',
                                            'problem_solving' => 'final answer'.($q->setting('units') ? ' + unit' : ''),
                                            default => '',
                                        };
                                    @endphp
                                    @continue($q->type === 'coding')
                                    <label class="block {{ $short ? '' : 'col-span-2' }}">
                                        <span class="text-xs font-medium text-gray-700">{{ $q->number }}
                                            <span class="text-gray-400">{{ $hint }}</span>
                                        </span>
                                        <input type="text" name="answers[{{ $q->number }}]" aria-label="Answer to question {{ $q->number }}" value="{{ old('answers.'.$q->number) }}" maxlength="255" autocomplete="off"
                                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 {{ $short ? 'text-center font-mono uppercase' : '' }}">
                                    </label>
                                @endforeach
                            </div>

                            @foreach ($questions->where('type', 'coding') as $q)
                                <div class="mt-4 rounded-md border border-gray-200 p-3">
                                    <label for="code-{{ $q->number }}" class="text-sm font-medium text-gray-700">
                                        {{ $q->number }}. Source code
                                        <span class="text-xs font-normal text-gray-500">({{ \App\Models\Question::LANGUAGES[$q->setting('language')] ?? $q->setting('language') }})</span>
                                    </label>
                                    <textarea id="code-{{ $q->number }}" name="answers[{{ $q->number }}]" rows="10" spellcheck="false" autocapitalize="off"
                                              class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                              placeholder="Paste the student's code, or upload the file below">{{ old('answers.'.$q->number) }}</textarea>
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-600">
                                        <label for="file-{{ $q->number }}">or upload a source file:</label>
                                        <input id="file-{{ $q->number }}" type="file" name="answer_files[{{ $q->number }}]" accept=".c,.h,.py,.txt,text/plain">
                                        <span>(UTF-8 text, up to {{ config('exam_checker.code_runner.max_source_kb') }} KB; pasted code takes priority)</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex flex-wrap justify-end gap-3">
                            <a href="{{ route('exams.show', $exam) }}" class="inline-flex items-center px-4 py-2 text-sm text-gray-600 hover:underline">Cancel</a>
                            <x-secondary-button type="submit" name="add_another" value="1">Save and enter next</x-secondary-button>
                            <x-primary-button>Save and view</x-primary-button>
                        </div>
                    </form>
                </x-panel>
            @endif
        </div>
    </div>
</x-app-layout>
