@props(['text'])

<div x-data="{ open: false }" x-on:click.outside="open = false" class="relative inline-flex">
    <button
        type="button"
        x-on:click="open = ! open"
        x-on:mouseenter="open = true"
        x-on:mouseleave="open = false"
        {{ $attributes }}
        class="flex h-4 w-4 items-center justify-center rounded-full bg-slate-200 text-[10px] font-semibold leading-none text-slate-600 hover:bg-slate-300"
    >
        i
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.duration.150ms
        class="absolute bottom-full left-1/2 z-40 mb-1.5 -translate-x-1/2 whitespace-nowrap rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg"
    >
        {{ $text }}
        <div class="absolute left-1/2 top-full h-1.5 w-1.5 -translate-x-1/2 -translate-y-1/2 rotate-45 bg-slate-900"></div>
    </div>
</div>
