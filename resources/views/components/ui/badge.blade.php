@props(['variant' => 'neutral'])

@php
    $variants = [
        'success' => 'bg-emerald-100 text-emerald-800 ring-emerald-600/30',
        'warning' => 'bg-amber-100 text-amber-800 ring-amber-600/30',
        'danger' => 'bg-red-100 text-red-800 ring-red-600/30',
        'neutral' => 'bg-slate-100 text-slate-700 ring-slate-500/20',
        'brand' => 'bg-brand-100 text-brand-800 ring-brand-600/30',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset', $variants[$variant] ?? $variants['neutral']]) }}>
    {{ $slot }}
</span>
