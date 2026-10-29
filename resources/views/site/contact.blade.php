@php
    $contact = config('site.contact');
    $channels = [
        ['chat', 'WhatsApp', 'Fastest way to reach us', 'Chat on WhatsApp', 'https://wa.me/'.$contact['whatsapp'], 'bg-emerald-500'],
        ['mail', 'Email', $contact['email'], 'Send an email', 'mailto:'.$contact['email'], 'bg-brand-600'],
        ['phone', 'Phone', $contact['phone'], 'Call us', 'tel:'.preg_replace('/[^0-9+]/', '', $contact['phone']), 'bg-accent-500'],
    ];
@endphp

<x-site.layout title="Contact" description="Contact HisabOKitab on WhatsApp, email or phone. Based in Karachi, Pakistan.">
    <x-site.page-hero eyebrow="Contact" title="Let’s get your shop set up" lead="Questions about features, pricing or moving from your register? Message us — a real person will reply." />

    <section class="mx-auto max-w-5xl px-4 py-20 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
            @foreach ($channels as $i => [$icon, $title, $detail, $action, $href, $color])
                <a href="{{ $href }}" @if (str_starts_with($href, 'https')) target="_blank" rel="noopener" @endif class="group flex flex-col rounded-2xl border border-slate-200 bg-white p-7 transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-xl hover:shadow-brand-950/5" data-reveal style="--reveal-delay: {{ $i * 90 }}ms">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl text-white {{ $color }}"><x-site.icon :name="$icon" /></span>
                    <h2 class="mt-5 text-lg font-semibold text-slate-900">{{ $title }}</h2>
                    <p class="mt-1 break-words text-sm text-slate-600">{{ $detail }}</p>
                    <span class="mt-6 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700">{{ $action }} <x-site.icon name="arrow-right" class="h-4 w-4 transition group-hover:translate-x-0.5" /></span>
                </a>
            @endforeach
        </div>

        <div class="mt-10 flex flex-col items-center gap-3 rounded-2xl bg-slate-50 p-8 text-center ring-1 ring-slate-200" data-reveal>
            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-white text-brand-600 shadow-sm"><x-site.icon name="map" /></span>
            <p class="font-semibold text-slate-900">{{ $contact['location'] }}</p>
            <p class="text-sm text-slate-600">We work with shops across Pakistan — online setup and support, wherever you are.</p>
        </div>
    </section>
</x-site.layout>
