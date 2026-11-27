<x-site.layout title="Business Software for Every Industry in Pakistan" description="HisabOKitab for shops, wholesalers and distributors, online and Daraz sellers, and service businesses like salons, gyms and workshops — each set up with the screens that trade needs." :breadcrumbs="[['Home', route('home')], ['Industries', route('site.industries')]]">
    <x-site.page-hero eyebrow="Industries" title="Set up for the business you actually run" lead="Shops, wholesalers, online sellers and service businesses: tell us what you do when you sign up, and HisabOKitab switches on the screens your trade needs — and leaves out the ones it doesn’t." />

    <h2 class="sr-only">Businesses HisabOKitab is built for</h2>
    @foreach (\App\Support\SiteIndustries::menu() as $i => $group)
        <section id="{{ $group['slug'] }}" @class(['scroll-mt-24', 'bg-slate-50' => $i % 2 === 1])>
            <div class="mx-auto grid max-w-7xl grid-cols-1 gap-10 px-4 py-20 sm:px-6 lg:grid-cols-12 lg:gap-12 lg:px-8">
                <div class="lg:col-span-4" data-reveal>
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-accent-50 text-accent-600"><x-site.icon :name="$group['icon']" /></span>
                    <h2 class="mt-5 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{{ $group['name'] }}@if ($group['badge'])<span class="ml-2 inline-flex rounded-full bg-accent-100 px-2 py-0.5 align-middle text-[10px] font-semibold uppercase tracking-wide text-accent-700">{{ $group['badge'] }}</span>@endif</h2>
                    <p class="mt-3 leading-7 text-slate-600">{{ $group['summary'] }}</p>
                    <a href="{{ $group['url'] }}" class="mt-6 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:text-brand-800">Explore {{ $group['name'] }} <x-site.icon name="arrow-right" class="h-4 w-4" /></a>
                </div>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:col-span-8">
                    @foreach ($group['items'] as $industry)
                        <a href="{{ $industry['url'] }}" class="group flex flex-col rounded-2xl border border-slate-200 bg-white p-6 transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-lg hover:shadow-brand-950/5" data-reveal style="--reveal-delay: {{ ($loop->index % 2) * 70 }}ms">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-site.icon :name="$industry['icon']" class="h-5 w-5" /></span>
                            <h3 class="mt-4 font-semibold text-slate-900 group-hover:text-brand-700">{{ $industry['name'] }}</h3>
                            <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">{{ $industry['summary'] }}</p>
                            <span class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700">Learn more <x-site.icon name="arrow-right" class="h-4 w-4 transition group-hover:translate-x-0.5" /></span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endforeach

    <section class="border-t border-slate-100">
        <div class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
            <x-site.section-heading eyebrow="Every business" title="What every business gets, whatever it does" lead="Some screens are made for one trade. These come with every plan, for every kind of business." />
            <div class="mt-14 grid grid-cols-1 gap-6 md:grid-cols-3">
                @foreach ([
                    ['udhaar', 'wallet', 'Customers & udhaar', 'Every customer’s balance itemised by bill, with WhatsApp reminders and their own ledger link.'],
                    ['cash-balances', 'calculator', 'Cash & balances', 'Cash in hand and every JazzCash, Easypaisa and bank account, with the day’s money in and out.'],
                    ['reports', 'chart', 'Reports & profit', 'Gross profit, sales, dues and expenses by day, month or year, exportable to Excel and PDF.'],
                ] as $i => [$slug, $icon, $title, $text])
                    <a href="{{ route('site.feature', $slug) }}" class="group rounded-2xl bg-white p-7 shadow-sm ring-1 ring-slate-200 transition duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-brand-950/5" data-reveal style="--reveal-delay: {{ $i * 90 }}ms">
                        <x-site.icon :name="$icon" class="h-7 w-7 text-brand-600" />
                        <h3 class="mt-4 text-lg font-semibold text-slate-900 group-hover:text-brand-700">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $text }}</p>
                    </a>
                @endforeach
            </div>
            <p class="mt-10 text-center text-sm text-slate-600" data-reveal>Something else? Every core feature works for any shop or business. <a href="{{ route('site.features') }}" class="font-semibold text-brand-700 hover:text-brand-800">See every feature</a>.</p>
        </div>
    </section>

    <div class="pt-4">
        <x-site.cta />
    </div>
</x-site.layout>
