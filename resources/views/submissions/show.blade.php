<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $submission->student->fullName() }} <span class="font-mono text-base text-gray-500">{{ $submission->student->student_number }}</span></h2>
                <p class="text-sm text-gray-500">
                    <a href="{{ route('exams.show', $submission->exam) }}" class="hover:underline">{{ $submission->exam->title }}</a>
                    · {{ $submission->exam->offering->course->code }} · Version {{ $submission->version->version_number }} · {{ ucfirst($submission->source) }} submission
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-status-badge :status="$submission->status" class="text-sm" />
                @if ($submission->result?->isReleased()) <x-status-badge status="released" class="text-sm" /> @endif
                @if ($canGrade && $submission->status === 'graded')
                    <form method="POST" action="{{ route('submissions.finalize', $submission) }}" onsubmit="return confirm('Finalize this submission? The scores can no longer be edited.')">
                        @csrf
                        <x-primary-button>Finalize</x-primary-button>
                    </form>
                @endif
                @if ($canGrade && in_array($submission->status, ['pending', 'failed']))
                    <form method="POST" action="{{ route('submissions.reprocess', $submission) }}">
                        @csrf
                        <x-secondary-button type="submit">Grade again</x-secondary-button>
                    </form>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <x-flash />

            @if (in_array($submission->status, ['pending', 'processing']))
                <div class="rounded-md border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                    This submission is queued for grading. Refresh in a moment. If it stays pending, check that a queue worker is running (<code>php artisan queue:work</code>).
                </div>
            @elseif ($submission->status === 'failed')
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $submission->failure_reason }}</div>
            @endif

            @php
                $awarded = $results->sum(fn ($r) => $r->awarded_score ?? 0);
                $pending = $results->whereNull('awarded_score')->count();
                $max = $questions->sum('points');
            @endphp
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <x-stat label="{{ $submission->result ? 'Final score' : 'Current score' }}" :value="($submission->result?->total_score ?? $awarded).' / '.$max" :hint="$pending ? $pending.' question(s) still unscored' : null" />
                <x-stat label="Percentage" :value="$submission->result ? $submission->result->percentage.'%' : '—'" hint="{{ $submission->result ? ($submission->result->passed ? 'Passed' : 'Did not pass') : 'Shown after finalization' }}" />
                <x-stat label="Needs review" :value="$results->filter(fn ($r) => $r->openReviewTask)->count()" />
                <x-stat label="Teacher-adjusted" :value="$results->whereIn('status', ['reviewed', 'overridden'])->count()" />
            </div>

            <x-panel title="Per-question grading">
                <div class="space-y-3">
                    @foreach ($questions as $q)
                        @php
                            $answer = $answers->get($q->id);
                            $result = $results->get($q->id);
                            $task = $result?->openReviewTask;
                        @endphp
                        <div x-data="{ open: {{ $task && $canGrade ? 'true' : 'false' }} }" class="rounded-md border {{ $task ? 'border-amber-300 bg-amber-50/40' : 'border-gray-200' }}">
                            <div class="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 text-sm">
                                <span class="w-10 font-semibold">{{ $q->number }}.</span>
                                <span class="w-40 text-xs text-gray-500">{{ \App\Models\Question::TYPES[$q->type] }}</span>
                                <span class="min-w-[9rem]">
                                    Answer: <span class="font-mono font-semibold">{{ $answer?->effectiveValue() ?? '(blank)' }}</span>
                                    @if ($answer?->isCorrected())
                                        <span class="block text-xs text-gray-500">Originally: <span class="font-mono">{{ $answer->normalized_value ?? '(blank)' }}</span> (raw “{{ $answer->raw_value }}”)</span>
                                    @elseif ($answer && $answer->raw_value !== null && $answer->raw_value !== $answer->normalized_value)
                                        <span class="block text-xs text-gray-500">Raw: “{{ $answer->raw_value }}”</span>
                                    @endif
                                    @if ($answer?->confidence !== null)
                                        <span class="block text-xs text-gray-500">Recognition confidence {{ number_format($answer->confidence, 2) }}</span>
                                    @endif
                                </span>
                                @if ($canGrade)
                                    @php
                                        $keyText = match ($q->type) {
                                            'true_false' => $q->answerKey?->correct_values[0] ?? '—',
                                            'identification' => implode(' / ', $q->answerKey?->correct_values ?? []),
                                            'problem_solving' => ($q->answerKey?->correct_values[0] ?? '—').' '.($q->setting('units')[0] ?? ''),
                                            default => implode(',', $q->answerKey?->correct_values ?? []),
                                        };
                                    @endphp
                                    <span class="min-w-[6rem]">Key: <span class="font-mono text-green-700">{{ $keyText }}</span></span>
                                @endif
                                <span class="min-w-[7rem] font-semibold">
                                    {{ $result?->awarded_score !== null ? $result->awarded_score : '—' }} / {{ rtrim(rtrim(number_format($q->points, 2), '0'), '.') }}
                                    @if ($result && $result->proposed_score !== null && $result->awarded_score !== $result->proposed_score)
                                        <span class="block text-xs font-normal text-gray-500">Proposed {{ $result->proposed_score }}</span>
                                    @endif
                                </span>
                                @if ($result) <x-status-badge :status="$result->status" /> @endif
                                <button type="button" @click="open = !open" class="ms-auto text-xs text-indigo-700 hover:underline" x-text="open ? 'Hide details' : 'Details'"></button>
                            </div>

                            <div x-show="open" x-cloak class="space-y-4 border-t border-gray-100 px-4 py-3 text-sm">
                                @if ($task)
                                    <p class="rounded bg-amber-100 px-3 py-2 text-amber-900"><span class="font-semibold">Review needed:</span> {{ $task->reason }}</p>
                                @endif
                                @if ($result && $canGrade)
                                    <p class="text-gray-700"><span class="font-medium">Grading explanation:</span> {{ $result->explanation }} <span class="text-xs text-gray-400">({{ $result->grader }})</span></p>
                                    @if ($result->feedback)<p class="text-gray-700"><span class="font-medium">Feedback:</span> {{ $result->feedback }}</p>@endif
                                    @if ($result->details->isNotEmpty())
                                        <table class="text-xs">
                                            <thead class="text-left text-gray-500"><tr><th class="pe-4">Criterion</th><th class="pe-4 text-right">Score</th><th>Comment</th></tr></thead>
                                            <tbody>
                                                @foreach ($result->details as $d)
                                                    <tr>
                                                        <td class="pe-4">{{ $d->label }}</td>
                                                        <td class="pe-4 text-right">{{ $d->score ?? '—' }} / {{ $d->max_points }}@if ($d->isFinalAnswer() && $d->auto_score !== null && $d->score !== $d->auto_score) <span class="text-gray-400">(auto {{ $d->auto_score }})</span>@endif</td>
                                                        <td>{{ $d->comment }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                        @if ($result->status === 'overridden' && abs($result->details->sum('score') - (float) $result->awarded_score) > 0.001)
                                            <p class="text-xs text-purple-700">The awarded score was overridden and no longer equals the criterion total.</p>
                                        @endif
                                    @endif
                                @endif

                                @if ($canGrade && $result && ! $submission->isFinalized())
                                    <div class="grid gap-4 lg:grid-cols-3">
                                        <form method="POST" action="{{ route('grading-results.update', $result) }}" class="space-y-2 rounded-md bg-gray-50 p-3">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="action" value="correct_answer">
                                            <p class="font-medium text-gray-800">Correct the answer</p>
                                            <p class="text-xs text-gray-500">Re-grades automatically. The original answer is kept.</p>
                                            <input name="answer" maxlength="255" value="{{ $answer?->effectiveValue() }}" class="block w-full rounded-md border-gray-300 font-mono text-sm {{ in_array($q->type, ['multiple_choice', 'true_false']) ? 'uppercase' : '' }}" placeholder="Blank" aria-label="Corrected answer">
                                            <input name="reason" maxlength="1000" class="block w-full rounded-md border-gray-300 text-sm" placeholder="Note (optional)">
                                            <x-secondary-button type="submit">Save correction</x-secondary-button>
                                        </form>

                                        <form method="POST" action="{{ route('grading-results.update', $result) }}" class="space-y-2 rounded-md bg-gray-50 p-3">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="action" value="override">
                                            <p class="font-medium text-gray-800">Override the score</p>
                                            <p class="text-xs text-gray-500">0 to {{ $result->max_points }} points. A reason is required.</p>
                                            <input name="score" type="number" step="0.01" min="0" max="{{ $result->max_points }}" required class="block w-full rounded-md border-gray-300 text-sm" placeholder="Score">
                                            <input name="reason" required maxlength="1000" class="block w-full rounded-md border-gray-300 text-sm" placeholder="Reason (required)">
                                            <input name="feedback" maxlength="2000" class="block w-full rounded-md border-gray-300 text-sm" placeholder="Feedback to student (optional)">
                                            <x-secondary-button type="submit">Save score</x-secondary-button>
                                        </form>

                                        @if ($q->rubricCriteria->isNotEmpty())
                                            @php
                                                $existing = $result->details->keyBy('rubric_criterion_id');
                                                $answerDetail = $result->details->firstWhere('rubric_criterion_id', null);
                                            @endphp
                                            <form method="POST" action="{{ route('grading-results.update', $result) }}" class="space-y-2 rounded-md bg-indigo-50 p-3 lg:col-span-3">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="action" value="score_rubric">
                                                <p class="font-medium text-gray-800">Score the solution rubric</p>
                                                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                    @if ($q->answerPoints() > 0)
                                                        <label class="block text-xs">
                                                            <span class="font-medium">Final answer</span> (0–{{ $q->answerPoints() }}, auto: {{ $answerDetail?->auto_score ?? 'not readable' }})
                                                            <input name="answer_score" type="number" step="0.01" min="0" max="{{ $q->answerPoints() }}" value="{{ $answerDetail?->score }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                                        </label>
                                                    @endif
                                                    @foreach ($q->rubricCriteria as $c)
                                                        <div class="text-xs">
                                                            <label for="crit-{{ $result->id }}-{{ $c->id }}"><span class="font-medium">{{ $c->label }}</span> (0–{{ $c->max_points }})</label>
                                                            @if ($c->description)<span class="block text-gray-500">{{ $c->description }}</span>@endif
                                                            <input id="crit-{{ $result->id }}-{{ $c->id }}" name="criteria[{{ $c->id }}][score]" type="number" step="0.01" min="0" max="{{ $c->max_points }}" required value="{{ $existing->get($c->id)?->score }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                                            <input name="criteria[{{ $c->id }}][comment]" maxlength="1000" value="{{ $existing->get($c->id)?->comment }}" placeholder="Comment (optional)" aria-label="Comment for {{ $c->label }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                                        </div>
                                                    @endforeach
                                                </div>
                                                <input name="reason" maxlength="1000" class="block w-full rounded-md border-gray-300 text-sm" placeholder="Reason (required only if you change the final-answer score)">
                                                <input name="feedback" maxlength="2000" value="{{ $result->feedback }}" class="block w-full rounded-md border-gray-300 text-sm" placeholder="Feedback to student (optional)">
                                                <x-primary-button>Save rubric scores</x-primary-button>
                                            </form>
                                        @elseif ($task && $result->proposed_score !== null)
                                            <form method="POST" action="{{ route('grading-results.update', $result) }}" class="space-y-2 rounded-md bg-gray-50 p-3">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="action" value="confirm">
                                                <p class="font-medium text-gray-800">Accept the proposed score</p>
                                                <p class="text-xs text-gray-500">Award {{ $result->proposed_score }} point(s) as proposed.</p>
                                                <input name="reason" maxlength="1000" class="block w-full rounded-md border-gray-300 text-sm" placeholder="Note (optional)">
                                                <x-secondary-button type="submit">Confirm</x-secondary-button>
                                            </form>
                                        @endif
                                    </div>
                                @endif

                                @if ($canGrade && $result && $result->events->isNotEmpty())
                                    <div>
                                        <p class="font-medium text-gray-800">History</p>
                                        <ul class="mt-1 space-y-1 text-xs text-gray-600">
                                            @foreach ($result->events as $event)
                                                <li>
                                                    {{ $event->created_at?->format('M j, Y g:i A') }} ·
                                                    <span class="font-medium">{{ str($event->event)->replace('_', ' ')->ucfirst() }}</span>
                                                    by {{ $event->user?->name ?? 'system' }}:
                                                    score {{ $event->previous_score ?? '—' }} → {{ $event->new_score ?? '—' }}
                                                    @if ($event->previous_answer !== null || $event->new_answer !== null) · answer {{ $event->previous_answer ?? '(blank)' }} → {{ $event->new_answer ?? '(blank)' }} @endif
                                                    @if ($event->reason) · “{{ $event->reason }}” @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-panel>
        </div>
    </div>
</x-app-layout>
