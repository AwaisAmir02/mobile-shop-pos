@props(['src' => null, 'label' => '', 'color' => null])

<div
    {{ $attributes->class(['flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-surface-sunken text-xs font-semibold text-ink-faint ring-1 ring-line']) }}
    @if ($color && ! $src) style="background-color: {{ $color }}1a; color: {{ $color }};" @endif
>
    @if ($src)
        <img src="{{ $src }}" class="h-full w-full object-cover" alt="">
    @else
        <span>{{ $label !== '' ? mb_strtoupper(mb_substr($label, 0, 1)) : '?' }}</span>
    @endif
</div>
