@props(['status'])

@php
    $styles = [
        'draft' => 'bg-gray-100 text-gray-700',
        'published' => 'bg-blue-100 text-blue-800',
        'closed' => 'bg-slate-200 text-slate-800',
        'archived' => 'bg-gray-200 text-gray-600',
        'pending' => 'bg-gray-100 text-gray-700',
        'processing' => 'bg-indigo-100 text-indigo-800',
        'graded' => 'bg-teal-100 text-teal-800',
        'needs_review' => 'bg-amber-100 text-amber-800',
        'finalized' => 'bg-green-100 text-green-800',
        'released' => 'bg-emerald-600 text-white',
        'failed' => 'bg-red-100 text-red-800',
        'auto' => 'bg-teal-50 text-teal-700',
        'reviewed' => 'bg-green-100 text-green-800',
        'overridden' => 'bg-purple-100 text-purple-800',
        'open' => 'bg-amber-100 text-amber-800',
        'resolved' => 'bg-green-100 text-green-800',
        'completed' => 'bg-green-100 text-green-800',
        'matched' => 'bg-green-100 text-green-800',
        'assigned' => 'bg-green-100 text-green-800',
        'needs_student' => 'bg-amber-100 text-amber-800',
        'duplicate' => 'bg-orange-100 text-orange-800',
        'unreadable' => 'bg-red-100 text-red-800',
        'wrong_exam' => 'bg-red-100 text-red-800',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap '.($styles[$status] ?? 'bg-gray-100 text-gray-700')]) }}>
    {{ str($status)->replace('_', ' ')->title() }}
</span>
