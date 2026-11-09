@php
    $active = request()->routeIs(...$item['active']);
    $badge = (int) ($item['badge'] ?? 0);
    $href = Route::has($item['route']) ? route($item['route']) : '#';
@endphp
<a
    href="{{ $href }}"
    wire:navigate
    data-nav-item
    title="{{ $item['label'] }}"
    x-show="matches(@js($item['label']))"
    @if ($active) aria-current="page" @endif
    @if ($item['captureScreen'] ?? false)
        {{-- Help & Support: carry the screen the user is on (address and title), read
             when the pointer or focus reaches the link so in-page tab changes count. --}}
        x-data="{ captureScreen() { if (location.pathname.startsWith('/support')) return; $el.href = @js($href) + '?' + new URLSearchParams({ from: location.pathname + location.search, screen: document.title }).toString(); } }"
        x-init="captureScreen()"
        x-on:mouseenter="captureScreen()"
        x-on:focus="captureScreen()"
        x-on:touchstart.passive="captureScreen()"
    @endif
    @class([
        'group/link relative flex items-center gap-3 rounded-lg px-2.5 py-1.5 text-sm transition-colors focus-visible:ring-sidebar-focus focus-visible:ring-offset-sidebar',
        'bg-sidebar-active font-semibold text-sidebar-active-ink' => $active,
        'font-medium text-sidebar-ink hover:bg-sidebar-ink-strong/10 hover:text-sidebar-ink-strong' => ! $active,
    ])
>
    <svg @class(['h-[18px] w-[18px] shrink-0', 'text-sidebar-ink-muted group-hover/link:text-sidebar-ink-strong' => ! $active]) fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$item['icon']] }}" />
    </svg>
    <span class="sb-label truncate">{{ $item['label'] }}</span>
    @if ($badge > 0)
        <span class="sb-label ml-auto min-w-[1.25rem] rounded-full bg-danger-solid px-1.5 text-center text-[11px] font-bold leading-5 text-surface" {{ $item['badgeKey'] ?? 'data-badge' }}="{{ $badge }}" aria-label="{{ $badge }} new">{{ $badge }}</span>
        <span class="sb-dot absolute right-2 top-1.5 hidden h-2 w-2 rounded-full bg-danger-solid" aria-hidden="true"></span>
    @endif
</a>
