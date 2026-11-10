@php
    $faqs = [
        ['What is HisabOKitab?', 'HisabOKitab is POS, inventory and accounts software made in Pakistan. It records your sales, stock, udhaar, cash and expenses in one place, and adds WhatsApp ordering, Facebook and Instagram posting and Meta ads on the plans that include them.'],
        ['Which businesses is it for?', 'Kiryana and general stores, bakeries, pharmacies, clothing shops, mobile and electronics shops, online sellers, wholesalers and distributors, and businesses with more than one branch. Screens like repairs and loads only appear for the businesses that need them.'],
        ['Do I need to install anything?', 'No. HisabOKitab runs in your browser on a phone, tablet or computer. Log in and start selling.'],
        ['Is it available in Urdu?', 'Every screen has a guide in Roman Urdu and English, and the WhatsApp chatbot replies to customers in Urdu or English, whichever they write in.'],
        ['Can it handle more than one branch?', 'Yes. Branches share one catalog, one stock pool and one customer list, while each branch records its own sales, stock-ins, udhaar and expenses. Owners see every branch side by side.'],
        ['Is my business data private?', 'Yes. Every business’s records are kept separate from every other, and staff only see the screens you allow.'],
    ];

    $plans = \App\Models\Plan::active()->get(['price']);
    $software = [
        '@type' => 'SoftwareApplication',
        'name' => config('branding.name'),
        'url' => url('/'),
        'applicationCategory' => 'BusinessApplication',
        'applicationSubCategory' => 'Point of sale and inventory management',
        'operatingSystem' => 'Web browser (Android, iOS, Windows, macOS)',
        'inLanguage' => ['en', 'ur'],
        'description' => 'POS, inventory and accounts software for shops, online sellers and growing businesses in Pakistan.',
        'publisher' => ['@id' => url('/').'#organization'],
        'featureList' => collect(\App\Support\SiteFeatures::all())->pluck('name')->all(),
    ];
    if ($plans->isNotEmpty()) {
        $software['offers'] = [
            '@type' => 'AggregateOffer',
            'priceCurrency' => 'PKR',
            'lowPrice' => (float) $plans->min('price'),
            'highPrice' => (float) $plans->max('price'),
            'offerCount' => $plans->count(),
            'url' => route('site.pricing'),
        ];
    }
    $schema = [
        $software,
        [
            '@type' => 'FAQPage',
            'mainEntity' => collect($faqs)->map(fn ($faq) => ['@type' => 'Question', 'name' => $faq[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq[1]]])->all(),
        ],
    ];
@endphp

<x-site.layout :schema="$schema">
    <section class="relative overflow-hidden">
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div class="absolute inset-0 bg-[linear-gradient(to_right,#e2e8f0_1px,transparent_1px),linear-gradient(to_bottom,#e2e8f0_1px,transparent_1px)] bg-[size:44px_44px] [mask-image:radial-gradient(ellipse_70%_60%_at_50%_0%,#000_60%,transparent_100%)] opacity-60"></div>
            <div class="site-drift absolute -left-32 -top-24 h-[28rem] w-[28rem] rounded-full bg-brand-400/25 blur-3xl"></div>
            <div class="site-drift absolute -right-24 top-40 h-[24rem] w-[24rem] rounded-full bg-accent-400/20 blur-3xl [animation-delay:-9s]"></div>
        </div>

        <div class="mx-auto grid grid-cols-1 max-w-7xl items-center gap-14 px-4 pb-20 pt-14 sm:px-6 sm:pt-20 lg:grid-cols-12 lg:gap-8 lg:px-8 lg:pb-28 lg:pt-24">
            <div class="lg:col-span-6">
                <p class="inline-flex items-center gap-2 rounded-full border border-brand-100 bg-white/80 px-3 py-1 text-xs font-semibold text-brand-700 shadow-sm" data-reveal>
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-accent-500 text-white"><x-site.icon name="sparkles" class="h-3 w-3" /></span>
                    POS &amp; business software for Pakistan
                </p>
                <h1 class="mt-6 text-4xl font-extrabold leading-[1.08] tracking-tight text-slate-900 sm:text-5xl lg:text-6xl" data-reveal style="--reveal-delay: 80ms">
                    Sales, stock, cash and WhatsApp orders —
                    <span class="bg-gradient-to-r from-brand-600 via-brand-500 to-accent-500 bg-clip-text text-transparent">your whole business on one screen.</span>
                </h1>
                <p class="mt-6 max-w-xl text-lg leading-8 text-slate-600" data-reveal style="--reveal-delay: 160ms">
                    From a single counter to a business with branches, suppliers and staff on payroll — HisabOKitab runs your sales, stock, udhaar and cash, then takes orders on WhatsApp, posts to Facebook and Instagram, and runs your Meta ads from the same dashboard. <span class="font-semibold text-slate-800">Hisab ab hua asaan.</span>
                </p>
                <div class="mt-9 flex flex-col gap-3 sm:flex-row" data-reveal style="--reveal-delay: 240ms">
                    <a href="{{ route('site.pricing') }}" class="group inline-flex items-center justify-center gap-2 rounded-full bg-accent-500 px-7 py-3.5 text-base font-semibold text-white shadow-lg shadow-accent-500/30 transition hover:-translate-y-0.5 hover:bg-accent-600">
                        Get started <x-site.icon name="arrow-right" class="h-5 w-5 transition group-hover:translate-x-0.5" />
                    </a>
                    <a href="{{ route('site.features') }}" class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-200 bg-white px-7 py-3.5 text-base font-semibold text-slate-800 shadow-sm transition hover:border-brand-200 hover:text-brand-700">
                        See every feature
                    </a>
                </div>
                <ul class="mt-10 flex flex-wrap gap-x-6 gap-y-3 text-sm text-slate-600" data-reveal style="--reveal-delay: 320ms">
                    @foreach (['Guide on every screen in Roman Urdu & English', 'Works on phone, tablet & PC', 'Each business’s data kept separate'] as $point)
                        <li class="flex items-center gap-2"><span class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-emerald-700"><x-site.icon name="check" class="h-3 w-3" /></span>{{ $point }}</li>
                    @endforeach
                </ul>
            </div>

            <div class="relative lg:col-span-6" data-reveal="zoom" style="--reveal-delay: 200ms">
                <x-site.browser url="hisabokitab.com/dashboard" label="HisabOKitab dashboard showing today's sales, outstanding dues, repairs and a WhatsApp order" class="lg:ml-6">
                    <x-site.mockup.dashboard />
                </x-site.browser>

                <div class="site-float absolute -bottom-8 -left-2 hidden w-64 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl shadow-brand-950/10 sm:block lg:-left-10" aria-hidden="true">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-semibold text-slate-900">Payment reminder sent</p>
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">WhatsApp</span>
                    </div>
                    <p class="mt-1 text-[11px] text-slate-500">Bilal Ahmed · 3 items due</p>
                    <div class="mt-3 flex items-end justify-between">
                        <p class="text-lg font-extrabold tabular-nums text-slate-900">Rs 11,350</p>
                        <p class="text-[10px] text-slate-400">Udhaar · 2 sales</p>
                    </div>
                </div>

                <div class="site-float-slow absolute -right-2 -top-6 hidden rounded-2xl border border-slate-200 bg-white p-3 shadow-xl shadow-brand-950/10 md:block lg:-right-6" aria-hidden="true">
                    <div class="flex items-center gap-3">
                        <span class="relative flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500 text-white">
                            <x-site.icon name="chat" class="h-5 w-5" />
                            <span class="absolute -right-0.5 -top-0.5 flex h-3 w-3"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-accent-400 opacity-75"></span><span class="relative inline-flex h-3 w-3 rounded-full bg-accent-500"></span></span>
                        </span>
                        <div>
                            <p class="text-xs font-semibold text-slate-900">New WhatsApp order</p>
                            <p class="text-[11px] text-slate-500">#1042 · Rs 2,400</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="border-y border-slate-200 bg-slate-50/70" aria-label="Modules included">
        <div class="relative overflow-hidden py-5 [mask-image:linear-gradient(to_right,transparent,#000_10%,#000_90%,transparent)]">
            <div class="site-marquee flex w-max gap-3">
                @php($modules = ['Sales & invoices', 'Barcode scanning', 'Stock In', 'FIFO batch costing', 'Expiry tracking', 'Variants', 'Purchase orders', 'Vendor invoices', 'Vendors', 'Udhaar ledger', 'Cash & balances', 'Expenses', 'Payroll', 'Party ledger', 'Gross profit', 'Reports', 'Multi-branch', 'WhatsApp orders', 'Social posting', 'Meta ads', 'Staff roles', 'Repairs', 'SIM sales', 'Balance loads', 'Wallet loads', 'Bills & NADRA'])
                {{-- Listed twice for the seamless loop; the second copy is hidden from screen readers. --}}
                @foreach (array_merge($modules, $modules) as $i => $module)
                    <span class="whitespace-nowrap rounded-full border border-slate-200 bg-white px-4 py-1.5 text-sm font-medium text-slate-600" @if ($i >= count($modules)) aria-hidden="true" @endif>{{ $module }}</span>
                @endforeach
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8 lg:py-32">
        <x-site.section-heading eyebrow="Everything in one place" title="Everything your business does in a day, in one place" lead="Not just a till. Sales, stock, purchasing, udhaar, cash, expenses and payroll — kept the way Pakistani businesses actually work, whatever you sell." />

        <div class="mt-16 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['pos', 'cart', 'Sales & invoices', 'Fast checkout with barcode scanning, partial payments and branded PDF invoices with a QR code.'],
                ['stock', 'box', 'Inventory & FIFO costing', 'Stock by batch and vendor, with expiry dates, sizes and colours, and every unit costed from the batch it came from.'],
                ['purchase-orders', 'receipt', 'Purchase orders', 'Order from vendors, receive goods against the order and check each vendor bill before you pay it.'],
                ['udhaar', 'wallet', 'Udhaar ledger', 'Every rupee a customer owes, itemised by where it came from, with WhatsApp reminders.'],
                ['cash-balances', 'calculator', 'Cash & balances', 'Cash in hand and every JazzCash, Easypaisa and bank account, with the day’s money in and out.'],
                ['reports', 'chart', 'Reports & profit', 'Gross profit, sales, dues and expenses by day, month or year, exportable to Excel and PDF.'],
                ['payroll', 'users', 'Payroll', 'Salaries, advances and deductions, monthly payslips — and paid salaries land in your expenses.'],
                ['mobile-shop-services', 'device', 'Counter services', 'Repairs, SIM sales, balance & wallet loads, bills and NADRA — switched on when you need them.'],
            ] as $i => [$slug, $icon, $title, $text])
                <a href="{{ route('site.feature', $slug) }}" class="group rounded-2xl border border-slate-200 bg-white p-6 transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-xl hover:shadow-brand-950/5" data-reveal style="--reveal-delay: {{ ($i % 4) * 80 }}ms">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-white"><x-site.icon :name="$icon" /></span>
                    <h3 class="mt-5 text-base font-semibold text-slate-900 group-hover:text-brand-700">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $text }}</p>
                </a>
            @endforeach
        </div>
    </section>

    <section class="bg-slate-50 py-24 lg:py-28">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <x-site.section-heading eyebrow="Who it’s for" title="Made for the way you sell" lead="One app, set up for your kind of business — from a single counter to several branches." />
            <div class="mt-14 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['store', 'Kiryana & general stores', 'Scan barcodes at the counter, keep udhaar for regular customers, see what’s running low and what you owe each supplier.'],
                    ['heart', 'Bakeries & sweet shops', 'Fast counter sales, orders taken on WhatsApp, udhaar for regular customers and every daily expense in one place.'],
                    ['device', 'Mobile & electronics shops', 'Phones with IMEIs, accessories, SIMs, loads, bills, NADRA and repairs — each with its own screen and fee income.'],
                    ['megaphone', 'Online sellers', 'Selling on Daraz, Instagram, Facebook or WhatsApp? Keep your stock, orders and real profit in one place, and let customers order on WhatsApp.'],
                    ['clock', 'Pharmacies & medical stores', 'Expiry dates on every batch: the stock closest to expiry sells first, and expired stock can’t be sold by mistake.'],
                    ['layers', 'Clothing & garments', 'One product in several sizes and colours, each with its own barcode and price, and fabric sold by the metre or gaz.'],
                    ['receipt', 'Wholesalers & distributors', 'Purchase orders, goods received and vendor invoices checked against each other, with a running ledger for every party.'],
                    ['chart', 'Growing businesses', 'Several branches, staff on payroll and roles for who sees what — with every location’s numbers side by side.'],
                ] as $i => [$icon, $title, $text])
                    <div class="rounded-2xl border border-slate-200 bg-white p-6" data-reveal style="--reveal-delay: {{ $i * 80 }}ms">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-accent-50 text-accent-600"><x-site.icon :name="$icon" /></span>
                        <h3 class="mt-5 text-base font-semibold text-slate-900">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8 lg:py-28">
        <x-site.section-heading eyebrow="Multi-branch" title="One account for every location you run" lead="Open another shop, a warehouse or an office in a new city without starting over. Products, customers, suppliers and stock stay shared — each branch records its own work, and you see every branch side by side." />
        <div class="mt-14 grid grid-cols-1 gap-5 md:grid-cols-3">
            @foreach ([
                ['store', 'Shops with more than one location', 'A second shop across town keeps its own sales, udhaar and expenses, while your products and stock stay shared.'],
                ['box', 'Online sellers with a warehouse', 'Run your warehouse as its own branch: its stock-ins and sales are recorded separately, from the same catalog and stock.'],
                ['chart', 'Companies with several offices', 'Tie staff to their office, compare every branch side by side, and keep one balance per customer across all of them.'],
            ] as $i => [$icon, $title, $text])
                <div class="rounded-2xl border border-slate-200 bg-white p-6" data-reveal style="--reveal-delay: {{ $i * 80 }}ms">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-site.icon :name="$icon" /></span>
                    <h3 class="mt-5 text-base font-semibold text-slate-900">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $text }}</p>
                </div>
            @endforeach
        </div>
        <div class="mt-10 text-center" data-reveal>
            <a href="{{ route('site.feature', 'multi-branch') }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:text-brand-800">See how multi-branch works <x-site.icon name="arrow-right" class="h-4 w-4" /></a>
        </div>
    </section>

    <section class="relative overflow-hidden bg-brand-950 py-24 lg:py-32">
        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
            <div class="site-drift absolute -right-40 top-0 h-[30rem] w-[30rem] rounded-full bg-emerald-500/20 blur-3xl"></div>
            <div class="site-drift absolute -left-40 bottom-0 h-[26rem] w-[26rem] rounded-full bg-brand-500/30 blur-3xl [animation-delay:-7s]"></div>
        </div>

        <div class="relative mx-auto grid grid-cols-1 max-w-7xl items-center gap-16 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
            <div>
                <x-site.section-heading align="left" :dark="true" eyebrow="WhatsApp commerce" title="Sell on WhatsApp while you serve the counter" lead="Your own WhatsApp chatbot shows customers what’s in stock, takes the order, and drops it into HisabOKitab for you to accept — in Urdu or English, whichever they write in." />

                <ul class="mt-10 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    @foreach ([
                        ['chat', 'Chatbot ordering', 'Browse, add to cart and confirm — accepted orders become real sales with stock deducted.'],
                        ['bell', 'Order updates', 'Packed, ready for pickup, out for delivery — customers get a message at each step.'],
                        ['wallet', 'Payment reminders', 'A polite reminder listing exactly what is due and where it came from.'],
                        ['star', 'Restock alerts & reviews', '“Notify me” when an item is back, and a review request after delivery.'],
                    ] as $i => [$icon, $title, $text])
                        <li class="rounded-2xl border border-white/10 bg-white/5 p-5 backdrop-blur" data-reveal style="--reveal-delay: {{ $i * 90 }}ms">
                            <x-site.icon :name="$icon" class="h-6 w-6 text-emerald-300" />
                            <h3 class="mt-3 font-semibold text-white">{{ $title }}</h3>
                            <p class="mt-1.5 text-sm leading-6 text-brand-100/80">{{ $text }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="flex justify-center" data-reveal="right">
                <div class="relative">
                    <div class="absolute -inset-8 rounded-full bg-emerald-400/10 blur-2xl" aria-hidden="true"></div>
                    <x-site.phone label="WhatsApp chat where a customer orders from the shop's chatbot" class="relative">
                        <x-site.mockup.chat />
                    </x-site.phone>
                </div>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8 lg:py-32">
        <div class="grid grid-cols-1 items-center gap-16 lg:grid-cols-2">
            <div class="order-2 lg:order-1" data-reveal="left">
                <x-site.browser url="hisabokitab.com/marketing/ads" label="Meta ad campaign showing spend, WhatsApp chats, orders and sales from the ad, running on Facebook, Instagram and WhatsApp Status">
                    <x-site.mockup.ads />
                </x-site.browser>
            </div>
            <div class="order-1 lg:order-2">
                <x-site.section-heading align="left" eyebrow="Social & ads" title="Post, promote and advertise — without leaving your shop software" lead="Turn any product into a ready-made post for your Facebook Page and Instagram, feed or Stories. Then boost it with a Meta ad that brings customers straight into your WhatsApp." />
                <ul class="mt-8 space-y-4">
                    @foreach ([
                        'Facebook & Instagram posts and Stories, scheduled from your product list',
                        'Ads that open a WhatsApp chat — on Facebook, Instagram and WhatsApp Status',
                        'See which ad brought which order, and what every rupee of ad spend returned',
                        'A hard spend cap you confirm before anything goes live',
                        'Share-to-WhatsApp kit, QR posters and happy-customer review posts',
                    ] as $i => $point)
                        <li class="flex gap-3 text-slate-700" data-reveal style="--reveal-delay: {{ $i * 70 }}ms">
                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent-100 text-accent-600"><x-site.icon name="check" class="h-3.5 w-3.5" /></span>
                            {{ $point }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    <section class="bg-gradient-to-b from-slate-50 to-white py-24 lg:py-32">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <x-site.section-heading eyebrow="Numbers you can trust" title="Know your real profit, and exactly who owes you what" lead="Most shops guess their margins and keep udhaar in a register. HisabOKitab does the arithmetic for you — and shows its working." />

            <div class="mt-16 grid grid-cols-1 gap-8 lg:grid-cols-2">
                <div data-reveal="left">
                    <x-site.browser url="hisabokitab.com/sales" label="Sale screen with batch costs and FIFO profit">
                        <x-site.mockup.pos />
                    </x-site.browser>
                    <h3 class="mt-6 text-lg font-semibold text-slate-900">FIFO batch costing on every sale</h3>
                    <p class="mt-2 text-slate-600">Bought the same item at three different prices? Each sale is costed at the true price of the batch it came from, oldest stock first, or closest to expiry first if you track expiry dates, so the profit you see is the profit you made.</p>
                </div>
                <div data-reveal="right">
                    <x-site.browser url="hisabokitab.com/udhaar" label="Customer udhaar ledger listing each due by its source">
                        <x-site.mockup.udhaar />
                    </x-site.browser>
                    <h3 class="mt-6 text-lg font-semibold text-slate-900">Udhaar that explains itself</h3>
                    <p class="mt-2 text-slate-600">Cash udhaar and unpaid sales roll into one total — itemised, so there’s never an argument about what’s owed.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8 lg:py-28">
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['shield', 'Your data stays yours', 'Every business gets its own isolated records, staff, settings and invoice branding — nothing is ever mixed between accounts.'],
                    ['users', 'Staff roles & permissions', 'Decide who can sell, who can edit or cancel, and who can see cost prices — screen by screen.'],
                ['language', 'Help in your language', 'A guide on every screen in Roman Urdu and English, and a WhatsApp assistant that answers customers in the language they write in.'],
                ['chat', 'Support from a real person', 'Raise a support ticket from inside the app, attach a screenshot and follow the reply in the same place.'],
            ] as $i => [$icon, $title, $text])
                <div class="rounded-2xl bg-gradient-to-br from-brand-600 to-brand-800 p-7 text-white shadow-xl shadow-brand-900/20" data-reveal style="--reveal-delay: {{ $i * 100 }}ms">
                    <x-site.icon :name="$icon" class="h-7 w-7 text-accent-300" />
                    <h3 class="mt-4 text-lg font-semibold">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-6 text-brand-100">{{ $text }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-10 flex flex-col items-start gap-4 rounded-2xl border border-dashed border-slate-300 p-6 sm:flex-row sm:items-center sm:justify-between" data-reveal>
            <div>
                <p class="font-semibold text-slate-900">On the roadmap</p>
                <p class="mt-1 text-sm text-slate-600">More ad channels are on the way, managed from the same dashboard.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach (['Google Ads', 'YouTube Ads', 'LinkedIn Ads'] as $channel)
                    <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-sm font-medium text-slate-700">{{ $channel }} <span class="rounded-full bg-accent-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-accent-700">Coming soon</span></span>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-slate-50">
        <div class="mx-auto max-w-3xl px-4 py-24 sm:px-6">
            <x-site.section-heading eyebrow="Questions" title="Frequently asked" />
            <div class="mt-12 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
                @foreach ($faqs as [$question, $answer])
                    <details class="group p-6" data-reveal>
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-slate-900 [&::-webkit-details-marker]:hidden">
                            {{ $question }}
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 transition group-open:rotate-45 group-open:bg-brand-600 group-open:text-white" aria-hidden="true">+</span>
                        </summary>
                        <p class="mt-3 leading-7 text-slate-600">{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
            <p class="mt-8 text-center text-sm text-slate-600" data-reveal>More questions about plans? <a href="{{ route('site.pricing') }}" class="font-semibold text-brand-700 hover:text-brand-800">See pricing</a> or <a href="{{ route('site.contact') }}" class="font-semibold text-brand-700 hover:text-brand-800">talk to us</a>.</p>
        </div>
    </section>

    <div class="pt-24">
        <x-site.cta />
    </div>
</x-site.layout>
