@props(['label', 'value', 'sub' => null, 'tone' => 'default'])

@php
    // Long amounts step down in size instead of wrapping "Rs" onto its own line.
    $length = mb_strlen(trim(strip_tags((string) $value)));
    $valueSize = match (true) {
        $length <= 12 => 'text-display-sm',
        $length <= 15 => 'text-2xl font-bold',
        $length <= 19 => 'text-xl font-bold',
        default => 'text-lg font-bold',
    };
    $navy = $tone === 'brand';
@endphp

<div {{ $attributes->class([
    'rounded-lg border px-4 py-3.5 shadow-card sm:px-5',
    'border-slate-200 bg-white' => ! $navy,
    'border-brand-900 bg-brand-950' => $navy,
]) }}>
    <p @class(['text-xs font-medium uppercase tracking-wide', 'text-slate-500' => ! $navy, 'text-brand-200' => $navy])>{{ $label }}</p>
    <p @class(['mt-1 whitespace-nowrap tabular-nums', $valueSize, 'text-slate-900' => ! $navy, 'text-white' => $navy])>{{ $value }}</p>
    @if ($sub)
        <p @class(['mt-1 text-xs', 'text-slate-500' => ! $navy, 'text-brand-200' => $navy])>{{ $sub }}</p>
    @endif
</div>
