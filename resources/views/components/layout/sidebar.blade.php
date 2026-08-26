@php
    $isSuperAdmin = auth()->user()?->is_super_admin;

    $navItems = $isSuperAdmin
        ? [
            ['label' => 'Shops', 'route' => 'admin.dashboard', 'icon' => 'building'],
        ]
        : [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
            ['label' => 'Products', 'route' => 'products.index', 'icon' => 'cube'],
            ['label' => 'Sales', 'route' => 'sales.index', 'icon' => 'cart'],
            ['label' => 'Balance Loads', 'route' => 'balance-loads.index', 'icon' => 'signal'],
            ['label' => 'Wallet Loads', 'route' => 'wallet-loads.index', 'icon' => 'wallet'],
            ['label' => 'Expenses', 'route' => 'expenses.index', 'icon' => 'banknote'],
            ['label' => 'Reports', 'route' => 'reports.index', 'icon' => 'chart'],
        ];

    $icons = [
        'home' => 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75',
        'cube' => 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
        'cart' => 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
        'signal' => 'M9.348 14.652a3.75 3.75 0 010-5.304m5.304 0a3.75 3.75 0 010 5.304m-7.425 2.121a6.75 6.75 0 010-9.546m9.546 0a6.75 6.75 0 010 9.546M5.106 18.894c-3.808-3.807-3.808-9.98 0-13.788m13.788 0c3.808 3.807 3.808 9.98 0 13.788M12 12h.008v.008H12V12z',
        'banknote' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0018.75 4.5H5.25A2.25 2.25 0 003 6.75v10.5A2.25 2.25 0 005.25 19.5z',
        'wallet' => 'M21 12a2.25 2.25 0 00-2.25-2.25H15a1.5 1.5 0 00-1.5 1.5v1.5a1.5 1.5 0 001.5 1.5h3.75A2.25 2.25 0 0021 12zM21 12v4.5a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 16.5V7.5A2.25 2.25 0 015.25 5.25h11.25A2.25 2.25 0 0118.75 7.5v2.25',
        'chart' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C6.5 20.496 5.996 21 5.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        'building' => 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
    ];
@endphp

<div
    x-show="sidebarOpen"
    x-cloak
    x-on:click="sidebarOpen = false"
    class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"
></div>

<aside
    class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform duration-200 lg:static lg:z-auto lg:w-64 lg:translate-x-0"
    :class="sidebarOpen && '!translate-x-0'"
>
    <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-slate-100 px-5">
        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-white">
            <x-application-logo class="h-5 w-5" />
        </span>
        <span class="text-base font-semibold text-slate-900">{{ config('app.name') }}</span>
    </div>

    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
        @foreach ($navItems as $item)
            @php $active = request()->routeIs($item['route']); @endphp
            <a
                href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
                wire:navigate
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition',
                    'bg-brand-50 text-brand-700' => $active,
                    'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $active,
                ])
            >
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$item['icon']] }}" />
                </svg>
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>
</aside>
