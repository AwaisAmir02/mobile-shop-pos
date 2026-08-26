@props(['label' => null, 'value' => null])

<label class="flex items-center gap-2.5 text-sm text-slate-700">
    <input type="checkbox" value="{{ $value }}" {{ $attributes->whereStartsWith('wire:') }} class="h-4 w-4 rounded border-slate-300 text-brand-600 shadow-sm focus:ring-brand-500">
    {{ $label ?? $slot }}
</label>
