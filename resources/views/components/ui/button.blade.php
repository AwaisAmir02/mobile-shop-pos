@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
])

@php
    // Pills at page level; inside x-ui.table, app.css squares them off (.ui-table .ui-btn).
    $variants = [
        'primary' => 'border-transparent bg-primary text-on-primary shadow-soft hover:bg-primary-hover disabled:bg-primary/50',
        // Orange is kept for New Sale and Complete Sale only (docs/design-system.md).
        'accent' => 'border-transparent bg-sale font-bold text-sale-ink shadow-soft hover:bg-sale-hover disabled:bg-sale/50',
        'secondary' => 'border-line bg-surface text-ink shadow-soft hover:border-line-strong hover:bg-surface-sunken disabled:text-ink-faint',
        'danger' => 'border-transparent bg-danger-solid text-danger-solid-ink shadow-soft hover:bg-danger-solid/90 disabled:bg-danger-solid/50',
        'ghost' => 'border-transparent bg-transparent text-ink-muted hover:bg-surface-sunken hover:text-ink',
    ];

    $sizes = [
        'sm' => 'px-3 py-[5px] text-sm gap-1.5',
        'md' => 'px-4 py-2 text-sm gap-2',
        'lg' => 'px-6 py-[9px] text-base gap-2',
    ];

    $classes = 'ui-btn inline-flex items-center justify-center rounded-full border font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-link focus-visible:ring-offset-2 focus-visible:ring-offset-surface disabled:cursor-not-allowed disabled:opacity-70 '
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
