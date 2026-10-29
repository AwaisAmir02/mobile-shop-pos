@php
    $plans = \App\Models\Plan::active()->ordered()->get();
    $columns = match (true) {
        $plans->count() === 1 => 'mx-auto max-w-md',
        $plans->count() === 2 => 'mx-auto max-w-4xl md:grid-cols-2',
        $plans->count() === 3 => 'lg:grid-cols-3',
        default => 'md:grid-cols-2 xl:grid-cols-4',
    };

    $faqs = [
        ['Do I need to install anything?', 'No. HisabOKitab runs in your browser on a phone, tablet or computer. Log in and start selling.'],
        ['Who pays for Meta ads?', 'Ads are billed by Meta directly to your own ad account. HisabOKitab never charges you for ad spend, and you confirm the maximum spend before any ad goes live.'],
        ['Can I keep using WhatsApp Business on my phone?', 'Yes. Your number stays usable in the WhatsApp Business app while HisabOKitab handles chatbot orders and messages.'],
        ['Is my shop’s data private?', 'Yes. Every shop’s records are isolated from every other shop, and staff only see the screens you allow.'],
    ];
@endphp

<x-site.layout title="Pricing" description="HisabOKitab plans for mobile and electronics shops in Pakistan — from counter sales and udhaar to WhatsApp ordering, social posting and Meta ads.">
    <x-site.page-hero eyebrow="Pricing" title="Simple plans that grow with your shop" lead="Start with the counter. Add WhatsApp ordering and marketing when you’re ready. No hidden charges — ad spend is billed by Meta to your own account." />

    <section class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
        @if ($plans->isEmpty())
            <div class="mx-auto max-w-xl rounded-3xl border border-slate-200 bg-white p-10 text-center shadow-sm" data-reveal>
                <h2 class="text-xl font-bold text-slate-900">Plans are being updated</h2>
                <p class="mt-2 text-slate-600">Talk to us and we’ll recommend the right setup for your shop.</p>
                <a href="{{ route('site.contact') }}" class="mt-6 inline-flex items-center gap-2 rounded-full bg-accent-500 px-6 py-3 text-sm font-semibold text-white hover:bg-accent-600">Contact us <x-site.icon name="arrow-right" class="h-4 w-4" /></a>
            </div>
        @else
            <div class="grid grid-cols-1 gap-6 lg:items-stretch {{ $columns }}">
                @foreach ($plans as $i => $plan)
                    <div @class([
                        'relative flex flex-col rounded-3xl p-8',
                        'bg-gradient-to-b from-brand-700 to-brand-900 text-white shadow-2xl shadow-brand-900/30 lg:-my-4 lg:py-12' => $plan->is_featured,
                        'border border-slate-200 bg-white shadow-sm' => ! $plan->is_featured,
                    ]) data-reveal style="--reveal-delay: {{ $i * 100 }}ms">
                        @if ($plan->is_featured)
                            <span class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-accent-500 px-3 py-1 text-xs font-semibold text-white shadow">Most popular</span>
                        @endif
                        <h2 @class(['text-xl font-bold', 'text-slate-900' => ! $plan->is_featured])>{{ $plan->name }}</h2>
                        @if ($plan->tagline)
                            <p @class(['mt-1 text-sm', 'text-brand-100' => $plan->is_featured, 'text-slate-500' => ! $plan->is_featured])>{{ $plan->tagline }}</p>
                        @endif
                        <p class="mt-6 flex items-baseline gap-1.5">
                            <span @class(['text-4xl font-extrabold tracking-tight tabular-nums', 'text-slate-900' => ! $plan->is_featured])>{{ $plan->priceLabel() }}</span>
                            <span @class(['text-sm', 'text-brand-100' => $plan->is_featured, 'text-slate-500' => ! $plan->is_featured])>/ {{ $plan->intervalLabel() }}</span>
                        </p>
                        <ul class="mt-8 flex-1 space-y-3 text-sm">
                            @foreach ($plan->featureList() as $feature)
                                <li @class(['flex gap-3', 'opacity-50' => ! $feature['included']])>
                                    @if ($feature['included'])
                                        <x-site.icon name="check" @class(['h-5 w-5 shrink-0', 'text-accent-300' => $plan->is_featured, 'text-brand-600' => ! $plan->is_featured]) />
                                        <span>{{ $feature['label'] }}</span>
                                    @else
                                        <x-site.icon name="close" class="h-5 w-5 shrink-0" />
                                        <span class="line-through decoration-1"><span class="sr-only">Not included: </span>{{ $feature['label'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <a href="{{ route('site.signup', ['plan' => $plan->id]) }}" @class([
                            'mt-10 inline-flex items-center justify-center gap-2 rounded-full px-6 py-3 text-sm font-semibold transition hover:-translate-y-0.5',
                            'bg-accent-500 text-white shadow-lg shadow-accent-600/30 hover:bg-accent-600' => $plan->is_featured,
                            'bg-brand-600 text-white hover:bg-brand-700' => ! $plan->is_featured,
                        ])>Get started <x-site.icon name="arrow-right" class="h-4 w-4" /></a>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="bg-slate-50">
        <div class="mx-auto max-w-3xl px-4 py-24 sm:px-6">
            <x-site.section-heading eyebrow="Questions" title="Frequently asked" />
            <div class="mt-12 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
                @foreach ($faqs as [$question, $answer])
                    <details class="group p-6" data-reveal>
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-slate-900 [&::-webkit-details-marker]:hidden">
                            {{ $question }}
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 transition group-open:rotate-45 group-open:bg-brand-600 group-open:text-white">+</span>
                        </summary>
                        <p class="mt-3 leading-7 text-slate-600">{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    <div class="pt-24">
        <x-site.cta title="Not sure which plan fits?" lead="Tell us about your shop and we’ll recommend the right setup." />
    </div>
</x-site.layout>
