@props([
    'wireModel',
    'options' => [],
    'placeholder' => 'Select…',
    'id' => null,
    'withColor' => false,
])

<div
    x-data="{
        open: false,
        highlighted: -1,
        options: @js($options),
        withColor: @js($withColor),
        get selected() {
            return this.options.find((o) => o.value === $wire.{{ $wireModel }}) ?? null;
        },
        select(option) {
            this.close();

            if (option.special) {
                this.$dispatch('open-modal', option.modal);
                return;
            }

            $wire.{{ $wireModel }} = option.value;
            this.$refs.button.focus();
        },
        toggle() {
            this.open ? this.close() : this.openList();
        },
        openList() {
            this.open = true;
            this.highlighted = this.options.findIndex((o) => o.value === $wire.{{ $wireModel }});
            this.$nextTick(() => this.$refs.panel?.querySelector('[data-highlighted=true]')?.scrollIntoView({ block: 'nearest' }));
        },
        close() {
            this.open = false;
            this.highlighted = -1;
        },
        move(step) {
            if (! this.open) { this.openList(); return; }
            const count = this.options.length;
            if (! count) return;
            this.highlighted = (this.highlighted + step + count) % count;
            this.$nextTick(() => this.$refs.panel?.querySelector('[data-highlighted=true]')?.scrollIntoView({ block: 'nearest' }));
        },
        chooseHighlighted() {
            if (this.open && this.highlighted >= 0) {
                this.select(this.options[this.highlighted]);
            }
        },
    }"
    x-on:click.outside="close()"
    {{ $attributes->class(['relative']) }}
>
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
        :style="withColor && selected?.color ? `background-color: ${selected.color}0d` : ''"
        class="flex w-full items-center gap-2.5 rounded-lg border border-slate-300 bg-white py-2.5 px-3.5 text-left text-base text-slate-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
    >
        <template x-if="selected">
            <span class="flex min-w-0 flex-1 items-center gap-2.5">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-100 ring-1 ring-slate-200">
                    <template x-if="selected.image">
                        <img :src="selected.image" class="h-full w-full object-cover" alt="">
                    </template>
                    <template x-if="!selected.image">
                        <span class="text-xs font-semibold text-slate-500" x-text="selected.label ? selected.label.charAt(0).toUpperCase() : '?'"></span>
                    </template>
                </span>
                <span class="truncate" x-text="selected.label"></span>
                <template x-if="withColor && selected.color">
                    <span class="ml-auto h-3 w-3 shrink-0 rounded-full ring-1 ring-black/10" :style="`background-color: ${selected.color}`"></span>
                </template>
            </span>
        </template>
        <template x-if="!selected">
            <span class="flex-1 truncate text-slate-400">{{ $placeholder }}</span>
        </template>

        <svg class="h-5 w-5 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
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
        class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg focus:outline-none"
        role="listbox"
    >
        <template x-for="(option, index) in options" :key="option.value">
            <button
                type="button"
                role="option"
                :data-highlighted="highlighted === index"
                x-on:click="select(option)"
                x-on:mouseenter="highlighted = index"
                class="flex w-full items-center gap-2.5 px-3.5 py-2 text-left text-sm"
                :class="{
                    'bg-slate-50': highlighted === index && !(withColor && option.color),
                    'mt-1 border-t border-slate-100 pt-2.5 font-medium text-brand-700': option.special,
                }"
                :style="withColor && option.color ? `background-color: ${option.color}${highlighted === index ? '2a' : '14'}` : ''"
            >
                <template x-if="option.special">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                    </span>
                </template>
                <template x-if="!option.special">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-100 ring-1 ring-slate-200">
                        <template x-if="option.image">
                            <img :src="option.image" class="h-full w-full object-cover" alt="">
                        </template>
                        <template x-if="!option.image">
                            <span class="text-xs font-semibold text-slate-500" x-text="option.label ? option.label.charAt(0).toUpperCase() : '?'"></span>
                        </template>
                    </span>
                </template>
                <span class="truncate" x-text="option.label"></span>
                <template x-if="withColor && option.color && !option.special">
                    <span class="ml-auto h-3 w-3 shrink-0 rounded-full ring-1 ring-black/10" :style="`background-color: ${option.color}`"></span>
                </template>
            </button>
        </template>

        <template x-if="options.length === 0">
            <p class="px-3.5 py-2 text-sm text-slate-400">No options available.</p>
        </template>
    </div>
</div>
