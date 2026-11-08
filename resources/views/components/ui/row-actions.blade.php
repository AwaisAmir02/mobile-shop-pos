@props([
    'label' => 'More actions',
])

{{-- A row's actions: an optional visible primary action, then a ⋯ menu holding the
     rest (x-ui.menu-item, x-ui.menu-divider before destructive ones).

     The menu teleports to <body> so a scrolling table can't clip it; anchoring,
     focus trap, wire:click and re-render behaviour were proven in the D0 spike
     (docs/design-system.md, "Row menus"). Rows must carry a wire:key. --}}
@php
    $hasMenu = trim($slot->toHtml()) !== '';
@endphp

<div {{ $attributes->class(['flex items-center justify-end gap-1']) }}>
    {{ $primary ?? '' }}

    @if ($hasMenu)
        <div x-data="{ open: false }" x-on:keydown.escape.window="open = false" class="relative">
            <button
                type="button"
                x-ref="trigger"
                x-on:click="open = ! open"
                :aria-expanded="open.toString()"
                aria-haspopup="menu"
                aria-label="{{ $label }}"
                title="{{ $label }}"
                data-row-menu
                class="flex h-8 w-8 items-center justify-center rounded-lg text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-link"
                :class="open && 'bg-surface-sunken text-ink'"
            >
                <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                    <path d="M3 10a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0zm5.5 0a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0zm7-1.5a1.5 1.5 0 100 3 1.5 1.5 0 000-3z" />
                </svg>
            </button>

            <template x-teleport="body">
                <div
                    x-show="open"
                    x-cloak
                    x-anchor.bottom-end.offset.4="$refs.trigger"
                    x-trap="open"
                    x-on:click.outside="open = false"
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    role="menu"
                    aria-label="{{ $label }}"
                    class="z-menu w-56 rounded-xl border border-line bg-surface-raised p-1.5 text-left shadow-float"
                >
                    {{ $slot }}
                </div>
            </template>
        </div>
    @endif
</div>
