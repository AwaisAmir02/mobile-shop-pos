@php $active = request()->routeIs(...$item['active']); @endphp
<a
    href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
    wire:navigate
    data-nav-item
    title="{{ $item['label'] }}"
    x-show="matches(@js($item['label']))"
    @if ($active) aria-current="page" @endif
    @class([
        'group/link flex items-center gap-3 rounded-md px-2.5 py-1.5 text-sm font-medium transition-colors focus-visible:ring-accent-400 focus-visible:ring-offset-brand-950',
        'bg-accent-600 text-white' => $active,
        'text-brand-100 hover:bg-white/10 hover:text-white' => ! $active,
    ])
>
    <svg @class(['h-[18px] w-[18px] shrink-0', 'text-brand-300 group-hover/link:text-white' => ! $active]) fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$item['icon']] }}" />
    </svg>
    <span class="sb-label truncate">{{ $item['label'] }}</span>
</a>
