@props(['options' => [], 'name', 'id' => null, 'label' => null])

{{--
    A choice between a few values, shown as one pill-shaped control. Each option
    is a radio bound to the same wire:model, so the property and its values are
    exactly what a select would have set.
--}}
<div role="radiogroup" @if ($label) aria-label="{{ $label }}" @endif {{ $attributes->except(['options', 'name', 'id', 'label'])->whereDoesntStartWith('wire:model')->class(['flex gap-1 rounded-full border border-line bg-surface-sunken/60 p-1']) }}>
    @foreach ($options as $value => $text)
        <label class="min-w-0 flex-1 cursor-pointer" data-segment="{{ $value }}">
            <input
                type="radio"
                name="{{ $name }}"
                value="{{ $value }}"
                @if ($loop->first) id="{{ $id ?? $name }}" @endif
                {{ $attributes->whereStartsWith('wire:model') }}
                class="peer sr-only"
            >
            <span class="block truncate rounded-full px-3 py-1.5 text-center text-sm font-semibold text-ink-faint transition-colors hover:text-ink peer-checked:bg-surface peer-checked:text-ink peer-checked:shadow-soft peer-checked:ring-1 peer-checked:ring-line peer-focus-visible:ring-2 peer-focus-visible:ring-link">{{ $text }}</span>
        </label>
    @endforeach
</div>
