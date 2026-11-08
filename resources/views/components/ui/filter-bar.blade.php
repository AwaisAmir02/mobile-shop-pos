@props([
    // How many filters are set, shown on the phone toggle so a hidden filter is never a surprise.
    'active' => 0,
])

{{-- A screen's filters in one panel. Fields go in the slot (x-ui.date-range,
     x-ui.field...), export and similar in "actions". On a phone the fields fold
     behind a Filters button; on wider screens they're always shown. Each slot
     renders once; flex order moves the actions between the two layouts. --}}
<div {{ $attributes->class(['mb-5 flex flex-wrap items-center gap-3 rounded-2xl border border-line bg-surface p-3 shadow-soft sm:items-end sm:p-4']) }} x-data="{ open: false }">
    <button
        type="button"
        x-on:click="open = ! open"
        :aria-expanded="open.toString()"
        class="order-1 inline-flex items-center gap-2 rounded-full px-1 py-1 text-sm font-semibold text-ink sm:hidden"
    >
        <svg class="h-4 w-4 text-ink-faint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
        </svg>
        Filters
        @if ($active > 0)
            <span class="rounded-full bg-primary-soft px-2 py-0.5 text-xs font-bold text-on-primary-soft">{{ $active }} on</span>
        @endif
        <svg class="h-4 w-4 text-ink-faint transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
        </svg>
    </button>

    <div class="order-3 hidden w-full flex-wrap items-end gap-3 sm:order-1 sm:flex sm:w-auto sm:flex-1" :class="open && '!flex'">
        {{ $slot }}
    </div>

    @isset($actions)
        <div class="order-2 ml-auto flex items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
