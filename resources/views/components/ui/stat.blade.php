@props(['label', 'value', 'sub' => null, 'tone' => 'default'])

@php
    // Long amounts step down in size instead of wrapping "Rs" onto its own line.
    $length = mb_strlen(trim(strip_tags((string) $value)));
    $valueSize = match (true) {
        $length <= 12 => 'text-xl sm:text-display-sm font-extrabold',
        $length <= 15 => 'text-lg sm:text-2xl font-extrabold',
        $length <= 19 => 'text-base sm:text-xl font-extrabold',
        default => 'text-base sm:text-lg font-extrabold',
    };
    $navy = $tone === 'brand';
@endphp

<div {{ $attributes->class([
    'min-w-0 rounded-2xl border px-4 py-4 sm:px-5',
    'border-line bg-surface shadow-soft' => ! $navy,
    'border-emphasis bg-emphasis' => $navy,
]) }}>
    <p @class(['text-xs font-semibold uppercase tracking-[0.06em]', 'text-ink-faint' => ! $navy, 'text-emphasis-muted' => $navy])>{{ $label }}</p>
    <p @class(['mt-1.5 whitespace-nowrap tracking-tight tabular-nums', $valueSize, 'text-ink' => ! $navy, 'text-emphasis-ink' => $navy])>{{ $value }}</p>
    @if ($sub)
        <p @class(['mt-1 text-xs', 'text-ink-faint' => ! $navy, 'text-emphasis-muted' => $navy])>{{ $sub }}</p>
    @endif
</div>
