@props([
    'id' => null,
])

<div
    x-data="{
        open: false,
        query: '',
        highlighted: -1,
        options: [],
        value: '',
        syncOptions() {
            this.options = Array.from(this.$refs.native.options).map((o) => ({
                value: o.value,
                label: o.textContent.trim(),
                create: o.value.startsWith('__create__'),
            }));
        },
        syncValue() {
            this.value = this.$refs.native.value;
        },
        get selectedLabel() {
            const option = this.options.find((o) => o.value === this.value);
            return option ? option.label : '';
        },
        get filtered() {
            if (! this.query) return this.options;
            const q = this.query.toLowerCase();
            return this.options.filter((o) => o.create || o.label.toLowerCase().includes(q));
        },
        select(option) {
            this.value = option.value;
            this.$refs.native.value = option.value;
            this.$refs.native.dispatchEvent(new Event('change', { bubbles: true }));
            this.close();
            this.$refs.button.focus();
        },
        toggle() { this.open ? this.close() : this.openList(); },
        openList() {
            this.syncOptions();
            this.syncValue();
            this.open = true;
            this.query = '';
            this.highlighted = this.filtered.findIndex((o) => o.value === this.value);
            this.$nextTick(() => {
                this.$refs.search?.focus();
                this.$refs.panel?.querySelector('[data-highlighted=true]')?.scrollIntoView({ block: 'nearest' });
            });
        },
        close() { this.open = false; this.highlighted = -1; },
        move(step) {
            if (! this.open) { this.openList(); return; }
            const list = this.filtered;
            if (! list.length) return;
            this.highlighted = (this.highlighted + step + list.length) % list.length;
            this.$nextTick(() => this.$refs.panel?.querySelector('[data-highlighted=true]')?.scrollIntoView({ block: 'nearest' }));
        },
        chooseHighlighted() {
            const list = this.filtered;
            if (this.open && this.highlighted >= 0 && this.highlighted < list.length) {
                this.select(list[this.highlighted]);
            }
        },
    }"
    x-init="
        syncOptions();
        $nextTick(() => syncValue());
        new MutationObserver(() => syncOptions()).observe($refs.native, { childList: true });
        $refs.native.addEventListener('change', () => syncValue());
    "
    x-on:click.outside="close()"
    {{ $attributes->whereDoesntStartWith('wire:')->class(['relative']) }}
>
    <select x-ref="native" {{ $attributes->whereStartsWith('wire:') }} tabindex="-1" aria-hidden="true" class="hidden">
        {{ $slot }}
    </select>

    <button
        type="button"
        id="{{ $id }}"
        x-ref="button"
        x-on:click="toggle()"
        x-on:keydown.arrow-down.prevent="move(1)"
        x-on:keydown.arrow-up.prevent="move(-1)"
        x-on:keydown.enter.prevent="chooseHighlighted()"
        x-on:keydown.escape="close()"
        :aria-expanded="open"
        aria-haspopup="listbox"
        class="flex w-full items-center justify-between gap-2 rounded-lg border border-line-strong bg-surface py-2 px-3 text-left text-base text-ink shadow-soft focus:border-link focus:outline-none focus:ring-1 focus:ring-link sm:text-sm"
    >
        <span class="truncate" x-text="selectedLabel"></span>

        <svg class="h-5 w-5 shrink-0 text-ink-faint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
        </svg>
    </button>

    <div
        x-ref="panel"
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute z-20 mt-1 w-full overflow-hidden rounded-xl border border-line bg-surface-raised shadow-float"
        role="listbox"
    >
        <div class="border-b border-line p-2">
            <input
                type="text"
                x-ref="search"
                x-model="query"
                x-on:input="highlighted = filtered.findIndex((o) => !o.create)"
                x-on:keydown.arrow-down.prevent="move(1)"
                x-on:keydown.arrow-up.prevent="move(-1)"
                x-on:keydown.enter.prevent="chooseHighlighted()"
                x-on:keydown.escape="close()"
                placeholder="Search…"
                class="block w-full rounded-lg border-line bg-surface px-2.5 py-1.5 text-sm text-ink focus:border-link focus:ring-link"
            >
        </div>

        <div class="max-h-60 overflow-auto p-1">
            <template x-for="(option, index) in filtered" :key="option.value">
                <button
                    type="button"
                    role="option"
                    :data-highlighted="highlighted === index"
                    x-on:click="select(option)"
                    x-on:mouseenter="highlighted = index"
                    class="flex w-full items-center rounded-lg px-3 py-2 text-left text-sm text-ink"
                    :class="{
                        'bg-primary-soft text-on-primary-soft': highlighted === index && !option.create,
                        'mt-1 border-t border-line pt-2.5 font-semibold text-link': option.create,
                    }"
                >
                    <span class="truncate" x-text="option.label"></span>
                </button>
            </template>

            <template x-if="filtered.length === 0">
                <p class="px-3.5 py-2 text-sm text-ink-faint">No matches.</p>
            </template>
        </div>
    </div>
</div>
