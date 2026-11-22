<x-site.layout title="POS Software for Every Kind of Shop in Pakistan" description="HisabOKitab for mobile and electronics shops, pharmacies and medical stores, kiryana and grocery stores, and clothing and shoe shops — each set up with the screens that trade needs." :breadcrumbs="[['Home', route('home')], ['Industries', route('site.industries')]]">
    <x-site.page-hero eyebrow="Industries" title="Set up for the shop you actually run" lead="Tell us what you sell when you sign up and HisabOKitab switches on the screens your trade needs — IMEIs and repairs for a mobile shop, salt search and a prescription register for a pharmacy, packs and udhaar links for a kiryana store, sizes and exchanges for clothing and shoes." />

    <section>
        <div class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
            <h2 class="sr-only">Shops HisabOKitab is built for</h2>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (\App\Support\SiteIndustries::menu() as $industry)
                    <a href="{{ $industry['url'] }}" class="group flex flex-col rounded-2xl border border-slate-200 bg-white p-6 transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-lg hover:shadow-brand-950/5" data-reveal style="--reveal-delay: {{ $loop->index * 70 }}ms">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-site.icon :name="$industry['icon']" class="h-5 w-5" /></span>
                        <h3 class="mt-4 font-semibold text-slate-900 group-hover:text-brand-700">{{ $industry['name'] }}</h3>
                        <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">{{ $industry['summary'] }}</p>
                        <span class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700">Learn more <x-site.icon name="arrow-right" class="h-4 w-4 transition group-hover:translate-x-0.5" /></span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-slate-50">
        <div class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
            <x-site.section-heading eyebrow="Every shop" title="What every shop gets, whatever it sells" lead="Some screens are made for one trade. These come with every plan, for every kind of shop." />
            <div class="mt-14 grid grid-cols-1 gap-6 md:grid-cols-3">
                @foreach ([
                    ['returns-exchange', 'layers', 'Returns, exchange & promotions', 'Returns with credit notes, store credit, one-step exchanges and scheduled discounts.'],
                    ['udhaar', 'wallet', 'Udhaar khata', 'Every customer’s balance itemised by sale, with WhatsApp reminders and their own ledger link.'],
                    ['stock', 'box', 'Stock & FIFO costing', 'Stock by batch and vendor, every sale costed from the batch it came from, and write-offs with a reason.'],
                ] as $i => [$slug, $icon, $title, $text])
                    <a href="{{ route('site.feature', $slug) }}" class="group rounded-2xl bg-white p-7 shadow-sm ring-1 ring-slate-200 transition duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-brand-950/5" data-reveal style="--reveal-delay: {{ $i * 90 }}ms">
                        <x-site.icon :name="$icon" class="h-7 w-7 text-brand-600" />
                        <h3 class="mt-4 text-lg font-semibold text-slate-900 group-hover:text-brand-700">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $text }}</p>
                    </a>
                @endforeach
            </div>
            <p class="mt-10 text-center text-sm text-slate-600" data-reveal>Wholesaler, distributor or online seller? You can sign up today and use every core feature. <a href="{{ route('site.features') }}" class="font-semibold text-brand-700 hover:text-brand-800">See every feature</a>.</p>
        </div>
    </section>

    <div class="pt-24">
        <x-site.cta />
    </div>
</x-site.layout>
