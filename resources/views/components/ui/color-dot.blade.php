@props(['color'])

@if ($color)
    <span {{ $attributes->class(['inline-block h-3 w-3 shrink-0 rounded-full ring-1 ring-black/10']) }} style="background-color: {{ $color }};"></span>
@endif
