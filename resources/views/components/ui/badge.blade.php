@props(['variant' => 'neutral'])

@php
    // Soft fill with matching text, as on the marketing site. 'accent' renders navy:
    // orange is kept for the two sale buttons.
    $variants = [
        'success' => 'bg-success-soft text-success ring-success/20',
        'warning' => 'bg-warning-soft text-warning ring-warning/20',
        'danger' => 'bg-danger-soft text-danger ring-danger/20',
        'neutral' => 'bg-surface-sunken text-ink-muted ring-line',
        'brand' => 'bg-primary-soft text-on-primary-soft ring-primary/15',
        'accent' => 'bg-primary-soft text-on-primary-soft ring-primary/15',
        'info' => 'bg-info-soft text-info ring-info/15',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset', $variants[$variant] ?? $variants['neutral']]) }}>
    {{ $slot }}
</span>
