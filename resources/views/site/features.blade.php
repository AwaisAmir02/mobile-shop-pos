@php
    $groups = [
        [
            'id' => 'counter',
            'eyebrow' => 'Counter & sales',
            'title' => 'A checkout that keeps up with a busy counter',
            'lead' => 'Scan, sell, print — and every sale already knows its cost, its customer and what is still owed.',
            'mockup' => 'pos',
            'url' => 'hisabokitab.com/sales',
            'label' => 'Sale screen with cart, batch costs, FIFO profit and payment options',
            'items' => [
                ['cart', 'Barcode-first checkout', 'Scan or search products, adjust price and discount per line or for the whole invoice.'],
                ['receipt', 'Branded invoices', 'PDF invoices with your shop’s name, logo and a QR code, ready to print or send on WhatsApp.'],
                ['wallet', 'Paid, partial or udhaar', 'Take full or part payment and link the balance to the customer automatically.'],
                ['shield', 'Safe edits & cancellations', 'Edit or cancel a sale with a reason; stock and dues correct themselves.'],
            ],
        ],
        [
            'id' => 'stock',
            'eyebrow' => 'Stock & costing',
            'title' => 'Stock you can count on, profit you can believe',
            'lead' => 'Every purchase is a batch with its own cost. Sales draw from the oldest batch first, so margins reflect what you really paid.',
            'mockup' => 'dashboard',
            'url' => 'hisabokitab.com/dashboard',
            'label' => 'Dashboard with revenue chart, items sold and outstanding dues',
            'items' => [
                ['box', 'Stock In by batch', 'Record purchases per vendor with quantities, costs and selling prices.'],
                ['calculator', 'FIFO batch costing', 'Each unit sold is costed from the batch it came from — automatically.'],
                ['users', 'Vendors & party ledger', 'Know what you owe each supplier and what each party owes you.'],
                ['chart', 'Dashboard & reports', 'Sales, services, expenses and dues by day, month or year.'],
            ],
        ],
        [
            'id' => 'udhaar',
            'eyebrow' => 'Customers & udhaar',
            'title' => 'Udhaar without the register',
            'lead' => 'Cash udhaar, unpaid sales and service balances add up to one total per customer — every line traceable to where it came from.',
            'mockup' => 'udhaar',
            'url' => 'hisabokitab.com/udhaar',
            'label' => 'Customer ledger with each due itemised by source',
            'items' => [
                ['wallet', 'One clear balance', 'Udhaar given and repaid, alongside unpaid sales and services.'],
                ['receipt', 'Itemised by source', 'See exactly which sale or service each rupee belongs to.'],
                ['chat', 'WhatsApp reminders', 'Send a polite reminder that lists every due item — no payment links, no surprises.'],
                ['users', 'Customer history', 'Every purchase, repair and payment for a customer on one page.'],
            ],
        ],
    ];

    $services = [
        ['wrench', 'Repairs', 'Intake, status, charges and a “device ready” WhatsApp message.'],
        ['device', 'SIM sales', 'Sell and track SIMs by network with revenue reporting.'],
        ['bolt', 'Balance loads', 'Mobile load sales with your fee recorded as revenue.'],
        ['wallet', 'Wallet loads', 'Cash-in and cash-out with fees tracked in both directions.'],
        ['receipt', 'Bills', 'Utility bill collection by provider, with fee income.'],
        ['id', 'NADRA verification', 'Record verifications and the revenue they bring in.'],
        ['calculator', 'Expenses & accounts', 'Shop expenses and accounts, so your dashboard shows the full picture.'],
        ['sparkles', 'Services', 'Any other service you offer, with its own categories and pricing.'],
    ];

    $whatsapp = [
        ['chat', 'Chatbot ordering', 'Customers browse your listed products, build a cart and confirm — right inside WhatsApp.'],
        ['check', 'Accept or reject', 'Accepted orders become real sales: stock deducted, invoice created, customer linked.'],
        ['bell', 'Order status messages', 'Packed, ready for pickup and out for delivery updates sent automatically.'],
        ['wallet', 'Payment reminders', 'Reminders that list each due item and its source, with a cooldown so no one is spammed.'],
        ['heart', 'Restock “notify me”', 'Out of stock? Customers tap to be told the moment it’s back.'],
        ['star', 'Review requests', 'A review request after delivery, and — with permission — a ready-made post from it.'],
        ['language', 'Urdu & English', 'The bot answers in the language the customer writes in, including Roman Urdu.'],
        ['qr', 'QR codes & share kit', 'Printable QR posters that open your chat or catalogue, and a share-to-WhatsApp kit.'],
    ];

    $marketing = [
        ['photo', 'Facebook & Instagram posts', 'Turn a product or review into a designed post for your Page and Instagram feed.'],
        ['sparkles', 'Stories', 'Publish to Facebook and Instagram Stories in the right format, automatically.'],
        ['clock', 'Scheduling', 'Plan posts ahead and let HisabOKitab publish them on time.'],
        ['megaphone', 'Meta ads from your dashboard', 'Create ads that open a WhatsApp chat, or boost a Facebook post — budget, dates and audience in a few taps.'],
        ['chat', 'WhatsApp Status ads', 'Show your ad in people’s WhatsApp Status as well as on Facebook and Instagram.'],
        ['shield', 'Spend you control', 'Everything is set up paused; you confirm the maximum spend before launch. Pause or stop anytime.'],
        ['chart', 'Results you can trace', 'Spend, chats, orders and sales from each ad, so you know what your ads really returned.'],
        ['eye', 'Marketing overview', 'One page for posts, reach and ad performance across channels.'],
    ];
@endphp

<x-site.layout title="Features" description="Every HisabOKitab feature for shops and online sellers: sales and invoices, FIFO stock costing, udhaar ledger and expenses, WhatsApp ordering, Facebook and Instagram posting and Meta ads — plus repairs, SIM, loads, bills and NADRA for mobile shops.">
    <x-site.page-hero eyebrow="Features" title="Built around how Pakistani shops really work" lead="Kiryana store, bakery, mobile shop or online seller — from the first scan at the counter to the WhatsApp message that brings a customer back, here’s everything HisabOKitab does.">
        <nav class="mt-10 flex flex-wrap justify-center gap-2" aria-label="Feature sections" data-reveal style="--reveal-delay: 240ms">
            @foreach ([['counter', 'Counter'], ['stock', 'Stock'], ['udhaar', 'Udhaar'], ['services', 'Services'], ['whatsapp', 'WhatsApp'], ['marketing', 'Social & ads'], ['control', 'Control']] as [$anchor, $label])
                <a href="#{{ $anchor }}" class="rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-600 shadow-sm transition hover:border-brand-200 hover:text-brand-700">{{ $label }}</a>
            @endforeach
        </nav>
    </x-site.page-hero>

    @foreach ($groups as $index => $group)
        <section id="{{ $group['id'] }}" class="scroll-mt-24 {{ $index % 2 === 1 ? 'bg-slate-50' : '' }}">
            <div class="mx-auto grid grid-cols-1 max-w-7xl items-center gap-14 px-4 py-24 sm:px-6 lg:grid-cols-2 lg:px-8">
                <div @class(['lg:order-2' => $index % 2 === 1])>
                    <x-site.section-heading align="left" :eyebrow="$group['eyebrow']" :title="$group['title']" :lead="$group['lead']" />
                    <dl class="mt-10 grid grid-cols-1 gap-6 sm:grid-cols-2">
                        @foreach ($group['items'] as $i => [$icon, $title, $text])
                            <div data-reveal style="--reveal-delay: {{ $i * 80 }}ms">
                                <dt class="flex items-center gap-3 font-semibold text-slate-900">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><x-site.icon :name="$icon" class="h-5 w-5" /></span>
                                    {{ $title }}
                                </dt>
                                <dd class="mt-2 text-sm leading-6 text-slate-600">{{ $text }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
                <div data-reveal="{{ $index % 2 === 1 ? 'left' : 'right' }}">
                    <x-site.browser :url="$group['url']" :label="$group['label']">
                        <x-dynamic-component :component="'site.mockup.'.$group['mockup']" />
                    </x-site.browser>
                </div>
            </div>
        </section>
    @endforeach

    <section id="services" class="scroll-mt-24 border-t border-slate-100">
        <div class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
            <x-site.section-heading eyebrow="Services & extras" title="Every income stream, not just product sales" lead="Expenses, accounts and your own services for every shop — plus repairs, SIMs, loads, wallets, bills and NADRA for mobile shops, each with its own screen, fees and reporting." />
            <div class="mt-14 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($services as $i => [$icon, $title, $text])
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 transition duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-brand-950/5" data-reveal style="--reveal-delay: {{ ($i % 4) * 70 }}ms">
                        <x-site.icon :name="$icon" class="h-7 w-7 text-accent-500" />
                        <h3 class="mt-4 font-semibold text-slate-900">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section id="whatsapp" class="relative scroll-mt-24 overflow-hidden bg-brand-950">
        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
            <div class="site-drift absolute -right-32 -top-20 h-96 w-96 rounded-full bg-emerald-500/20 blur-3xl"></div>
        </div>
        <div class="relative mx-auto grid grid-cols-1 max-w-7xl items-start gap-14 px-4 py-24 sm:px-6 lg:grid-cols-12 lg:px-8">
            <div class="lg:col-span-8">
                <x-site.section-heading align="left" :dark="true" eyebrow="WhatsApp commerce" title="Your shop, open on WhatsApp around the clock" lead="Connect your WhatsApp Business number and keep using the WhatsApp Business app on your phone at the same time. HisabOKitab handles the ordering, updates and reminders." />
                <div class="mt-12 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @foreach ($whatsapp as $i => [$icon, $title, $text])
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-5" data-reveal style="--reveal-delay: {{ ($i % 2) * 80 }}ms">
                            <div class="flex items-center gap-3">
                                <x-site.icon :name="$icon" class="h-5 w-5 text-emerald-300" />
                                <h3 class="font-semibold text-white">{{ $title }}</h3>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-brand-100/80">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="flex justify-center lg:sticky lg:top-28 lg:col-span-4" data-reveal="right">
                <x-site.phone label="WhatsApp chat where a customer orders from the shop's chatbot">
                    <x-site.mockup.chat />
                </x-site.phone>
            </div>
        </div>
    </section>

    <section id="marketing" class="scroll-mt-24">
        <div class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 items-center gap-14 lg:grid-cols-2">
                <x-site.section-heading align="left" eyebrow="Social & ads" title="Your marketing team, built into the shop software" lead="Post to Facebook and Instagram, and run Meta ads that land customers in your WhatsApp — paid from your own ad account, with a spend cap you approve." />
                <div data-reveal="right">
                    <x-site.browser url="hisabokitab.com/marketing/ads" label="Meta ad campaign with placements on Facebook, Instagram and WhatsApp Status, and results">
                        <x-site.mockup.ads />
                    </x-site.browser>
                </div>
            </div>
            <div class="mt-16 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($marketing as $i => [$icon, $title, $text])
                    <div class="rounded-2xl border border-slate-200 bg-white p-6" data-reveal style="--reveal-delay: {{ ($i % 4) * 70 }}ms">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-50 text-accent-600"><x-site.icon :name="$icon" class="h-5 w-5" /></span>
                        <h3 class="mt-4 font-semibold text-slate-900">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $text }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-10 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6" data-reveal>
                <p class="font-semibold text-slate-900">More ad channels on the roadmap</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach (['Google Ads', 'YouTube Ads', 'LinkedIn Ads'] as $channel)
                        <span class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1.5 text-sm font-medium text-slate-700 ring-1 ring-slate-200">{{ $channel }} <span class="rounded-full bg-accent-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-accent-700">Coming soon</span></span>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section id="control" class="scroll-mt-24 bg-slate-50">
        <div class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
            <x-site.section-heading eyebrow="Control" title="Your shop, your rules" />
            <div class="mt-14 grid grid-cols-1 gap-6 md:grid-cols-3">
                @foreach ([
                    ['users', 'Roles & permissions', 'Choose exactly which screens each staff member can open — and who can edit, cancel or see cost prices.'],
                    ['shield', 'Isolated per shop', 'Every shop’s data is separated at the core. Your records are only ever visible to your team.'],
                    ['store', 'Your brand on everything', 'Your shop name and logo on invoices and posts.'],
                ] as $i => [$icon, $title, $text])
                    <div class="rounded-2xl bg-white p-7 shadow-sm ring-1 ring-slate-200" data-reveal style="--reveal-delay: {{ $i * 90 }}ms">
                        <x-site.icon :name="$icon" class="h-7 w-7 text-brand-600" />
                        <h3 class="mt-4 text-lg font-semibold text-slate-900">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <div class="pt-24">
        <x-site.cta />
    </div>
</x-site.layout>
