<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">New exam</h2>
        <p class="text-sm text-gray-500">{{ $offering->course->code }} · Section {{ $offering->section }} · {{ $offering->term->label() }}</p>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-flash />
            <x-panel>
                <form method="POST" action="{{ route('exams.store', $offering) }}" class="space-y-4">
                    @csrf
                    @include('exams.partials.details-fields', ['exam' => null])
                    <div class="flex justify-end gap-3">
                        <a href="{{ route('offerings.show', $offering) }}" class="inline-flex items-center px-4 py-2 text-sm text-gray-600 hover:underline">Cancel</a>
                        <x-primary-button>Create exam</x-primary-button>
                    </div>
                </form>
            </x-panel>
        </div>
    </div>
</x-app-layout>
