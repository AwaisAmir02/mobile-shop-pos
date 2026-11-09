@props([
    // A User. Shows their profile photo, or their initial when there is none.
    'user',
    // Tailwind size classes for the circle and the letter.
    'size' => 'h-8 w-8 text-sm',
    // A photo to show instead, e.g. a preview before saving.
    'src' => null,
])

@php
    $url = $src ?? $user?->avatarUrl();
@endphp

@if ($url)
    <img
        src="{{ $url }}"
        alt=""
        {{ $attributes->class(['shrink-0 rounded-full bg-surface-sunken object-cover ring-1 ring-line', $size]) }}
        data-avatar="photo"
    >
@else
    <span
        {{ $attributes->class(['flex shrink-0 items-center justify-center rounded-full bg-primary-soft font-bold text-on-primary-soft', $size]) }}
        aria-hidden="true"
        data-avatar="initial"
    >{{ $user?->initial() ?? '?' }}</span>
@endif
