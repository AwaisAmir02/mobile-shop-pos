@props(['title' => null, 'description' => null])

@php
    $brand = config('branding.name');
    $pageTitle = $title ? $title.' | '.$brand : $brand.' — Shop management software for every shop and online seller in Pakistan';
    $metaDescription = $description ?? 'HisabOKitab runs your whole shop from one screen — kiryana, bakery, mobile shop or online store: sales and invoices, stock with FIFO costing, udhaar and expenses, plus WhatsApp ordering, social posting and Meta ads.';
    $links = [
        ['route' => 'site.features', 'label' => 'Features'],
        ['route' => 'site.pricing', 'label' => 'Pricing'],
        ['route' => 'site.about', 'label' => 'About'],
        ['route' => 'site.contact', 'label' => 'Contact'],
    ];
    $contact = config('site.contact');
    $structuredData = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => $brand,
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'url' => url('/'),
        'description' => $metaDescription,
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $pageTitle }}</title>
        <meta name="description" content="{{ $metaDescription }}">
        <link rel="canonical" href="{{ url()->current() }}">
        <meta name="theme-color" content="#0033a9">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $brand }}">
        <meta property="og:title" content="{{ $pageTitle }}">
        <meta property="og:description" content="{{ $metaDescription }}">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ asset(config('branding.icon_512')) }}">
        <meta name="twitter:card" content="summary">

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <script>document.documentElement.classList.add('js')</script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/site.js'])

        <script type="application/ld+json">{!! $structuredData !!}</script>
    </head>
    <body class="bg-white font-sans text-slate-900 antialiased">
        <a href="#main" class="sr-only z-[60] rounded-lg bg-white px-4 py-2 text-sm font-semibold text-brand-700 shadow focus:not-sr-only focus:fixed focus:left-4 focus:top-4">Skip to content</a>

        <header data-site-header class="sticky top-0 z-50 border-b border-transparent bg-white/85 backdrop-blur-md transition-[border-color,box-shadow] duration-300">
            <nav class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-6 px-4 sm:px-6 lg:h-[4.5rem] lg:px-8" aria-label="Main">
                <a href="{{ route('home') }}" class="shrink-0" aria-label="{{ $brand }} home">
                    <img src="{{ asset(config('branding.logo_horizontal')) }}" srcset="{{ asset(config('branding.logo_horizontal')) }} 1x, {{ asset(config('branding.logo_horizontal_2x')) }} 2x" alt="{{ $brand }}" class="h-8 w-auto sm:h-9" width="220" height="56">
                </a>

                <div class="hidden items-center gap-1 md:flex">
                    @foreach ($links as $link)
                        <a href="{{ route($link['route']) }}" @class([
                            'rounded-full px-4 py-2 text-sm font-medium transition-colors',
                            'bg-brand-50 text-brand-700' => request()->routeIs($link['route']),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs($link['route']),
                        ]) @if (request()->routeIs($link['route'])) aria-current="page" @endif>{{ $link['label'] }}</a>
                    @endforeach
                </div>

                <div class="hidden items-center gap-3 md:flex">
                    @auth
                        <a href="{{ url('/') }}" class="inline-flex items-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">Open dashboard <x-site.icon name="arrow-right" class="h-4 w-4" /></a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-full px-4 py-2 text-sm font-semibold text-slate-700 transition hover:text-brand-700">Log in</a>
                        <a href="{{ route('site.pricing') }}" class="inline-flex items-center gap-2 rounded-full bg-accent-500 px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-accent-500/30 transition hover:-translate-y-0.5 hover:bg-accent-600">Get started <x-site.icon name="arrow-right" class="h-4 w-4" /></a>
                    @endauth
                </div>

                <button type="button" data-menu-toggle class="-mr-2 inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-700 hover:bg-slate-100 md:hidden" aria-expanded="false" aria-controls="site-mobile-menu" aria-label="Open menu">
                    <x-site.icon name="menu" data-icon-open />
                    <x-site.icon name="close" class="hidden h-6 w-6" data-icon-close />
                </button>
            </nav>

            <div id="site-mobile-menu" data-menu class="hidden border-t border-slate-100 bg-white px-4 pb-6 pt-2 md:hidden">
                @foreach ($links as $link)
                    <a href="{{ route($link['route']) }}" class="block rounded-lg px-3 py-3 text-base font-medium text-slate-700 hover:bg-slate-50">{{ $link['label'] }}</a>
                @endforeach
                <div class="mt-4 grid grid-cols-1 gap-3">
                    @auth
                        <a href="{{ url('/') }}" class="rounded-full bg-brand-600 px-5 py-3 text-center text-sm font-semibold text-white">Open dashboard</a>
                    @else
                        <a href="{{ route('site.pricing') }}" class="rounded-full bg-accent-500 px-5 py-3 text-center text-sm font-semibold text-white">Get started</a>
                        <a href="{{ route('login') }}" class="rounded-full border border-slate-200 px-5 py-3 text-center text-sm font-semibold text-slate-700">Log in</a>
                    @endauth
                </div>
            </div>
        </header>

        <main id="main" class="overflow-x-clip">
            {{ $slot }}
        </main>

        <footer class="border-t border-slate-200 bg-slate-50">
            <div class="mx-auto grid grid-cols-1 max-w-7xl gap-10 px-4 py-14 sm:px-6 md:grid-cols-2 lg:grid-cols-4 lg:px-8">
                <div class="lg:col-span-2">
                    <img src="{{ asset(config('branding.logo_horizontal')) }}" alt="{{ $brand }}" class="h-9 w-auto" width="220" height="56" loading="lazy">
                    <p class="mt-4 max-w-sm text-sm leading-6 text-slate-600">Shop management software for Pakistani shops and online sellers — from kiryana stores and bakeries to mobile shops and Instagram resellers. {{ config('branding.slogan') }}</p>
                </div>
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Product</h2>
                    <ul class="mt-4 space-y-3 text-sm text-slate-600">
                        <li><a href="{{ route('site.features') }}" class="hover:text-brand-700">Features</a></li>
                        <li><a href="{{ route('site.pricing') }}" class="hover:text-brand-700">Pricing</a></li>
                        <li><a href="{{ route('site.about') }}" class="hover:text-brand-700">About</a></li>
                        @auth
                            <li><a href="{{ url('/') }}" class="hover:text-brand-700">Open dashboard</a></li>
                        @else
                            <li><a href="{{ route('login') }}" class="hover:text-brand-700">Log in</a></li>
                        @endauth
                    </ul>
                </div>
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Get in touch</h2>
                    <ul class="mt-4 space-y-3 text-sm text-slate-600">
                        <li><a href="https://wa.me/{{ $contact['whatsapp'] }}" class="hover:text-brand-700" rel="noopener">WhatsApp us</a></li>
                        <li><a href="mailto:{{ $contact['email'] }}" class="hover:text-brand-700">{{ $contact['email'] }}</a></li>
                        <li>{{ $contact['location'] }}</li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-slate-200">
                <div class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-6 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                    <p>&copy; {{ now()->year }} {{ $brand }}. All rights reserved.</p>
                    <p>Made in Pakistan, for Pakistani shops.</p>
                </div>
            </div>
        </footer>
    </body>
</html>
