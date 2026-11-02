<x-site.layout title="Features" description="Every HisabOKitab feature for shops and online sellers: sales and invoices, FIFO stock costing, udhaar ledger and expenses, multi-branch, WhatsApp ordering, Facebook and Instagram posting and Meta ads — plus repairs, SIM, loads, bills and NADRA for mobile shops.">
    <x-site.page-hero eyebrow="Features" title="Built around how Pakistani shops really work" lead="Kiryana store, bakery, mobile shop or online seller — from the first scan at the counter to the WhatsApp message that brings a customer back, here’s everything HisabOKitab does." />

    <section>
        <div class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
            <h2 class="sr-only">All features</h2>
            {{-- Each card keeps the anchor its section had on the old single page, so /features#whatsapp etc. still land in the right place. --}}
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (\App\Support\SiteFeatures::all() as $slug => $feature)
                    <a id="{{ $feature['anchor'] }}" href="{{ route('site.feature', $slug) }}" class="group flex scroll-mt-24 flex-col rounded-2xl border border-slate-200 bg-white p-6 transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-lg hover:shadow-brand-950/5" data-reveal style="--reveal-delay: {{ ($loop->index % 3) * 70 }}ms">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-site.icon :name="$feature['icon']" class="h-5 w-5" /></span>
                        <h3 class="mt-4 font-semibold text-slate-900 group-hover:text-brand-700">{{ $feature['name'] }}</h3>
                        <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">{{ $feature['summary'] }}</p>
                        <span class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700">Learn more <x-site.icon name="arrow-right" class="h-4 w-4 transition group-hover:translate-x-0.5" /></span>
                    </a>
                @endforeach
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
