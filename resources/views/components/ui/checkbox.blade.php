@props(['label' => null, 'value' => null])

<label class="flex items-center gap-2.5 text-sm text-ink-muted">
    <input type="checkbox" value="{{ $value }}" {{ $attributes->whereStartsWith('wire:') }} class="h-4 w-4 rounded border-line-strong bg-surface text-primary shadow-soft focus:ring-link focus:ring-offset-0">
    {{ $label ?? $slot }}
</label>
