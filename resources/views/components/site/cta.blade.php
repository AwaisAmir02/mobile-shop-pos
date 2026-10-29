@props(['title' => 'Ready to make hisab easy?', 'lead' => 'Set up your shop, add your products and make your first sale — the same day.'])

<section class="px-4 pb-24 sm:px-6 lg:px-8">
    <div class="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-gradient-to-br from-brand-700 via-brand-600 to-brand-800 px-6 py-16 text-center shadow-2xl shadow-brand-900/25 sm:px-12 lg:py-20" data-reveal="zoom">
        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
            <div class="site-drift absolute -right-16 -top-16 h-72 w-72 rounded-full bg-accent-500/30 blur-3xl"></div>
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.12)_1px,transparent_0)] bg-[size:22px_22px]"></div>
        </div>
        <div class="relative">
            <h2 class="text-3xl font-extrabold tracking-tight text-white sm:text-4xl">{{ $title }}</h2>
            <p class="mx-auto mt-4 max-w-xl text-lg text-brand-100">{{ $lead }}</p>
            <div class="mt-9 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ route('site.pricing') }}" class="inline-flex items-center justify-center gap-2 rounded-full bg-accent-500 px-7 py-3.5 font-semibold text-white shadow-lg shadow-accent-600/40 transition hover:-translate-y-0.5 hover:bg-accent-600">Get started <x-site.icon name="arrow-right" class="h-5 w-5" /></a>
                <a href="{{ route('site.contact') }}" class="inline-flex items-center justify-center rounded-full border border-white/30 px-7 py-3.5 font-semibold text-white transition hover:bg-white/10">Talk to us</a>
            </div>
        </div>
    </div>
</section>
