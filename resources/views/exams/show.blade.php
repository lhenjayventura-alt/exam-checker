<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $exam->title }}</h2>
                <p class="text-sm text-gray-500">
                    <a href="{{ route('offerings.show', $exam->offering) }}" class="hover:underline">{{ $exam->offering->course->code }} · Section {{ $exam->offering->section }}</a>
                    · {{ \App\Models\Exam::TYPES[$exam->exam_type] }} · {{ $exam->exam_date?->format('M j, Y') ?? 'No date set' }}
                    · Version {{ $exam->currentVersion->version_number }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-status-badge :status="$exam->status" class="text-sm" />
                <a href="{{ route('exams.results', $exam) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50">Results &amp; analysis</a>
                @if ($canManage && ! $exam->isDraft())
                    <a href="{{ route('exams.scans.index', $exam) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50">Answer sheets &amp; scans</a>
                @endif
                @if ($canManage && $exam->isDraft())
                    <form method="POST" action="{{ route('exams.publish', $exam) }}" onsubmit="return confirm('Publish this exam? Questions, points and the answer key will be locked.')">
                        @csrf
                        <x-primary-button>Publish</x-primary-button>
                    </form>
                @elseif ($canManage && $exam->status === 'published')
                    <form method="POST" action="{{ route('exams.close', $exam) }}" onsubmit="return confirm('Close this exam to new submissions?')">
                        @csrf
                        <x-secondary-button type="submit">Close submissions</x-secondary-button>
                    </form>
                @endif
                @if ($canManage && in_array($exam->status, ['published', 'closed']))
                    <form method="POST" action="{{ route('exams.versions.store', $exam) }}" onsubmit="return confirm('Create a new editable version? The exam stops accepting submissions until you publish the new version. Existing submissions keep version {{ $exam->currentVersion->version_number }}.')">
                        @csrf
                        <x-secondary-button type="submit">New version</x-secondary-button>
                    </form>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <x-flash />

            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <x-stat label="Questions" :value="$questions->count()" />
                <x-stat label="Total points" :value="rtrim(rtrim(number_format($totalPoints, 2), '0'), '.')" />
                <x-stat label="Passing score" :value="rtrim(rtrim(number_format($exam->passing_percentage, 2), '0'), '.').'%'" />
                <x-stat label="Submissions" :value="$submissions->count().' / '.$rosterCount" hint="Submitted / on roster" />
            </div>

            @if ($exam->instructions)
                <x-panel title="Instructions"><p class="whitespace-pre-line text-sm text-gray-700">{{ $exam->instructions }}</p></x-panel>
            @endif

            @if ($canManage && $exam->isDraft())
                <x-panel title="Exam details">
                    <form method="POST" action="{{ route('exams.update', $exam) }}" class="space-y-4">
                        @csrf @method('PATCH')
                        @include('exams.partials.details-fields', ['exam' => $exam])
                        <div class="flex justify-end"><x-secondary-button type="submit">Save details</x-secondary-button></div>
                    </form>
                </x-panel>

                @include('exams.partials.question-form')
            @endif

            <x-panel title="Questions and answer key">
                @if (! $showKey)
                    <p class="mb-3 text-xs text-gray-500">Answer keys are visible only to the teachers assigned to this class.</p>
                @endif
                @forelse ($questions as $q)
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 py-3 last:border-0">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm">
                                <span class="font-semibold">{{ $q->number }}.</span>
                                <span class="text-xs text-gray-500">{{ \App\Models\Question::TYPES[$q->type] }}{{ $q->isMultipleSelect() ? ' (multiple select, '.($q->setting('partial_credit') === 'proportional' ? 'proportional credit' : 'all-or-nothing').')' : '' }} · {{ rtrim(rtrim(number_format($q->points, 2), '0'), '.') }} pt</span>
                            </p>
                            @if ($q->prompt)<p class="mt-1 whitespace-pre-line text-sm text-gray-800">{{ $q->prompt }}</p>@endif
                            @if ($q->type === 'multiple_choice')
                                <ul class="mt-1 grid gap-x-6 text-sm text-gray-700 sm:grid-cols-2">
                                    @foreach ($q->options as $opt)
                                        @php $isKey = $showKey && in_array($opt->label, $q->answerKey?->correct_values ?? [], true); @endphp
                                        <li class="{{ $isKey ? 'font-semibold text-green-700' : '' }}">{{ $opt->label }}. {{ $opt->text ?: '(no text)' }} @if ($isKey)<span aria-label="correct answer">✓</span>@endif</li>
                                    @endforeach
                                </ul>
                            @elseif ($showKey && $q->type === 'true_false')
                                <p class="mt-1 text-sm">Key: <span class="font-semibold text-green-700">{{ ucfirst($q->answerKey?->correct_values[0] ?? '—') }}</span></p>
                            @elseif ($showKey && $q->type === 'identification')
                                <p class="mt-1 text-sm">Accepted:
                                    @foreach ($q->answerKey?->correct_values ?? [] as $accepted)
                                        <span class="me-1 inline-block rounded bg-green-50 px-1.5 font-semibold text-green-700">{{ $accepted }}</span>
                                    @endforeach
                                </p>
                                <p class="text-xs text-gray-500">{{ $q->setting('case_sensitive') ? 'Case-sensitive' : 'Case-insensitive' }}{{ $q->setting('fuzzy') ? ' · near misses flagged for review' : '' }}</p>
                            @elseif ($showKey && $q->type === 'problem_solving')
                                @php
                                    $tol = (float) $q->setting('tolerance', 0);
                                    $units = $q->setting('units', []);
                                @endphp
                                <p class="mt-1 text-sm">Expected: <span class="font-semibold text-green-700">{{ $q->answerKey?->correct_values[0] }} {{ $units[0] ?? '' }}</span>
                                    <span class="text-xs text-gray-500">{{ $tol > 0 ? '± '.$tol.($q->setting('tolerance_type') === 'percent' ? '%' : '') : 'exact' }}{{ $units ? ' · units: '.implode(', ', $units).($q->setting('unit_required') ? ' (required)' : '') : '' }} · final answer {{ $q->answerPoints() }} pt</span>
                                </p>
                                @if ($q->rubricCriteria->isNotEmpty())
                                    <ul class="mt-1 list-disc ps-5 text-xs text-gray-600">
                                        @foreach ($q->rubricCriteria as $c)
                                            <li><span class="font-medium">{{ $c->label }}</span> ({{ $c->max_points }} pt){{ $c->description ? ': '.$c->description : '' }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            @endif
                        </div>
                        @if ($canManage && $exam->isDraft())
                            <form method="POST" action="{{ route('exams.questions.destroy', [$exam, $q]) }}" onsubmit="return confirm('Delete question {{ $q->number }}?')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-700 hover:underline">Delete</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No questions yet.</p>
                @endforelse
            </x-panel>

            <x-panel title="Submissions">
                @if ($canManage)
                    <x-slot name="actions">
                        @if ($exam->acceptsSubmissions())
                            <a href="{{ route('exams.submissions.create', $exam) }}" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">Enter answers</a>
                        @endif
                        @if ($submissions->where('status', 'graded')->isNotEmpty())
                            <form method="POST" action="{{ route('exams.finalize-all', $exam) }}" onsubmit="return confirm('Finalize all graded submissions? Finalized scores can no longer be edited.')">
                                @csrf
                                <x-secondary-button type="submit">Finalize all graded</x-secondary-button>
                            </form>
                        @endif
                    </x-slot>
                @endif

                @if ($exam->isDraft() && $submissions->isEmpty())
                    <p class="text-sm text-gray-500">Publish the exam to start recording submissions.</p>
                @elseif ($submissions->isEmpty())
                    <p class="text-sm text-gray-500">No submissions yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs uppercase text-gray-500">
                                <tr><th class="py-2 pe-4">Student</th><th class="pe-4">Version</th><th class="pe-4">Source</th><th class="pe-4">Status</th><th class="pe-4 text-right">Open reviews</th><th class="pe-4 text-right">Score</th><th></th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($submissions as $s)
                                    <tr>
                                        <td class="py-2 pe-4">{{ $s->student->fullName() }} <span class="block font-mono text-xs text-gray-500">{{ $s->student->student_number }}</span></td>
                                        <td class="pe-4">v{{ $s->version->version_number }}</td>
                                        <td class="pe-4">{{ ucfirst($s->source) }}</td>
                                        <td class="pe-4">
                                            <x-status-badge :status="$s->status" />
                                            @if ($s->result?->isReleased()) <x-status-badge status="released" /> @endif
                                        </td>
                                        <td class="pe-4 text-right">{{ $s->open_reviews ?: '' }}</td>
                                        <td class="pe-4 text-right">{{ $s->result ? $s->result->total_score.' / '.$s->result->max_score : '—' }}</td>
                                        <td class="text-right"><a href="{{ route('submissions.show', $s) }}" class="text-indigo-700 hover:underline">{{ $canManage && in_array($s->status, ['needs_review', 'graded']) ? 'Review' : 'View' }}</a></td>
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
