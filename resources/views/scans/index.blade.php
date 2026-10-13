<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Scanned answer sheets · {{ $exam->title }}</h2>
        <p class="text-sm text-gray-500">
            <a href="{{ route('exams.show', $exam) }}" class="hover:underline">{{ $exam->offering->course->code }} · Section {{ $exam->offering->section }} · Version {{ $exam->currentVersion->version_number }}</a>
        </p>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <x-flash />

            @unless ($scannerReady)
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                    The answer-sheet scanner is not installed on this server, so sheets cannot be printed or read yet. An administrator should follow “Scanner setup” in the README.
                </div>
            @endunless

            <div class="grid gap-6 lg:grid-cols-2">
                <x-panel title="1. Print answer sheets">
                    <p class="text-sm text-gray-600">Sheets match version {{ $exam->currentVersion->version_number }} of this exam. Each page has corner markers and a signed QR code.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="{{ route('exams.answer-sheets', $exam) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50">Blank sheet (PDF)</a>
                        @if ($students->isNotEmpty())
                            <a href="{{ route('exams.answer-sheets', [$exam, 'for' => 'roster']) }}" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">Named sheets for {{ $students->count() }} student(s) (PDF)</a>
                        @endif
                    </div>
                    <p class="mt-2 text-xs text-gray-500">Named sheets are matched to the student automatically. Blank sheets need you to pick the student after scanning.</p>
                </x-panel>

                <x-panel title="2. Upload scans">
                    <form method="POST" action="{{ route('exams.scans.store', $exam) }}" enctype="multipart/form-data" class="space-y-3">
                        @csrf
                        <input name="scan" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required aria-label="Scanned answer sheets" class="block text-sm">
                        <p class="text-xs text-gray-500">A multi-page PDF from a document scanner, or a phone photo (JPEG/PNG) of one page. Up to {{ round($maxKb / 1024) }} MB. Keep all four corner squares in view.</p>
                        <x-primary-button :disabled="! $scannerReady || ! $exam->acceptsSubmissions()">Upload and read</x-primary-button>
                        @unless ($exam->acceptsSubmissions())
                            <p class="text-xs text-amber-700">The exam is not accepting submissions (status: {{ $exam->status }}).</p>
                        @endunless
                    </form>
                </x-panel>
            </div>

            @if ($pending->isNotEmpty())
                <x-panel title="3. Sheets that need you ({{ $pending->count() }})">
                    <div class="space-y-4">
                        @foreach ($pending as $sheet)
                            <div class="flex flex-wrap gap-4 rounded-md border border-amber-200 bg-amber-50/50 p-3">
                                @if (! empty($sheet->page_images))
                                    <a href="{{ route('scan-sheets.page', [$sheet, 1]) }}" target="_blank" rel="noopener" class="shrink-0">
                                        <img src="{{ route('scan-sheets.page', [$sheet, 1]) }}" alt="Scanned page {{ $sheet->first_page }}" loading="lazy" class="h-40 w-auto rounded border border-gray-300 bg-white">
                                    </a>
                                @endif
                                <div class="min-w-0 flex-1 text-sm">
                                    <p><x-status-badge :status="$sheet->status" /> <span class="text-gray-600">Page {{ $sheet->first_page }}{{ $sheet->page_count > 1 ? '–'.($sheet->first_page + $sheet->page_count - 1) : '' }}</span></p>
                                    <p class="mt-1 text-gray-800">{{ $sheet->message }}</p>
                                    @if ($sheet->canBeAssigned() && $students->isNotEmpty())
                                        <form method="POST" action="{{ route('scan-sheets.assign', $sheet) }}" class="mt-2 flex flex-wrap items-center gap-2">
                                            @csrf
                                            <select name="student_id" required aria-label="Student for this sheet" class="rounded-md border-gray-300 py-1.5 text-sm">
                                                <option value="">Select the student…</option>
                                                @foreach ($students as $student)
                                                    <option value="{{ $student->id }}">{{ $student->student_number }} · {{ $student->fullName() }}</option>
                                                @endforeach
                                            </select>
                                            <x-secondary-button type="submit">Assign and grade</x-secondary-button>
                                        </form>
                                    @elseif ($sheet->status === 'duplicate' && $sheet->student)
                                        <p class="mt-1 text-xs text-gray-600">If this sheet belongs to a different student, assign it to them. Otherwise it can be ignored.</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-panel>
            @endif

            <x-panel title="Uploads">
                @forelse ($uploads as $upload)
                    <div class="border-b border-gray-100 py-3 text-sm last:border-0">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>
                                <span class="font-medium">{{ $upload->original_name }}</span>
                                <span class="text-xs text-gray-500">· {{ $upload->created_at->format('M j, Y g:i A') }} · {{ $upload->uploader->name }} · {{ number_format($upload->size / 1024) }} KB{{ $upload->page_count ? ' · '.$upload->page_count.' page(s)' : '' }}</span>
                            </span>
                            <x-status-badge :status="$upload->status" />
                        </div>
                        @if ($upload->error)
                            <p class="mt-1 text-xs text-red-700">{{ $upload->error }}</p>
                        @endif
                        @if (in_array($upload->status, ['pending', 'processing']))
                            <p class="mt-1 text-xs text-gray-500">Waiting for the queue worker (<code>php artisan queue:work</code>).</p>
                        @endif
                        @if ($upload->sheets->isNotEmpty())
                            <ul class="mt-2 grid gap-1 text-xs sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($upload->sheets as $sheet)
                                    <li>
                                        Page {{ $sheet->first_page }}:
                                        @if ($sheet->submission)
                                            <a href="{{ route('submissions.show', $sheet->submission) }}" class="text-indigo-700 hover:underline">{{ $sheet->student?->fullName() }}</a>
                                            <span class="text-gray-500">({{ $sheet->status }})</span>
                                        @else
                                            <span class="text-amber-700">{{ str($sheet->status)->replace('_', ' ') }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No scans uploaded yet.</p>
                @endforelse
            </x-panel>
        </div>
    </div>
</x-app-layout>
