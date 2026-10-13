@props(['title' => null])

<section {{ $attributes->merge(['class' => 'bg-white shadow-sm sm:rounded-lg']) }}>
    @if ($title || isset($actions))
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 sm:px-6">
            <h3 class="font-semibold text-gray-800">{{ $title }}</h3>
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    <div class="p-4 sm:p-6">
        {{ $slot }}
    </div>
</section>
