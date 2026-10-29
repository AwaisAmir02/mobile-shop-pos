@php
    $user = auth()->user();
    $isSuperAdmin = $user?->is_super_admin;

    $navItems = $isSuperAdmin
        ? [
            ['label' => 'Shops', 'route' => 'admin.dashboard', 'icon' => 'building', 'active' => ['admin.dashboard', 'admin.shops.*']],
            ['label' => 'Plans', 'route' => 'admin.plans', 'icon' => 'banknote', 'active' => ['admin.plans', 'admin.plans.*']],
            ['label' => 'Add-ons', 'route' => 'admin.addons', 'icon' => 'sparkles', 'active' => ['admin.addons', 'admin.addons.*']],
            ['label' => 'Payments', 'route' => 'admin.payments', 'icon' => 'wallet', 'active' => ['admin.payments', 'admin.payments.*']],
            ['label' => 'Integrations', 'route' => 'admin.integrations', 'icon' => 'link', 'active' => ['admin.integrations', 'admin.integrations.*']],
            ['label' => 'Backups', 'route' => 'admin.backups', 'icon' => 'cog', 'active' => ['admin.backups', 'admin.backups.*']],
        ]
        : collect([
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home', 'group' => null, 'permission' => 'dashboard'],
            ['label' => 'Products', 'route' => 'products.index', 'icon' => 'cube', 'group' => 'stock', 'permission' => 'products'],
            ['label' => 'Sales', 'route' => 'sales.index', 'icon' => 'cart', 'group' => 'sell', 'permission' => 'sales'],
            ['label' => 'Wallet Loads', 'route' => 'wallet-loads.index', 'icon' => 'wallet', 'group' => 'counter', 'permission' => 'wallet-loads'],
            ['label' => 'NADRA Verification', 'route' => 'nadra-verifications.index', 'icon' => 'identification', 'group' => 'counter', 'permission' => 'nadra-verifications'],
            ['label' => 'Expenses', 'route' => 'expenses.index', 'icon' => 'banknote', 'group' => 'money', 'permission' => 'expenses'],
            ['label' => 'SIM Sale', 'route' => 'sim-sales.index', 'icon' => 'sim', 'group' => 'counter', 'permission' => 'sim-sales'],
            ['label' => 'Udhaar', 'route' => 'udhaar.index', 'icon' => 'udhaar', 'group' => 'sell', 'permission' => 'udhaar'],
            ['label' => 'Balance Loads', 'route' => 'balance-loads.index', 'icon' => 'signal', 'group' => 'counter', 'permission' => 'balance-loads'],
            ['label' => 'Bills', 'route' => 'bills.index', 'icon' => 'bolt', 'group' => 'counter', 'permission' => 'bills'],
            ['label' => 'Stock In', 'route' => 'stock-ins.index', 'icon' => 'stock-in', 'group' => 'stock', 'permission' => 'stock-ins'],
            ['label' => 'Vendors', 'route' => 'vendors.index', 'icon' => 'vendor', 'group' => 'stock', 'permission' => 'vendors'],
            ['label' => 'Customers', 'route' => 'customers.index', 'icon' => 'customers', 'group' => 'sell', 'permission' => 'customers'],
            ['label' => 'Repairs', 'route' => 'repairs.index', 'icon' => 'wrench', 'group' => 'sell', 'permission' => 'repairs'],
            ['label' => 'Services', 'route' => 'services.index', 'icon' => 'sparkles', 'group' => 'sell', 'permission' => 'services'],
            ['label' => 'WhatsApp Orders', 'route' => 'whatsapp-orders.index', 'icon' => 'chat', 'group' => 'online', 'permission' => 'whatsapp-orders'],
            ['label' => 'Customer Messages', 'route' => 'whatsapp-messages.index', 'icon' => 'bell', 'group' => 'online', 'permission' => 'whatsapp-messages'],
            ['label' => 'Marketing', 'route' => 'marketing.posts', 'icon' => 'megaphone', 'group' => 'online', 'permission' => ['marketing', 'marketing-analytics', 'marketing-ads']],
            ['label' => 'Reports', 'route' => 'reports.index', 'icon' => 'chart', 'group' => 'money', 'permission' => 'reports'],
            ['label' => 'Party Ledger', 'route' => 'party-ledger.index', 'icon' => 'ledger', 'group' => 'money', 'permission' => 'party-ledger'],
            ['label' => 'Team', 'route' => 'users.index', 'icon' => 'users', 'group' => 'manage', 'permission' => 'users'],
            ['label' => 'Settings', 'route' => 'settings.index', 'icon' => 'cog', 'group' => 'manage', 'permission' => ['settings', 'users']],
        ])->filter(fn ($item) => $user && collect((array) $item['permission'])->contains(fn ($p) => $user->hasAccessTo($p)))
            ->reject(fn ($item) => $item['route'] === 'services.index' && ! $user?->shop?->services_enabled)
            ->reject(fn ($item) => in_array($item['route'], ['whatsapp-orders.index', 'whatsapp-messages.index'], true) && ! $user?->shop?->whatsapp_enabled)
            ->reject(fn ($item) => $item['route'] === 'marketing.posts' && ! ($user?->shop?->whatsapp_enabled || $user?->shop?->social_enabled))
            ->map(fn ($item) => $item['route'] === 'marketing.posts' && $user?->hasAccessTo('marketing-analytics') ? array_merge($item, ['route' => 'marketing.index']) : $item)
            ->map(fn ($item) => $item['route'] === 'marketing.posts' && ! $user?->hasAccessTo('marketing') && $user?->hasAccessTo('marketing-ads') && $user?->shop?->ads_enabled ? array_merge($item, ['route' => 'marketing.ads']) : $item)
            ->reject(fn ($item) => $item['route'] === 'marketing.ads' && ! $user?->shop?->ads_enabled)
            ->all();

    $icons = [
        'home' => 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75',
        'cube' => 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
        'cart' => 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
        'signal' => 'M9.348 14.652a3.75 3.75 0 010-5.304m5.304 0a3.75 3.75 0 010 5.304m-7.425 2.121a6.75 6.75 0 010-9.546m9.546 0a6.75 6.75 0 010 9.546M5.106 18.894c-3.808-3.807-3.808-9.98 0-13.788m13.788 0c3.808 3.807 3.808 9.98 0 13.788M12 12h.008v.008H12V12z',
        'banknote' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0018.75 4.5H5.25A2.25 2.25 0 003 6.75v10.5A2.25 2.25 0 005.25 19.5z',
        'wallet' => 'M21 12a2.25 2.25 0 00-2.25-2.25H15a1.5 1.5 0 00-1.5 1.5v1.5a1.5 1.5 0 001.5 1.5h3.75A2.25 2.25 0 0021 12zM21 12v4.5a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 16.5V7.5A2.25 2.25 0 015.25 5.25h11.25A2.25 2.25 0 0118.75 7.5v2.25',
        'chart' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C6.5 20.496 5.996 21 5.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        'building' => 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
        'users' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
        'customers' => 'M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z',
        'udhaar' => 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182.556-.417 1.28-.659 2.003-.659.725 0 1.45.22 2.003.659l.688.516M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'stock-in' => 'M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3',
        'vendor' => 'M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12',
        'sim' => 'M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5a2.25 2.25 0 002.25-2.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-6 3h9',
        'bolt' => 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z',
        'wrench' => 'M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085Z',
        'sparkles' => 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z',
        'identification' => 'M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z',
        'cog' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z',
        'link' => 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244',
        'chat' => 'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z',
        'bell' => 'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0',
        'megaphone' => 'M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 010 3.46',
        'ledger' => 'M9 12h6m-6 3.75h6M9 8.25h3M6.75 21h10.5a2.25 2.25 0 002.25-2.25V6.621a2.25 2.25 0 00-.659-1.591l-2.621-2.621A2.25 2.25 0 0014.379 1.5H6.75a2.25 2.25 0 00-2.25 2.25v15A2.25 2.25 0 006.75 21z',
        'search' => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z',
    ];

    $groupLabels = [
        'sell' => 'Sell',
        'stock' => 'Stock',
        'counter' => 'Counter services',
        'money' => 'Money',
        'online' => 'Online',
    ];

    // Grouping and highlighting are presentation only: every item has already
    // passed the permission and feature filters above, so a group whose items
    // were all filtered out is simply not rendered.
    $navItems = collect($navItems)->map(fn ($item) => $item + [
        'group' => null,
        'active' => [$item['route'], Str::beforeLast($item['route'], '.').'.*'],
    ]);

    $sections = collect([['key' => 'main', 'label' => null, 'items' => $navItems->whereNull('group')->values()]])
        ->concat(collect($groupLabels)->map(fn ($label, $key) => [
            'key' => $key,
            'label' => $label,
            'items' => $navItems->where('group', $key)->values(),
        ])->values())
        ->filter(fn ($section) => $section['items']->isNotEmpty());

    $pinned = $navItems->where('group', 'manage')->values();
@endphp

<div
    x-show="sidebarOpen"
    x-cloak
    x-on:click="sidebarOpen = false"
    class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"
></div>

<aside
    x-data="{
        query: '',
        labels: @js($navItems->pluck('label')->all()),
        folded: (() => { try { return JSON.parse(localStorage.getItem('hok.sidebar.folded')) || {} } catch (e) { return {} } })(),
        init() {
            // Every page load (wire:navigate included) re-renders the sidebar at the top, so put
            // the menu back where the user left it, then make sure the active link is on screen.
            this.$nextTick(() => {
                const nav = this.$refs.nav;
                try { nav.scrollTop = parseInt(localStorage.getItem('hok.sidebar.scroll'), 10) || 0 } catch (e) {}
                this.revealActive();
            });
        },
        saveScroll() {
            try { localStorage.setItem('hok.sidebar.scroll', String(Math.round(this.$refs.nav.scrollTop))) } catch (e) {}
        },
        revealActive() {
            const nav = this.$refs.nav;
            const link = nav.querySelector('a[aria-current=page]');
            if (! link || link.offsetParent === null) return;
            // Measured by hand rather than scrollIntoView(), which would also scroll the page
            // while the phone drawer sits off-screen.
            const box = nav.getBoundingClientRect();
            const item = link.getBoundingClientRect();
            if (item.top < box.top) nav.scrollTop += item.top - box.top - 12;
            else if (item.bottom > box.bottom) nav.scrollTop += item.bottom - box.bottom + 12;
        },
        toggleGroup(key) {
            this.folded[key] = ! this.folded[key];
            try { localStorage.setItem('hok.sidebar.folded', JSON.stringify(this.folded)) } catch (e) {}
        },
        matches(label) {
            return label.toLowerCase().includes(this.query.trim().toLowerCase());
        },
        get noMatches() {
            return this.query.trim() !== '' && ! this.labels.some((label) => this.matches(label));
        },
        openFirstMatch() {
            [...this.$root.querySelectorAll('a[data-nav-item]')].find((a) => a.offsetParent !== null)?.click();
        },
        jump() {
            if (window.innerWidth < 1024) {
                this.sidebarOpen = true;
            } else if (this.collapsed) {
                this.toggleCollapsed();
            }
            this.$nextTick(() => this.$refs.jump.focus());
        },
    }"
    x-on:keydown.window.ctrl.k.prevent="jump()"
    x-on:keydown.window.meta.k.prevent="jump()"
    class="sb-aside fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col bg-brand-950 text-brand-100 transition-transform duration-200 lg:w-64 lg:translate-x-0"
    :class="sidebarOpen && '!translate-x-0'"
>
    <div class="sb-brand flex h-14 shrink-0 items-center gap-2.5 px-4">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white p-1">
            <x-application-logo class="h-full w-full object-contain" />
        </span>
        <span class="sb-label truncate text-base font-semibold text-white">
            {{ Str::before(config('app.name'), 'O') }}<span class="text-accent-500">O</span>{{ Str::after(config('app.name'), 'O') }}
        </span>
    </div>

    <div class="sb-label shrink-0 px-3 pb-2">
        <label class="relative block">
            <span class="sr-only">Jump to a screen</span>
            <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['search'] }}" />
            </svg>
            <input
                x-ref="jump"
                x-model="query"
                x-on:keydown.enter.prevent="openFirstMatch()"
                x-on:keydown.escape="query = ''; $el.blur()"
                type="search"
                autocomplete="off"
                placeholder="Jump to…"
                class="block w-full rounded-md border-0 bg-white/10 py-1.5 pl-8 pr-14 text-sm text-white placeholder:text-brand-300 focus:bg-white/15 focus:ring-1 focus:ring-accent-400 [&::-webkit-search-cancel-button]:hidden"
            >
            <kbd class="pointer-events-none absolute right-2 top-1/2 hidden -translate-y-1/2 rounded bg-white/10 px-1.5 py-0.5 font-sans text-[10px] font-medium text-brand-200 lg:block">Ctrl K</kbd>
        </label>
    </div>

    <nav x-ref="nav" x-on:scroll.debounce.100ms="saveScroll()" class="sb-scroll flex-1 space-y-3 overflow-y-auto px-2.5 pb-3 pt-1">
        @foreach ($sections as $section)
            <div x-show="! query || @js($section['items']->pluck('label')->all()).some((label) => matches(label))">
                @if ($section['label'])
                    <button
                        type="button"
                        x-on:click="toggleGroup('{{ $section['key'] }}')"
                        :aria-expanded="(! folded['{{ $section['key'] }}']).toString()"
                        class="sb-label flex w-full items-center justify-between rounded px-2.5 pb-1 text-[11px] font-semibold uppercase tracking-wider text-brand-400 hover:text-brand-200"
                    >
                        {{ $section['label'] }}
                        <svg x-show="! query" class="h-3.5 w-3.5 transition-transform" :class="folded['{{ $section['key'] }}'] && '-rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>
                    <div class="sb-divider mx-2 mb-2 hidden h-px bg-white/10"></div>
                @endif

                <div class="sb-group-items space-y-0.5" x-show="query || ! folded['{{ $section['key'] }}']">
                    @foreach ($section['items'] as $item)
                        @include('components.layout.sidebar-link', ['item' => $item, 'icons' => $icons])
                    @endforeach
                </div>
            </div>
        @endforeach

        <p x-show="noMatches" x-cloak class="px-2.5 py-2 text-sm text-brand-300">No screen matches “<span x-text="query.trim()"></span>”.</p>
    </nav>

    <div class="shrink-0 space-y-0.5 border-t border-white/10 px-2.5 py-2">
        @foreach ($pinned as $item)
            @include('components.layout.sidebar-link', ['item' => $item, 'icons' => $icons])
        @endforeach

        <div class="flex items-center gap-2 px-2.5 pt-1.5">
            @if ($user?->shop)
                <p class="sb-label min-w-0 flex-1 truncate text-xs text-brand-300">{{ $user->shop->name }}</p>
            @else
                <span class="sb-label flex-1"></span>
            @endif
            <button
                type="button"
                x-on:click="toggleCollapsed()"
                :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                class="sb-collapse hidden h-7 w-7 shrink-0 items-center justify-center rounded-md text-brand-300 hover:bg-white/10 hover:text-white lg:flex"
            >
                <svg class="h-4 w-4 transition-transform" :class="collapsed && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5" />
                </svg>
            </button>
        </div>
    </div>
</aside>
