@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
])

@php
    $variants = [
        'primary' => 'border-transparent bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800 disabled:bg-brand-300 focus-visible:ring-brand-500',
        'accent' => 'border-transparent bg-accent-600 text-white hover:bg-accent-700 active:bg-accent-800 disabled:bg-accent-300 focus-visible:ring-accent-500',
        'secondary' => 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50 active:bg-slate-100 disabled:text-slate-400 focus-visible:ring-brand-500',
        'danger' => 'border-transparent bg-red-600 text-white hover:bg-red-700 active:bg-red-800 disabled:bg-red-300 focus-visible:ring-red-500',
        'ghost' => 'border-transparent bg-transparent text-slate-600 hover:bg-slate-100 active:bg-slate-200 focus-visible:ring-brand-500',
    ];

    $sizes = [
        'sm' => 'px-3 py-[5px] text-sm gap-1.5 rounded-md',
        'md' => 'px-3.5 py-2 text-sm gap-2 rounded-md',
        'lg' => 'px-5 py-[9px] text-base gap-2 rounded-lg',
    ];

    $classes = 'inline-flex items-center justify-center border font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-70 '
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
