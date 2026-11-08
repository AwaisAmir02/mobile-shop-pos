@props(['text'])

<div x-data="{ open: false }" x-on:click.outside="open = false" class="relative inline-flex">
    <button
        type="button"
        x-on:click="open = ! open"
        x-on:mouseenter="open = true"
        x-on:mouseleave="open = false"
        {{ $attributes }}
        class="flex h-4 w-4 items-center justify-center rounded-full bg-surface-sunken text-[10px] font-semibold leading-none text-ink-muted ring-1 ring-line hover:bg-line"
    >
        i
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.duration.150ms
        class="absolute bottom-full left-1/2 z-40 mb-1.5 -translate-x-1/2 whitespace-nowrap rounded-lg bg-ink px-2.5 py-1.5 text-xs font-medium text-surface shadow-float"
    >
        {{ $text }}
        <div class="absolute left-1/2 top-full h-1.5 w-1.5 -translate-x-1/2 -translate-y-1/2 rotate-45 bg-ink"></div>
    </div>
</div>
