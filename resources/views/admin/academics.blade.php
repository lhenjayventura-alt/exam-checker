<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Academic setup</h2>
        <p class="text-sm text-gray-500">Programs, terms, courses, sections and teaching assignments.</p>
    </x-slot>

    @php $select = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500'; @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <x-flash />

            <div class="grid gap-6 lg:grid-cols-2">
                <x-panel title="Programs">
                    <form method="POST" action="{{ route('admin.programs.store') }}" class="mb-4 grid gap-3 sm:grid-cols-3">
                        @csrf
                        <x-text-input name="code" placeholder="Code (e.g. BSCpE)" required />
                        <x-text-input name="name" placeholder="Program name" required />
                        <x-primary-button class="justify-center">Add</x-primary-button>
                    </form>
                    @forelse ($programs as $p)
                        <p class="border-b border-gray-100 py-1 text-sm last:border-0"><span class="font-medium">{{ $p->code }}</span> · {{ $p->name }} <span class="text-xs text-gray-500">({{ $p->courses_count }} courses)</span></p>
                    @empty
                        <p class="text-sm text-gray-500">No programs yet.</p>
                    @endforelse
                </x-panel>

                <x-panel title="Academic terms">
                    <form method="POST" action="{{ route('admin.terms.store') }}" class="mb-4 grid gap-3 sm:grid-cols-2">
                        @csrf
                        <x-text-input name="academic_year" placeholder="2026-2027" required />
                        <select name="term" class="{{ $select }} !mt-0" required>
                            @foreach (\App\Models\AcademicTerm::TERMS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_current" value="1" class="rounded border-gray-300"> Current term</label>
                        <x-primary-button class="justify-center">Add</x-primary-button>
                    </form>
                    @forelse ($terms as $t)
                        <p class="border-b border-gray-100 py-1 text-sm last:border-0">{{ $t->label() }} @if ($t->is_current)<span class="ms-1 rounded bg-green-100 px-1.5 text-xs text-green-800">current</span>@endif</p>
                    @empty
                        <p class="text-sm text-gray-500">No terms yet.</p>
                    @endforelse
                </x-panel>
            </div>

            <x-panel title="Courses">
                <form method="POST" action="{{ route('admin.courses.store') }}" class="mb-4 grid gap-3 sm:grid-cols-4">
                    @csrf
                    <select name="program_id" class="{{ $select }} !mt-0" required>
                        <option value="">Program…</option>
                        @foreach ($programs as $p)<option value="{{ $p->id }}">{{ $p->code }}</option>@endforeach
                    </select>
                    <x-text-input name="code" placeholder="Course code" required />
                    <x-text-input name="title" placeholder="Descriptive title" required />
                    <x-primary-button class="justify-center">Add</x-primary-button>
                </form>
                <div class="grid gap-x-6 sm:grid-cols-2">
                    @forelse ($courses as $c)
                        <p class="border-b border-gray-100 py-1 text-sm"><span class="font-medium">{{ $c->code }}</span> · {{ $c->title }} <span class="text-xs text-gray-500">{{ $c->program->code }}</span></p>
                    @empty
                        <p class="text-sm text-gray-500">No courses yet.</p>
                    @endforelse
                </div>
            </x-panel>

            <x-panel title="Sections and teaching assignments">
                <form method="POST" action="{{ route('admin.offerings.store') }}" class="mb-6 grid gap-3 rounded-md bg-gray-50 p-4 sm:grid-cols-4">
                    @csrf
                    <select name="course_id" class="{{ $select }} !mt-0" required>
                        <option value="">Course…</option>
                        @foreach ($courses as $c)<option value="{{ $c->id }}">{{ $c->code }} · {{ $c->title }}</option>@endforeach
                    </select>
                    <select name="academic_term_id" class="{{ $select }} !mt-0" required>
                        <option value="">Term…</option>
                        @foreach ($terms as $t)<option value="{{ $t->id }}" @selected($t->is_current)>{{ $t->label() }}</option>@endforeach
                    </select>
                    <x-text-input name="section" placeholder="Section (e.g. CPE-3A)" required />
                    <x-primary-button class="justify-center">Add section</x-primary-button>
                    <div class="sm:col-span-4">
                        <p class="text-xs font-medium text-gray-700">Assign teacher(s)</p>
                        <div class="mt-1 flex flex-wrap gap-4 text-sm">
                            @forelse ($teachers as $teacher)
                                <label class="inline-flex items-center gap-2"><input type="checkbox" name="teacher_ids[]" value="{{ $teacher->id }}" class="rounded border-gray-300"> {{ $teacher->name }}</label>
                            @empty
                                <span class="text-gray-500">No active users have the Teacher role.</span>
                            @endforelse
                        </div>
                    </div>
                </form>

                @forelse ($offerings as $o)
                    <div x-data="{ edit: false }" class="border-b border-gray-100 py-2 text-sm last:border-0">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>
                                <a href="{{ route('offerings.show', $o) }}" class="font-medium text-indigo-700 hover:underline">{{ $o->course->code }} · {{ $o->section }}</a>
                                <span class="text-xs text-gray-500">{{ $o->term->label() }} · {{ $o->students_count }} students</span>
                                <span class="block text-xs text-gray-600">Teacher(s): {{ $o->teachers->pluck('name')->join(', ') ?: 'none assigned' }}</span>
                            </span>
                            <button type="button" @click="edit = !edit" class="text-xs text-indigo-700 hover:underline">Change teachers</button>
                        </div>
                        <form x-show="edit" x-cloak method="POST" action="{{ route('admin.offerings.teachers', $o) }}" class="mt-2 flex flex-wrap items-center gap-4 rounded bg-gray-50 p-3">
                            @csrf @method('PUT')
                            @foreach ($teachers as $teacher)
                                <label class="inline-flex items-center gap-2"><input type="checkbox" name="teacher_ids[]" value="{{ $teacher->id }}" @checked($o->teachers->contains($teacher)) class="rounded border-gray-300"> {{ $teacher->name }}</label>
                            @endforeach
                            <x-secondary-button type="submit">Save</x-secondary-button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No sections yet.</p>
                @endforelse
            </x-panel>
        </div>
    </div>
</x-app-layout>
