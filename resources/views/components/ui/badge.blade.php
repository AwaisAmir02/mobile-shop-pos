@props(['variant' => 'neutral'])

@php
    $variants = [
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'danger' => 'bg-red-50 text-red-700 ring-red-600/20',
        'neutral' => 'bg-slate-100 text-slate-600 ring-slate-500/10',
        'brand' => 'bg-brand-50 text-brand-700 ring-brand-600/20',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset', $variants[$variant] ?? $variants['neutral']]) }}>
    {{ $slot }}
</span>
