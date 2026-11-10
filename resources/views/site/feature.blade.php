@php
    $mockup = $feature['mockup'];
    $related = collect($feature['related'])->mapWithKeys(fn ($relatedSlug) => [$relatedSlug => \App\Support\SiteFeatures::find($relatedSlug)]);
@endphp

<x-site.layout :title="$feature['seo_title']" :description="$feature['description']" :breadcrumbs="[['Home', route('home')], ['Features', route('site.features')], [$feature['name'], route('site.feature', $slug)]]">
    <x-site.page-hero :eyebrow="$feature['eyebrow']" :title="$feature['title']" :lead="$feature['lead']">
        @if (! empty($feature['notice']))
            <div class="mx-auto mt-8 flex max-w-2xl items-start gap-3 rounded-2xl border border-accent-200 bg-accent-50 p-4 text-left" role="note" data-reveal style="--reveal-delay: 200ms">
                <span class="mt-0.5 shrink-0 rounded-full bg-accent-500 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">{{ $feature['badge'] ?? 'Note' }}</span>
                <p class="text-sm leading-6 text-slate-700">{{ $feature['notice'] }}</p>
            </div>
        @endif
        <p class="mt-8" data-reveal style="--reveal-delay: 240ms">
            <a href="{{ route('site.features') }}" class="text-sm font-semibold text-brand-700 hover:text-brand-800">&larr; All {{ config('branding.name') }} features</a>
        </p>
    </x-site.page-hero>

    @if ($mockup !== null && $mockup['frame'] === 'phone')
        <section class="relative overflow-hidden bg-brand-950">
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="site-drift absolute -right-32 -top-20 h-96 w-96 rounded-full bg-emerald-500/20 blur-3xl"></div>
            </div>
            <div class="relative mx-auto grid grid-cols-1 max-w-7xl items-start gap-14 px-4 py-24 sm:px-6 lg:grid-cols-12 lg:px-8">
                <div class="lg:col-span-8">
                    <h2 class="sr-only">{{ $feature['name'] }}</h2>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        @foreach ($feature['items'] as $i => [$icon, $title, $text])
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
                    <x-site.phone :label="$mockup['label']">
                        <x-dynamic-component :component="'site.mockup.'.$mockup['component']" />
                    </x-site.phone>
                </div>
            </div>
        </section>
    @elseif ($mockup !== null)
        <section>
            <div class="mx-auto grid grid-cols-1 max-w-7xl items-center gap-14 px-4 py-24 sm:px-6 lg:grid-cols-2 lg:px-8">
                <div>
                    <h2 class="sr-only">{{ $feature['name'] }}</h2>
                    <dl class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        @foreach ($feature['items'] as $i => [$icon, $title, $text])
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
                <div data-reveal="right">
                    <x-site.browser :url="$mockup['url']" :label="$mockup['label']">
                        <x-dynamic-component :component="'site.mockup.'.$mockup['component']" />
                    </x-site.browser>
                </div>
            </div>
        </section>
    @else
        <section>
            <div class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
                <h2 class="sr-only">{{ $feature['name'] }}</h2>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($feature['items'] as $i => [$icon, $title, $text])
                        <div class="rounded-2xl border border-slate-200 bg-white p-6 transition duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-brand-950/5" data-reveal style="--reveal-delay: {{ ($i % 4) * 70 }}ms">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-50 text-accent-600"><x-site.icon :name="$icon" class="h-5 w-5" /></span>
                            <h3 class="mt-4 font-semibold text-slate-900">{{ $title }}</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if (! empty($feature['roadmap']))
        <section>
            <div class="mx-auto max-w-7xl px-4 pb-24 sm:px-6 lg:px-8">
                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6" data-reveal>
                    <p class="font-semibold text-slate-900">More ad channels on the roadmap</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($feature['roadmap'] as $channel)
                            <span class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1.5 text-sm font-medium text-slate-700 ring-1 ring-slate-200">{{ $channel }} <span class="rounded-full bg-accent-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-accent-700">Coming soon</span></span>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if (! empty($feature['spotlights']))
        <section class="bg-slate-50">
            <div @class(['mx-auto grid grid-cols-1 gap-6 px-4 py-20 sm:px-6 lg:px-8', 'max-w-3xl' => count($feature['spotlights']) === 1, 'max-w-5xl md:grid-cols-2' => count($feature['spotlights']) > 1])>
                @foreach ($feature['spotlights'] as $i => [$title, $text])
                    <div class="rounded-3xl bg-white p-8 shadow-sm ring-1 ring-slate-200" data-reveal style="--reveal-delay: {{ $i * 90 }}ms">
                        <h2 class="text-lg font-semibold text-slate-900">{{ $title }}</h2>
                        <p class="mt-2 text-slate-600">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="border-t border-slate-100">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
            <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-accent-600">Related features</h2>
            <div class="mt-6 grid grid-cols-1 gap-5 md:grid-cols-3">
                @foreach ($related as $relatedSlug => $relatedFeature)
                    <a href="{{ route('site.feature', $relatedSlug) }}" class="group rounded-2xl border border-slate-200 bg-white p-6 transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-lg hover:shadow-brand-950/5">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-site.icon :name="$relatedFeature['icon']" class="h-5 w-5" /></span>
                        <h3 class="mt-4 font-semibold text-slate-900 group-hover:text-brand-700">{{ $relatedFeature['name'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $relatedFeature['summary'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <x-site.cta />
</x-site.layout>
