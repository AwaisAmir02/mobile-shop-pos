@props(['title' => null, 'description' => null, 'schema' => [], 'breadcrumbs' => [], 'noindex' => false])

@php
    $brand = config('branding.name');
    $pageTitle = $title ? $title.' | '.$brand : 'POS & Business Management Software in Pakistan | '.$brand;
    $metaDescription = $description ?? 'POS, inventory and accounts software for shops, online sellers and growing businesses in Pakistan: sales, stock, udhaar, cash, payroll and WhatsApp orders in one app.';
    $links = [
        ['route' => 'site.features', 'label' => 'Features'],
        ['route' => 'site.pricing', 'label' => 'Pricing'],
        ['route' => 'site.about', 'label' => 'About'],
        ['route' => 'site.contact', 'label' => 'Contact'],
    ];
    $siteFeatures = \App\Support\SiteFeatures::all();
    $featuresActive = request()->routeIs('site.features', 'site.feature');
    $currentFeature = request()->route('feature');
    $chevron = '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="h-4 w-4 transition-transform duration-200 group-data-[open]:rotate-180"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>';
    $contact = config('site.contact');
    $canonical = url()->current();
    $ogImage = asset('branding/og-image.png');
    $organizationId = url('/').'#organization';

    $graph = [
        [
            '@type' => 'Organization',
            '@id' => $organizationId,
            'name' => $brand,
            'url' => url('/'),
            'logo' => ['@type' => 'ImageObject', 'url' => asset(config('branding.icon_512')), 'width' => 512, 'height' => 512],
            'slogan' => config('branding.slogan'),
            'email' => $contact['email'],
            'telephone' => $contact['phone'],
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Karachi', 'addressCountry' => 'PK'],
            'areaServed' => ['@type' => 'Country', 'name' => 'Pakistan'],
            'contactPoint' => [[
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'telephone' => $contact['phone'],
                'email' => $contact['email'],
                'areaServed' => 'PK',
                'availableLanguage' => ['English', 'Urdu'],
            ]],
        ],
        [
            '@type' => 'WebSite',
            '@id' => url('/').'#website',
            'name' => $brand,
            'url' => url('/'),
            'inLanguage' => 'en',
            'publisher' => ['@id' => $organizationId],
        ],
    ];

    if ($breadcrumbs !== []) {
        $graph[] = [
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($breadcrumbs)->values()->map(fn ($crumb, $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb[0],
                'item' => $crumb[1],
            ])->all(),
        ];
    }

    $structuredData = json_encode(
        ['@context' => 'https://schema.org', '@graph' => array_merge($graph, $schema)],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
    );
@endphp

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $pageTitle }}</title>
        <meta name="description" content="{{ $metaDescription }}">
        @if ($noindex)
            <meta name="robots" content="noindex, follow">
        @else
            <link rel="canonical" href="{{ $canonical }}">
        @endif
        <meta name="theme-color" content="#0033a9">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $brand }}">
        <meta property="og:locale" content="en_PK">
        <meta property="og:title" content="{{ $pageTitle }}">
        <meta property="og:description" content="{{ $metaDescription }}">
        <meta property="og:url" content="{{ $canonical }}">
        <meta property="og:image" content="{{ $ogImage }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ $brand }}: POS, inventory, udhaar and WhatsApp orders for businesses in Pakistan">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $pageTitle }}">
        <meta name="twitter:description" content="{{ $metaDescription }}">
        <meta name="twitter:image" content="{{ $ogImage }}">

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <script>document.documentElement.classList.add('js')</script>

        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/site.js'])

        <script type="application/ld+json">{!! $structuredData !!}</script>
    </head>
    <body class="bg-white font-sans text-slate-900 antialiased">
        <a href="#main" class="sr-only z-[60] rounded-lg bg-white px-4 py-2 text-sm font-semibold text-brand-700 shadow focus:not-sr-only focus:fixed focus:left-4 focus:top-4">Skip to content</a>

        <header data-site-header class="sticky top-0 z-50 border-b border-transparent bg-white/85 backdrop-blur-md transition-[border-color,box-shadow] duration-300">
            <nav class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-6 px-4 sm:px-6 lg:h-[4.5rem] lg:px-8" aria-label="Main">
                <a href="{{ route('home') }}" class="shrink-0" aria-label="{{ $brand }} home">
                    <img src="{{ asset('branding/logo-site@1x.webp') }}" srcset="{{ asset('branding/logo-site@1x.webp') }} 1x, {{ asset('branding/logo-site@2x.webp') }} 2x" alt="{{ $brand }}" class="h-8 w-auto sm:h-9" width="144" height="38" fetchpriority="high">
                </a>

                <div class="hidden items-center gap-1 md:flex">
                    @foreach ($links as $link)
                        @if ($link['route'] === 'site.features')
                            <div class="group relative" data-dropdown>
                                <div @class([
                                    'flex items-center rounded-full transition-colors',
                                    'bg-brand-50 text-brand-700' => $featuresActive,
                                    'text-slate-600 hover:bg-slate-100 hover:text-slate-900 group-data-[open]:bg-slate-100 group-data-[open]:text-slate-900' => ! $featuresActive,
                                ])>
                                    <a href="{{ route('site.features') }}" class="py-2 pl-4 pr-1 text-sm font-medium" @if (request()->routeIs('site.features')) aria-current="page" @endif>{{ $link['label'] }}</a>
                                    <button type="button" data-dropdown-toggle class="rounded-r-full py-2 pl-1 pr-3" aria-expanded="false" aria-controls="site-features-menu" aria-label="Show all features">{!! $chevron !!}</button>
                                </div>
                                <div id="site-features-menu" class="invisible absolute left-1/2 top-full z-50 w-[40rem] max-w-[calc(100vw-2rem)] -translate-x-1/2 translate-y-1 pt-3 opacity-0 transition duration-150 group-data-[open]:visible group-data-[open]:translate-y-0 group-data-[open]:opacity-100">
                                    <div class="overflow-hidden rounded-2xl bg-white shadow-2xl shadow-brand-950/15 ring-1 ring-slate-900/5">
                                        <div class="grid grid-cols-2 gap-1 p-3">
                                            @foreach ($siteFeatures as $slug => $feature)
                                                <a href="{{ route('site.feature', $slug) }}" @class(['flex gap-3 rounded-xl p-3 transition hover:bg-slate-50', 'bg-brand-50' => $currentFeature === $slug, 'col-span-2' => $loop->last && $loop->odd]) @if ($currentFeature === $slug) aria-current="page" @endif>
                                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><x-site.icon :name="$feature['icon']" class="h-5 w-5" /></span>
                                                    <span class="min-w-0">
                                                        <span class="block text-sm font-semibold text-slate-900">{{ $feature['name'] }}@if (! empty($feature['badge']))<span class="ml-1.5 inline-flex rounded-full bg-accent-100 px-2 py-0.5 align-middle text-[10px] font-semibold uppercase tracking-wide text-accent-700">{{ $feature['badge'] }}</span>@endif</span>
                                                        <span class="mt-0.5 line-clamp-2 block text-xs leading-5 text-slate-500">{{ $feature['summary'] }}</span>
                                                    </span>
                                                </a>
                                            @endforeach
                                        </div>
                                        <div class="border-t border-slate-100 bg-slate-50 px-6 py-3">
                                            <a href="{{ route('site.features') }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:text-brand-800">All features <x-site.icon name="arrow-right" class="h-4 w-4" /></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @else
                            <a href="{{ route($link['route']) }}" @class([
                                'rounded-full px-4 py-2 text-sm font-medium transition-colors',
                                'bg-brand-50 text-brand-700' => request()->routeIs($link['route']),
                                'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs($link['route']),
                            ]) @if (request()->routeIs($link['route'])) aria-current="page" @endif>{{ $link['label'] }}</a>
                        @endif
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
                    @if ($link['route'] === 'site.features')
                        <div class="group" data-submenu>
                            <div class="flex items-center">
                                <a href="{{ route('site.features') }}" class="block flex-1 rounded-lg px-3 py-3 text-base font-medium text-slate-700 hover:bg-slate-50">{{ $link['label'] }}</a>
                                <button type="button" data-submenu-toggle class="rounded-lg p-3 text-slate-500 hover:bg-slate-50" aria-expanded="false" aria-controls="site-mobile-features" aria-label="Show all features">{!! $chevron !!}</button>
                            </div>
                            <div id="site-mobile-features" class="hidden pb-2 pl-3 group-data-[open]:block">
                                @foreach ($siteFeatures as $slug => $feature)
                                    <a href="{{ route('site.feature', $slug) }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">
                                        <x-site.icon :name="$feature['icon']" class="h-5 w-5 text-brand-600" />
                                        <span>{{ $feature['name'] }}@if (! empty($feature['badge']))<span class="ml-1.5 inline-flex rounded-full bg-accent-100 px-2 py-0.5 align-middle text-[10px] font-semibold uppercase tracking-wide text-accent-700">{{ $feature['badge'] }}</span>@endif</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <a href="{{ route($link['route']) }}" class="block rounded-lg px-3 py-3 text-base font-medium text-slate-700 hover:bg-slate-50">{{ $link['label'] }}</a>
                    @endif
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
            <div class="mx-auto grid grid-cols-1 max-w-7xl gap-10 px-4 py-14 sm:px-6 md:grid-cols-2 lg:grid-cols-5 lg:px-8">
                <div class="lg:col-span-2">
                    <img src="{{ asset('branding/logo-site@1x.webp') }}" srcset="{{ asset('branding/logo-site@1x.webp') }} 1x, {{ asset('branding/logo-site@2x.webp') }} 2x" alt="{{ $brand }}" class="h-9 w-auto" width="144" height="38" loading="lazy">
                    <p class="mt-4 max-w-sm text-sm leading-6 text-slate-600">POS, inventory and accounts software for Pakistani shops, online sellers and growing businesses — from kiryana stores, pharmacies and mobile shops to wholesalers and companies with several branches. {{ config('branding.slogan') }}</p>
                </div>
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Features</h2>
                    <ul class="mt-4 space-y-3 text-sm text-slate-600">
                        @foreach (\Illuminate\Support\Arr::only($siteFeatures, ['pos', 'stock', 'purchase-orders', 'udhaar', 'cash-balances', 'reports', 'payroll', 'multi-branch', 'whatsapp-ordering']) as $slug => $feature)
                            <li><a href="{{ route('site.feature', $slug) }}" class="hover:text-brand-700">{{ $feature['name'] }}</a></li>
                        @endforeach
                    </ul>
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
                        @foreach ($contact['whatsapp_numbers'] ?? [] as $number)
                            <li><a href="https://wa.me/{{ preg_replace('/\D/', '', $number) }}" class="hover:text-brand-700" rel="noopener">{{ $number }}</a></li>
                        @endforeach
                        <li><a href="mailto:{{ $contact['email'] }}" class="hover:text-brand-700">{{ $contact['email'] }}</a></li>
                        <li>{{ $contact['location'] }}</li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-slate-200">
                <div class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-6 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                    <p>&copy; {{ now()->year }} {{ $brand }}. All rights reserved.</p>
                    <p>Made in Pakistan, for Pakistani businesses.</p>
                </div>
            </div>
        </footer>
    </body>
</html>
