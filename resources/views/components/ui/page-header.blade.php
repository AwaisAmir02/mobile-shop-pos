@props([
    // Parent screen, shown as a small breadcrumb above the title.
    'back' => null,
    'backLabel' => null,
    'subtitle' => null,
])

{{-- Goes in a screen's header slot. The title is the default slot; actions go in
     the "actions" slot (one primary at most, docs/design-system.md). --}}
{{-- Wraps rather than squeezing the title: on a phone the actions drop below it. --}}
<div {{ $attributes->class(['flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-2']) }}>
    <div class="min-w-0 max-w-full">
        @if ($back)
            <a href="{{ $back }}" wire:navigate data-breadcrumb class="group inline-flex items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-link">
                <svg class="h-3.5 w-3.5 transition-transform group-hover:-translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                </svg>
                {{ $backLabel }}
            </a>
        @endif

        <h1 class="truncate">{{ $slot }}</h1>

        @if ($subtitle)
            <p class="truncate text-sm text-ink-faint">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 items-center gap-2 sm:gap-3">
            {{ $actions }}
        </div>
    @endisset
</div>
