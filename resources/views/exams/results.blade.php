<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Results · {{ $exam->title }}</h2>
                <p class="text-sm text-gray-500">
                    <a href="{{ route('exams.show', $exam) }}" class="hover:underline">{{ $exam->offering->course->code }} · Section {{ $exam->offering->section }} · {{ $exam->offering->term->label() }}</a>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($results->isNotEmpty())
                    <a href="{{ route('exams.results.export', $exam) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50">Export CSV</a>
                @endif
                @if ($canManage && $results->contains(fn ($r) => ! $r->isReleased()))
                    <form method="POST" action="{{ route('exams.release', $exam) }}" onsubmit="return confirm('Release all finalized results? Released results cannot be changed silently.')">
                        @csrf
                        <x-primary-button>Release finalized results</x-primary-button>
                    </form>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <x-flash />

            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">
                Statistics use <strong>finalized</strong> results only (n = {{ $summary['n'] }}).
                @if ($unfinalized > 0)
                    {{ $unfinalized }} submission(s) are not finalized yet and are excluded.
                @endif
                @if ($summary['n'] > 0 && $summary['small_sample'])
                    <span class="block text-amber-700">Small sample (n &lt; {{ \App\Services\ExamAnalyticsService::SMALL_SAMPLE_N }}): treat these figures as indicative, not as definitive measures of exam quality.</span>
                @endif
            </div>

            @if ($summary['n'] === 0)
                <x-panel><p class="text-sm text-gray-500">No finalized results yet. Finalize graded submissions from the exam page.</p></x-panel>
            @else
                <div class="grid grid-cols-2 gap-4 md:grid-cols-4 lg:grid-cols-7">
                    <x-stat label="Examinees" :value="$summary['n']" />
                    <x-stat label="Mean" :value="$summary['mean'].' / '.$summary['max_score']" />
                    <x-stat label="Median" :value="$summary['median']" />
                    <x-stat label="Highest" :value="$summary['highest']" />
                    <x-stat label="Lowest" :value="$summary['lowest']" />
                    <x-stat label="Std. deviation" :value="$summary['std_dev']" hint="Sample SD" />
                    <x-stat label="Passed" :value="$summary['passed'].' ('.$summary['pass_rate'].'%)'" :hint="'At ≥ '.$exam->passing_percentage.'%'" />
                </div>

                <x-panel title="Score distribution (percentage of total points)">
                    @php $peak = max(1, max(array_column($summary['distribution'], 'count'))); @endphp
                    <div class="space-y-1">
                        @foreach ($summary['distribution'] as $bucket)
                            <div class="flex items-center gap-3 text-xs">
                                <span class="w-16 shrink-0 text-right text-gray-600">{{ $bucket['range'] }}</span>
                                <div class="h-4 flex-1 rounded bg-gray-100">
                                    <div class="h-4 rounded bg-indigo-500" style="width: {{ $bucket['count'] / $peak * 100 }}%"></div>
                                </div>
                                <span class="w-8 text-gray-700">{{ $bucket['count'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-panel>

                <x-panel title="Item analysis">
                    <p class="mb-3 text-xs text-gray-500">
                        <strong>Difficulty (p)</strong>: mean of awarded ÷ maximum points; for all-or-nothing items this is the proportion answering correctly (higher = easier).
                        <strong>Discrimination (D)</strong>: upper-lower index, p(top {{ $items['group_size'] ?? '—' }}) − p(bottom {{ $items['group_size'] ?? '—' }}), groups = 27% of examinees ranked by total score.
                        @unless ($items['discrimination_computed'])
                            <span class="text-amber-700">D is not computed with fewer than {{ \App\Services\ExamAnalyticsService::MIN_N_DISCRIMINATION }} examinees.</span>
                        @endunless
                    </p>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs uppercase text-gray-500">
                                <tr><th class="py-2 pe-4">Item</th><th class="pe-4">Type</th><th class="pe-4 text-right">n</th><th class="pe-4 text-right">Difficulty</th><th class="pe-4 text-right">D</th><th class="pe-4">Response distribution</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($items['items'] as $item)
                                    <tr class="align-top">
                                        <td class="py-2 pe-4 font-semibold">{{ $item['number'] }}</td>
                                        <td class="pe-4 text-xs text-gray-600">{{ \App\Models\Question::TYPES[$item['type']] }}<span class="block text-gray-400">{{ $item['difficulty_method'] }}</span></td>
                                        <td class="pe-4 text-right">{{ $item['n'] }}</td>
                                        <td class="pe-4 text-right">{{ $item['difficulty'] ?? '—' }}</td>
                                        <td class="pe-4 text-right">{{ $item['discrimination'] ?? '—' }}</td>
                                        <td class="pe-4">
                                            @if ($item['distractors'])
                                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                                    @foreach ($item['distractors'] as $d)
                                                        <span class="{{ $showKey && $d['is_key'] ? 'font-semibold text-green-700' : 'text-gray-700' }}">{{ $d['label'] }}: {{ $d['count'] }} ({{ $d['percent'] }}%)</span>
                                                    @endforeach
                                                </div>
                                            @else
                                                <span class="text-xs text-gray-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-panel>

                <x-panel title="Class score sheet">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs uppercase text-gray-500">
                                <tr><th class="py-2 pe-4">Student number</th><th class="pe-4">Name</th><th class="pe-4 text-right">Score</th><th class="pe-4 text-right">%</th><th class="pe-4">Remark</th><th class="pe-4">Status</th><th></th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($results as $r)
                                    <tr>
                                        <td class="py-2 pe-4 font-mono">{{ $r->student->student_number }}</td>
                                        <td class="pe-4">{{ $r->student->fullName() }}</td>
                                        <td class="pe-4 text-right">{{ $r->total_score }} / {{ $r->max_score }}</td>
                                        <td class="pe-4 text-right">{{ $r->percentage }}</td>
                                        <td class="pe-4">{{ $r->passed ? 'Passed' : 'Did not pass' }}</td>
                                        <td class="pe-4"><x-status-badge :status="$r->isReleased() ? 'released' : 'finalized'" /></td>
                                        <td class="text-right"><a href="{{ route('submissions.show', $r->submission_id) }}" class="text-indigo-700 hover:underline">View</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-panel>
            @endif
        </div>
    </div>
</x-app-layout>
