@php
    $oldOptions = old('options', collect(range('A', 'D'))->map(fn ($l) => ['label' => $l, 'text' => ''])->all());
@endphp

<x-panel title="Add a question">
    <form method="POST" action="{{ route('exams.questions.store', $exam) }}" class="space-y-4"
          x-data="{
              type: @js(old('type', 'multiple_choice')),
              multiple: @js((bool) old('multiple_select', false)),
              options: @js(array_values($oldOptions)),
              correct: @js(old('correct', [])),
              addOption() {
                  if (this.options.length >= {{ config('exam_checker.max_options') }}) return;
                  this.options.push({ label: String.fromCharCode(65 + this.options.length), text: '' });
              },
              removeOption() {
                  if (this.options.length <= 2) return;
                  const removed = this.options.pop();
                  this.correct = this.correct.filter(l => l !== removed.label);
              },
          }">
        @csrf
        <div class="grid gap-4 sm:grid-cols-4">
            <div>
                <x-input-label for="q_number" value="Number" />
                <x-text-input id="q_number" name="number" type="number" min="1" class="mt-1 block w-full" :value="old('number')" placeholder="Next" />
            </div>
            <div>
                <x-input-label for="q_type" value="Type" />
                <select id="q_type" name="type" x-model="type" @change="correct = []" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @foreach (\App\Models\Question::SUPPORTED_TYPES as $t)
                        <option value="{{ $t }}">{{ \App\Models\Question::TYPES[$t] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-input-label for="q_points" value="Points" />
                <x-text-input id="q_points" name="points" type="number" step="0.01" min="0.01" class="mt-1 block w-full" :value="old('points', 1)" required />
            </div>
        </div>
        <div>
            <x-input-label for="q_prompt" value="Question text (optional)" />
            <textarea id="q_prompt" name="prompt" rows="2" maxlength="5000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('prompt') }}</textarea>
        </div>

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
                            <input :type="multiple ? 'checkbox' : 'radio'" name="correct[]" :value="opt.label" x-model="correct" class="border-gray-300 text-green-600" :class="multiple ? 'rounded' : ''">
                            <span x-text="opt.label"></span>
                        </label>
                        <input type="text" :name="`options[${i}][text]`" x-model="opt.text" maxlength="1000" placeholder="Option text" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                </template>
                <div class="flex gap-3 text-xs">
                    <button type="button" @click="addOption()" class="text-indigo-700 hover:underline">+ Add option</button>
                    <button type="button" @click="removeOption()" class="text-gray-600 hover:underline">− Remove last option</button>
                </div>
            </div>
        </template>

        <template x-if="type === 'true_false'">
            <div class="flex gap-6 text-sm">
                <span class="font-medium text-gray-700">Correct answer:</span>
                <label class="inline-flex items-center gap-2"><input type="radio" name="correct[]" value="true" x-model="correct" class="text-green-600"> True</label>
                <label class="inline-flex items-center gap-2"><input type="radio" name="correct[]" value="false" x-model="correct" class="text-green-600"> False</label>
            </div>
        </template>

        <div class="flex justify-end">
            <x-primary-button>Add question</x-primary-button>
        </div>
    </form>
</x-panel>
