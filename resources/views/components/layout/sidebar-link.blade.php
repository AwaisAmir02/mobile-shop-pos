@php $active = request()->routeIs(...$item['active']); @endphp
<a
    href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
    wire:navigate
    data-nav-item
    title="{{ $item['label'] }}"
    x-show="matches(@js($item['label']))"
    @if ($active) aria-current="page" @endif
    @class([
        'group/link flex items-center gap-3 rounded-lg px-2.5 py-1.5 text-sm transition-colors focus-visible:ring-sidebar-focus focus-visible:ring-offset-sidebar',
        'bg-sidebar-active font-semibold text-sidebar-active-ink' => $active,
        'font-medium text-sidebar-ink hover:bg-sidebar-ink-strong/10 hover:text-sidebar-ink-strong' => ! $active,
    ])
>
    <svg @class(['h-[18px] w-[18px] shrink-0', 'text-sidebar-ink-muted group-hover/link:text-sidebar-ink-strong' => ! $active]) fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$item['icon']] }}" />
    </svg>
    <span class="sb-label truncate">{{ $item['label'] }}</span>
</a>
