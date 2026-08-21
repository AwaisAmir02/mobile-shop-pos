@props(['label', 'value', 'sub' => null])

<div {{ $attributes->class(['rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-card']) }}>
    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ $label }}</p>
    <p class="text-display-sm text-slate-900">{{ $value }}</p>
    @if ($sub)
        <p class="mt-1 text-xs text-slate-500">{{ $sub }}</p>
    @endif
</div>
