@props([
    'variant' => 'info',
    'title' => null,
    // Full-width strip under the top bar (no rounded box), e.g. the billing banners.
    'flush' => false,
])

@php
    $variants = [
        'info' => ['box' => 'border-sky-200 bg-sky-50 text-sky-900', 'icon' => 'text-sky-600'],
        'success' => ['box' => 'border-emerald-200 bg-emerald-50 text-emerald-900', 'icon' => 'text-emerald-600'],
        'warning' => ['box' => 'border-amber-200 bg-amber-50 text-amber-900', 'icon' => 'text-amber-600'],
        'danger' => ['box' => 'border-red-200 bg-red-50 text-red-800', 'icon' => 'text-red-600'],
    ];
    $tone = $variants[$variant] ?? $variants['info'];

    $icons = [
        'info' => 'm11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z',
        'success' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'warning' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
        'danger' => 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z',
    ];
@endphp

<div {{ $attributes->class([
    'flex gap-3 text-sm',
    $tone['box'],
    'border-b px-4 py-2.5 sm:px-6 lg:px-8' => $flush,
    'rounded-lg border p-3.5' => ! $flush,
]) }}>
    <svg class="mt-px h-5 w-5 shrink-0 {{ $tone['icon'] }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$variant] ?? $icons['info'] }}" />
    </svg>

    <div class="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            @if ($title)
                <p class="font-semibold">{{ $title }}</p>
            @endif
            {{ $slot }}
        </div>

        @isset($actions)
            <div class="flex shrink-0 items-center gap-3">
                {{ $actions }}
            </div>
        @endisset
    </div>
</div>
