@php $active = request()->routeIs(...$item['active']); @endphp
<a
    href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
    wire:navigate
    data-nav-item
    title="{{ $item['label'] }}"
    x-show="matches(@js($item['label']))"
    @if ($active) aria-current="page" @endif
    @class([
        'group/link flex items-center gap-3 rounded-lg px-2.5 py-1.5 text-sm transition-colors focus-visible:ring-link focus-visible:ring-offset-surface',
        'bg-primary-soft font-semibold text-on-primary-soft' => $active,
        'font-medium text-ink-muted hover:bg-surface-sunken hover:text-ink' => ! $active,
    ])
>
    <svg @class(['h-[18px] w-[18px] shrink-0', 'text-primary' => $active, 'text-ink-faint group-hover/link:text-ink-muted' => ! $active]) fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$item['icon']] }}" />
    </svg>
    <span class="sb-label truncate">{{ $item['label'] }}</span>
</a>
