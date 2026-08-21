@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
])

@php
    $variants = [
        'primary' => 'bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800 disabled:bg-brand-300',
        'secondary' => 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 active:bg-slate-100 disabled:text-slate-400',
        'danger' => 'bg-red-600 text-white hover:bg-red-700 active:bg-red-800 disabled:bg-red-300',
        'ghost' => 'bg-transparent text-slate-600 hover:bg-slate-100 active:bg-slate-200',
    ];

    $sizes = [
        'sm' => 'px-3 py-1.5 text-sm gap-1.5 rounded-md',
        'md' => 'px-4 py-2.5 text-sm gap-2 rounded-lg',
        'lg' => 'px-5 py-3 text-base gap-2 rounded-lg',
    ];

    $classes = 'inline-flex items-center justify-center font-semibold transition disabled:cursor-not-allowed disabled:opacity-70 '
        . ($variants[$variant] ?? $variants['primary']) . ' '
        . ($sizes[$size] ?? $sizes['md']);
@endphp

@if ($attributes->get('href'))
    <a {{ $attributes->except(['variant', 'size'])->class([$classes]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->except(['variant', 'size', 'type'])->class([$classes]) }}>
        {{ $slot }}
    </button>
@endif
