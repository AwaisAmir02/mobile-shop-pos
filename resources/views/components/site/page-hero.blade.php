@props(['eyebrow', 'title', 'lead' => null])

<section class="relative overflow-hidden border-b border-slate-100">
    <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
        <div class="absolute inset-0 bg-[linear-gradient(to_right,#e2e8f0_1px,transparent_1px),linear-gradient(to_bottom,#e2e8f0_1px,transparent_1px)] bg-[size:44px_44px] [mask-image:radial-gradient(ellipse_60%_70%_at_50%_0%,#000_50%,transparent_100%)] opacity-50"></div>
        <div class="site-drift absolute -top-32 left-1/2 h-80 w-[40rem] -translate-x-1/2 rounded-full bg-brand-300/25 blur-3xl"></div>
    </div>
    <div class="mx-auto max-w-4xl px-4 py-20 text-center sm:px-6 lg:py-24">
        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-accent-600" data-reveal>{{ $eyebrow }}</p>
        <h1 class="mt-4 text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl lg:text-[3.5rem] lg:leading-[1.08]" data-reveal style="--reveal-delay: 80ms">{{ $title }}</h1>
        @if ($lead)
            <p class="mx-auto mt-6 max-w-2xl text-lg leading-8 text-slate-600" data-reveal style="--reveal-delay: 160ms">{{ $lead }}</p>
        @endif
        {{ $slot }}
    </div>
</section>
