<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' — '.config('app.name') : config('app.name') }}</title>

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- Applied before first paint so a collapsed sidebar doesn't flash open on load. --}}
        <script>
            try { if (localStorage.getItem('hok.sidebar') === 'collapsed') document.documentElement.dataset.sidebar = 'collapsed'; } catch (e) {}
        </script>
    </head>
    <body class="font-sans text-slate-900 antialiased">
        {{-- wire:navigate copies the new page's <html> attributes over the old ones, dropping
             data-sidebar. Livewire re-runs body scripts during the swap (before Alpine and before
             paint), so re-apply it here too. --}}
        <script>
            try { if (localStorage.getItem('hok.sidebar') === 'collapsed') document.documentElement.dataset.sidebar = 'collapsed'; } catch (e) {}
        </script>
        <div
            x-data="{
                sidebarOpen: false,
                collapsed: document.documentElement.dataset.sidebar === 'collapsed',
                toggleCollapsed() {
                    this.collapsed = ! this.collapsed;
                    document.documentElement.dataset.sidebar = this.collapsed ? 'collapsed' : 'expanded';
                    try { localStorage.setItem('hok.sidebar', this.collapsed ? 'collapsed' : 'expanded'); } catch (e) {}
                },
            }"
            class="flex min-h-screen bg-slate-50"
        >
            <x-layout.sidebar />

            <div class="sb-main flex min-w-0 flex-1 flex-col lg:pl-64">
                <header class="app-header sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
                    <div class="flex min-h-14 items-center gap-3 px-4 sm:px-6 lg:px-8">
                        <button
                            x-on:click="sidebarOpen = true"
                            type="button"
                            aria-label="Open menu"
                            class="-ml-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 lg:hidden"
                        >
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                            </svg>
                        </button>

                        <div class="min-w-0 flex-1 py-2">
                            {{ $header ?? '' }}
                        </div>

                        <livewire:layout.navigation />
                    </div>
                </header>

                <x-billing.pending-banner />
                <x-billing.usage-banner />

                <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    {{ $slot }}
                </main>
            </div>
        </div>

        <x-ui.toast-container />
    </body>
</html>
