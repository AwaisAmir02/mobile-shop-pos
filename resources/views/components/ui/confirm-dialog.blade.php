{{-- The one styled confirmation dialog, driven by the Alpine `confirm` store
     (resources/js/app.js, App\Support\ConfirmAction). Rendered once per page by
     the app layout. --}}
<div
    x-data
    x-show="$store.confirm.open"
    x-cloak
    x-on:keydown.escape.window="$store.confirm.open && $store.confirm.settle(false)"
    class="fixed inset-0 z-dialog flex items-end justify-center p-4 sm:items-center"
    role="dialog"
    aria-modal="true"
    aria-labelledby="confirm-dialog-title"
>
    <div
        x-show="$store.confirm.open"
        x-transition.opacity.duration.150ms
        x-on:click="$store.confirm.settle(false)"
        class="absolute inset-0 bg-scrim/60 backdrop-blur-[2px]"
    ></div>

    <div
        x-show="$store.confirm.open"
        x-trap.noscroll="$store.confirm.open"
        x-transition:enter="ease-out duration-150"
        x-transition:enter-start="translate-y-3 opacity-0 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
        class="relative w-full max-w-sm rounded-2xl bg-surface-raised p-6 shadow-float"
    >
        <div class="flex gap-4">
            <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                :class="$store.confirm.danger ? 'bg-danger-soft text-danger' : 'bg-primary-soft text-primary'"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                </svg>
            </span>
            <div class="min-w-0">
                <h2 id="confirm-dialog-title" class="text-base font-bold tracking-tight text-ink" x-text="$store.confirm.title"></h2>
                <p class="mt-1 text-sm text-ink-muted" x-text="$store.confirm.message"></p>
            </div>
        </div>

        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <x-ui.button variant="secondary" x-on:click="$store.confirm.settle(false)">Cancel</x-ui.button>
            <button
                type="button"
                x-on:click="$store.confirm.settle(true)"
                x-text="$store.confirm.confirmLabel"
                data-confirm-accept
                class="ui-btn inline-flex items-center justify-center rounded-full border border-transparent px-4 py-2 text-sm font-semibold shadow-soft transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-link focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
                :class="$store.confirm.danger ? 'bg-danger-solid text-danger-solid-ink hover:bg-danger-solid/90' : 'bg-primary text-on-primary hover:bg-primary-hover'"
            ></button>
        </div>
    </div>
</div>
