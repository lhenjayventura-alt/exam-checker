@php
    $oldOptions = old('options', collect(range('A', 'D'))->map(fn ($l) => ['label' => $l, 'text' => ''])->all());
    $oldCriteria = old('criteria', []);
    $oldTests = old('tests', [['name' => 'Sample', 'input' => '', 'expected_output' => '', 'points' => 1, 'hidden' => false]]);
    $input ='block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
@endphp

<x-panel title="Add a question">
    <form method="POST" action="{{ route('exams.questions.store', $exam) }}" class="space-y-4"
          x-data="{
              type: @js(old('type', 'multiple_choice')),
              points: @js((float) old('points', 1)),
              multiple: @js((bool) old('multiple_select', false)),
              options: @js(array_values($oldOptions)),
              correct: @js(old('correct', [])),
              answerPoints: @js(old('answer_points') !== null ? (float) old('answer_points') : null),
              criteria: @js(array_values($oldCriteria)),
              tests: @js(array_values($oldTests)),
              testTotal() { return this.tests.reduce((sum, t) => sum + (parseFloat(t.points) || 0), 0); },
              addOption() {
                  if (this.options.length >= {{ config('exam_checker.max_options') }}) return;
                  this.options.push({ label: String.fromCharCode(65 + this.options.length), text: '' });
              },
              removeOption() {
                  if (this.options.length <= 2) return;
                  const removed = this.options.pop();
                  this.correct = this.correct.filter(l => l !== removed.label);
              },
              rubricTotal() { return this.criteria.reduce((sum, c) => sum + (parseFloat(c.max_points) || 0), 0); },
              finalPoints() {
                  if (this.type === 'coding') return this.testTotal();
                  return this.answerPoints === null || this.answerPoints === '' ? this.points : parseFloat(this.answerPoints) || 0;
              },
              balanced() { return Math.abs(this.finalPoints() + this.rubricTotal() - (parseFloat(this.points) || 0)) < 0.001; },
          }">
        @csrf
        <div class="grid gap-4 sm:grid-cols-4">
            <div>
                <x-input-label for="q_number" value="Number" />
                <x-text-input id="q_number" name="number" type="number" min="1" class="mt-1 block w-full" :value="old('number')" placeholder="Next" />
            </div>
            <div>
                <x-input-label for="q_type" value="Type" />
                <select id="q_type" name="type" x-model="type" @change="correct = []" class="mt-1 {{ $input }}">
                    @foreach (\App\Models\Question::SUPPORTED_TYPES as $t)
                        <option value="{{ $t }}">{{ \App\Models\Question::TYPES[$t] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-input-label for="q_points" value="Points" />
                <input id="q_points" name="points" type="number" step="0.01" min="0.01" x-model.number="points" required class="mt-1 {{ $input }}">
            </div>
        </div>
        <div>
            <x-input-label for="q_prompt" value="Question text (optional)" />
            <textarea id="q_prompt" name="prompt" rows="2" maxlength="5000" class="mt-1 {{ $input }}">{{ old('prompt') }}</textarea>
        </div>

        {{-- Multiple choice --}}
        <template x-if="type === 'multiple_choice'">
            <div class="space-y-3">
                <div class="flex flex-wrap items-center gap-6 text-sm">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="multiple_select" value="1" x-model="multiple" @change="correct = []" class="rounded border-gray-300 text-indigo-600">
                        Multiple correct options
                    </label>
                    <label x-show="multiple" class="inline-flex items-center gap-2">
                        Partial credit
                        <select name="partial_credit" class="rounded-md border-gray-300 py-1 text-sm">
                            <option value="none">None (all-or-nothing)</option>
                            <option value="proportional">Proportional (wrong picks deduct)</option>
                        </select>
                    </label>
                </div>
                <p class="text-xs text-gray-500">Mark the correct option(s). Options can be left without text when only letters appear on the paper.</p>
                <template x-for="(opt, i) in options" :key="i">
                    <div class="flex items-center gap-3">
                        <input type="hidden" :name="`options[${i}][label]`" :value="opt.label">
                        <label class="inline-flex w-14 items-center gap-2 text-sm font-semibold">
                            <input :type="multiple ? 'checkbox' : 'radio'" name="correct[]" :value="opt.label" x-model="correct" class="border-gray-300 text-green-600" :class="multiple ? 'rounded' : ''" :aria-label="`Option ${opt.label} is correct`">
                            <span x-text="opt.label"></span>
                        </label>
                        <input type="text" :name="`options[${i}][text]`" x-model="opt.text" maxlength="1000" placeholder="Option text" class="{{ $input }}" :aria-label="`Option ${opt.label} text`">
                    </div>
                </template>
                <div class="flex gap-3 text-xs">
                    <button type="button" @click="addOption()" class="text-indigo-700 hover:underline">+ Add option</button>
                    <button type="button" @click="removeOption()" class="text-gray-600 hover:underline">− Remove last option</button>
                </div>
            </div>
        </template>

        {{-- True or false --}}
        <template x-if="type === 'true_false'">
            <div class="flex gap-6 text-sm">
                <span class="font-medium text-gray-700">Correct answer:</span>
                <label class="inline-flex items-center gap-2"><input type="radio" name="correct[]" value="true" x-model="correct" class="text-green-600"> True</label>
                <label class="inline-flex items-center gap-2"><input type="radio" name="correct[]" value="false" x-model="correct" class="text-green-600"> False</label>
            </div>
        </template>

        {{-- Identification --}}
        <template x-if="type === 'identification'">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="q_accepted" value="Accepted answers (one per line)" />
                    <textarea id="q_accepted" name="accepted_answers" rows="4" class="mt-1 {{ $input }}" placeholder="Ohm's law&#10;Ohms law">{{ is_array(old('accepted_answers')) ? implode("\n", old('accepted_answers')) : old('accepted_answers') }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">Include synonyms and accepted spellings. Punctuation and extra spaces are ignored when comparing.</p>
                </div>
                <div class="space-y-3 text-sm">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="case_sensitive" value="1" @checked(old('case_sensitive')) class="mt-0.5 rounded border-gray-300 text-indigo-600">
                        <span>Case-sensitive<span class="block text-xs text-gray-500">Use for chemical symbols or code identifiers (e.g. “Co” vs “CO”).</span></span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="fuzzy" value="1" @checked(old('fuzzy')) class="mt-0.5 rounded border-gray-300 text-indigo-600">
                        <span>Flag near misses for review<span class="block text-xs text-gray-500">Likely misspellings are sent to you to confirm. They are never awarded automatically.</span></span>
                    </label>
                </div>
            </div>
        </template>

        {{-- Problem solving --}}
        <template x-if="type === 'problem_solving'">
            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-4">
                    <div>
                        <x-input-label for="q_expected" value="Expected answer" />
                        <input id="q_expected" name="expected" type="number" step="any" required value="{{ old('expected') }}" class="mt-1 {{ $input }}">
                    </div>
                    <div>
                        <x-input-label for="q_tolerance" value="Tolerance" />
                        <input id="q_tolerance" name="tolerance" type="number" step="any" min="0" value="{{ old('tolerance', 0) }}" class="mt-1 {{ $input }}">
                    </div>
                    <div>
                        <x-input-label for="q_tolerance_type" value="Tolerance type" />
                        <select id="q_tolerance_type" name="tolerance_type" class="mt-1 {{ $input }}">
                            <option value="absolute" @selected(old('tolerance_type') !== 'percent')>± absolute value</option>
                            <option value="percent" @selected(old('tolerance_type') === 'percent')>± percent of expected</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="q_units" value="Accepted units" />
                        <input id="q_units" name="units" value="{{ is_array(old('units')) ? implode(', ', old('units')) : old('units') }}" placeholder="V, volts" class="mt-1 {{ $input }}">
                    </div>
                </div>
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="unit_required" value="1" @checked(old('unit_required')) class="rounded border-gray-300 text-indigo-600">
                    Unit is required (a missing or different unit is sent to review; units are not converted)
                </label>

                <div class="rounded-md bg-gray-50 p-3">
                    <div class="flex flex-wrap items-end gap-4">
                        <div>
                            <x-input-label for="q_answer_points" value="Points for the final answer" />
                            <input id="q_answer_points" name="answer_points" type="number" step="0.01" min="0" x-model="answerPoints" :placeholder="points" class="mt-1 w-40 {{ $input }}">
                        </div>
                        <p class="text-xs text-gray-500">Checked automatically, all or nothing. Remaining points go to rubric criteria scored by you.</p>
                    </div>

                    <p class="mt-4 text-sm font-medium text-gray-700">Solution rubric</p>
                    <template x-for="(c, i) in criteria" :key="i">
                        <div class="mt-2 grid gap-2 sm:grid-cols-6">
                            <input :name="`criteria[${i}][label]`" x-model="c.label" required maxlength="150" placeholder="Criterion (e.g. Correct formula)" class="sm:col-span-2 {{ $input }}" :aria-label="`Criterion ${i + 1} label`">
                            <input :name="`criteria[${i}][description]`" x-model="c.description" maxlength="1000" placeholder="What earns full points (optional)" class="sm:col-span-3 {{ $input }}" :aria-label="`Criterion ${i + 1} description`">
                            <div class="flex gap-2">
                                <input :name="`criteria[${i}][max_points]`" x-model="c.max_points" type="number" step="0.01" min="0.01" required placeholder="Pts" class="{{ $input }}" :aria-label="`Criterion ${i + 1} points`">
                                <button type="button" @click="criteria.splice(i, 1)" class="text-xs text-red-700 hover:underline" :aria-label="`Remove criterion ${i + 1}`">✕</button>
                            </div>
                        </div>
                    </template>
                    <button type="button" @click="criteria.push({ label: '', description: '', max_points: '' })" class="mt-2 text-xs text-indigo-700 hover:underline">+ Add criterion</button>
                    <p class="mt-2 text-xs" :class="balanced() ? 'text-green-700' : 'text-amber-700'">
                        Final answer <span x-text="finalPoints()"></span> + rubric <span x-text="rubricTotal()"></span> = <span x-text="finalPoints() + rubricTotal()"></span> of <span x-text="points"></span> points
                        <span x-show="!balanced()">: these must match.</span>
                    </p>
                </div>
            </div>
        </template>

        {{-- Coding --}}
        <template x-if="type === 'coding'">
            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-4">
                    <div>
                        <x-input-label for="q_language" value="Language" />
                        <select id="q_language" name="language" class="mt-1 {{ $input }}">
                            @foreach (\App\Models\Question::LANGUAGES as $value => $label)
                                <option value="{{ $value }}" @selected(old('language') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="q_time" value="Time limit per test (ms)" />
                        <input id="q_time" name="time_limit_ms" type="number" min="100" max="10000" step="100" value="{{ old('time_limit_ms', 2000) }}" class="mt-1 {{ $input }}">
                    </div>
                    <div>
                        <x-input-label for="q_memory" value="Memory limit (MB)" />
                        <input id="q_memory" name="memory_mb" type="number" min="32" max="512" value="{{ old('memory_mb', 128) }}" class="mt-1 {{ $input }}">
                    </div>
                    <div>
                        <x-input-label for="q_comparison" value="Output comparison" />
                        <select id="q_comparison" name="comparison" class="mt-1 {{ $input }}">
                            @foreach (\App\Models\Question::OUTPUT_COMPARISONS as $value => $label)
                                <option value="{{ $value }}" @selected(old('comparison', 'trim') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="rounded-md bg-gray-50 p-3">
                    <p class="text-sm font-medium text-gray-700">Test cases</p>
                    <p class="text-xs text-gray-500">The program reads the input from standard input; its standard output is compared with the expected output. Hidden tests are never shown with results. Each passing test earns its points.</p>
                    <template x-for="(t, i) in tests" :key="i">
                        <div class="mt-3 grid gap-2 rounded border border-gray-200 bg-white p-2 sm:grid-cols-6">
                            <input :name="`tests[${i}][name]`" x-model="t.name" required maxlength="100" placeholder="Name" class="sm:col-span-2 {{ $input }}" :aria-label="`Test ${i + 1} name`">
                            <input :name="`tests[${i}][points]`" x-model="t.points" type="number" step="0.01" min="0.01" required placeholder="Pts" class="{{ $input }}" :aria-label="`Test ${i + 1} points`">
                            <label class="inline-flex items-center gap-2 text-xs">
                                <input type="hidden" :name="`tests[${i}][hidden]`" value="0">
                                <input type="checkbox" :name="`tests[${i}][hidden]`" value="1" x-model="t.hidden" class="rounded border-gray-300"> Hidden
                            </label>
                            <button type="button" @click="tests.length > 1 && tests.splice(i, 1)" class="text-xs text-red-700 hover:underline sm:col-span-2 sm:text-right" :aria-label="`Remove test ${i + 1}`">Remove</button>
                            <textarea :name="`tests[${i}][input]`" x-model="t.input" rows="3" placeholder="Input (stdin)" class="font-mono sm:col-span-3 {{ $input }}" :aria-label="`Test ${i + 1} input`"></textarea>
                            <textarea :name="`tests[${i}][expected_output]`" x-model="t.expected_output" rows="3" placeholder="Expected output" class="font-mono sm:col-span-3 {{ $input }}" :aria-label="`Test ${i + 1} expected output`"></textarea>
                        </div>
                    </template>
                    <button type="button" @click="tests.push({ name: `Test ${tests.length + 1}`, input: '', expected_output: '', points: 1, hidden: true })" class="mt-2 text-xs text-indigo-700 hover:underline">+ Add test case</button>

                    <p class="mt-4 text-sm font-medium text-gray-700">Code-quality rubric (optional, scored by you)</p>
                    <template x-for="(c, i) in criteria" :key="i">
                        <div class="mt-2 grid gap-2 sm:grid-cols-6">
                            <input :name="`criteria[${i}][label]`" x-model="c.label" required maxlength="150" placeholder="Criterion (e.g. Readable, well-structured code)" class="sm:col-span-2 {{ $input }}" :aria-label="`Criterion ${i + 1} label`">
                            <input :name="`criteria[${i}][description]`" x-model="c.description" maxlength="1000" placeholder="What earns full points (optional)" class="sm:col-span-3 {{ $input }}" :aria-label="`Criterion ${i + 1} description`">
                            <div class="flex gap-2">
                                <input :name="`criteria[${i}][max_points]`" x-model="c.max_points" type="number" step="0.01" min="0.01" required placeholder="Pts" class="{{ $input }}" :aria-label="`Criterion ${i + 1} points`">
                                <button type="button" @click="criteria.splice(i, 1)" class="text-xs text-red-700 hover:underline" :aria-label="`Remove criterion ${i + 1}`">✕</button>
                            </div>
                        </div>
                    </template>
                    <button type="button" @click="criteria.push({ label: '', description: '', max_points: '' })" class="mt-2 text-xs text-indigo-700 hover:underline">+ Add criterion</button>
                    <p class="mt-2 text-xs" :class="balanced() ? 'text-green-700' : 'text-amber-700'">
                        Tests <span x-text="finalPoints()"></span> + rubric <span x-text="rubricTotal()"></span> = <span x-text="finalPoints() + rubricTotal()"></span> of <span x-text="points"></span> points
                        <span x-show="!balanced()">: these must match.</span>
                    </p>
                </div>
            </div>
        </template>

        <div class="flex justify-end">
            <x-primary-button>Add question</x-primary-button>
        </div>
    </form>
</x-panel>
