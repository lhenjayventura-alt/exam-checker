@props(['label', 'value', 'hint' => null])

<div class="rounded-lg bg-white p-4 shadow-sm">
    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $value ?? '—' }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
</div>
