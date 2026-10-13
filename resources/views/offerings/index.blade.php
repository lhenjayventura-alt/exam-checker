<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Classes</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-flash />
            <x-panel>
                @if ($offerings->isEmpty())
                    <p class="text-sm text-gray-500">No classes are visible to you. Teachers see classes they are assigned to; program chairs see their program's classes.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs uppercase text-gray-500">
                                <tr><th class="py-2 pe-4">Course</th><th class="pe-4">Section</th><th class="pe-4">Term</th><th class="pe-4">Teacher(s)</th><th class="pe-4 text-right">Students</th><th class="text-right">Exams</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($offerings as $offering)
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-2 pe-4">
                                            <a href="{{ route('offerings.show', $offering) }}" class="font-medium text-indigo-700 hover:underline">{{ $offering->course->code }}</a>
                                            <span class="block text-xs text-gray-500">{{ $offering->course->title }}</span>
                                        </td>
                                        <td class="pe-4">{{ $offering->section }}</td>
                                        <td class="pe-4">{{ $offering->term->label() }}</td>
                                        <td class="pe-4">{{ $offering->teachers->pluck('name')->join(', ') ?: '—' }}</td>
                                        <td class="pe-4 text-right">{{ $offering->students_count }}</td>
                                        <td class="text-right">{{ $offering->exams_count }}</td>
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
