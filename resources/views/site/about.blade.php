<x-site.layout title="About" description="HisabOKitab is shop management software made in Pakistan for every kind of shop and online seller — bringing the counter, the ledger and WhatsApp selling together.">
    <x-site.page-hero eyebrow="About us" title="Made for the shops that keep Pakistan running" lead="The kiryana on the corner, the bakery down the street, the mobile shop in the market and the seller packing orders from home — they all juggle stock, udhaar and customers on WhatsApp. HisabOKitab was built to keep up with all of it." />

    <section class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 gap-16 lg:grid-cols-2 lg:items-center">
            <div class="space-y-6 text-lg leading-8 text-slate-600" data-reveal="left">
                <h2 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">Hisab ab hua asaan.</h2>
                <p>Too many shopkeepers still close the day with a calculator, a register and a stack of WhatsApp chats. Profit is a guess, udhaar lives in someone’s memory, and online customers wait for a reply.</p>
                <p>We built HisabOKitab so that every sale, every rupee of udhaar and every WhatsApp order lands in one place — with the real cost behind it and a clear record of who owes what.</p>
                <p>Then we went further: the same dashboard posts to Facebook and Instagram and runs Meta ads that bring new customers straight into your WhatsApp.</p>
            </div>
            <div class="grid grid-cols-2 gap-4" data-reveal="right">
                @foreach ([['7', 'kinds of business, one app'], ['2', 'languages on WhatsApp'], ['1', 'screen for counter & online'], ['100%', 'per-shop data isolation']] as $i => [$value, $label])
                    <div @class(['rounded-2xl p-6', 'bg-brand-600 text-white' => $i === 0 || $i === 3, 'bg-slate-50 ring-1 ring-slate-200' => $i === 1 || $i === 2])>
                        <p class="text-4xl font-extrabold tracking-tight">{{ $value }}</p>
                        <p @class(['mt-2 text-sm', 'text-brand-100' => $i === 0 || $i === 3, 'text-slate-600' => $i === 1 || $i === 2])>{{ $label }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-slate-50">
        <div class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
            <x-site.section-heading eyebrow="What we believe" title="Software should earn its place on the counter" />
            <div class="mt-14 grid grid-cols-1 gap-6 md:grid-cols-3">
                @foreach ([
                    ['calculator', 'Numbers you can trust', 'If the profit or the udhaar is wrong, nothing else matters. Accuracy comes first in everything we build.'],
                    ['bolt', 'Fast at the counter', 'A customer is waiting. Every screen is designed to get the sale done in as few taps as possible.'],
                    ['heart', 'Built with shopkeepers', 'Features come from real shops — their routines, their customers and the way they actually talk.'],
                ] as $i => [$icon, $title, $text])
                    <div class="rounded-2xl bg-white p-7 shadow-sm ring-1 ring-slate-200" data-reveal style="--reveal-delay: {{ $i * 90 }}ms">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-accent-50 text-accent-600"><x-site.icon :name="$icon" /></span>
                        <h3 class="mt-5 text-lg font-semibold text-slate-900">{{ $title }}</h3>
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
