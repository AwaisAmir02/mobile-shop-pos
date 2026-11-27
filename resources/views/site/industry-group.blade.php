@php
    // One business group's overview (SiteIndustries::groups()): its business types, then what all of them get.
    $pages = \App\Support\SiteIndustries::pagesIn($slug);
    $others = array_values(array_filter(\App\Support\SiteIndustries::menu(), fn ($other) => $other['slug'] !== $slug));
@endphp

<x-site.layout :title="$group['seo_title']" :description="$group['description']" :breadcrumbs="[['Home', route('home')], ['Industries', route('site.industries')], [$group['name'], route('site.industry', $slug)]]">
    <x-site.page-hero :eyebrow="$group['eyebrow']" :title="$group['title']" :lead="$group['lead']">
        @if (! empty($group['notice']))
            <x-site.notice :badge="$group['badge'] ?? null" :text="$group['notice']" />
        @endif
        <p class="mt-8" data-reveal style="--reveal-delay: 240ms">
            <a href="{{ route('site.industries') }}" class="text-sm font-semibold text-brand-700 hover:text-brand-800">&larr; All industries</a>
        </p>
    </x-site.page-hero>

    <section>
        <div class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
            <x-site.section-heading eyebrow="Pick your business" :title="'Made for '.\Illuminate\Support\Str::lower($group['name'])" />
            <div @class(['mt-14 grid grid-cols-1 gap-5 sm:grid-cols-2', 'lg:grid-cols-4' => count($pages) === 4, 'lg:grid-cols-3' => count($pages) !== 4])>
                @foreach ($pages as $pageSlug => $page)
                    @php($partner = \App\Support\SiteIndustries::partnerOf($pageSlug))
                    <a href="{{ route('site.industry', $pageSlug) }}" class="group flex flex-col rounded-2xl border border-slate-200 bg-white p-6 transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-lg hover:shadow-brand-950/5" data-reveal style="--reveal-delay: {{ ($loop->index % 4) * 70 }}ms">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-site.icon :name="$page['icon']" /></span>
                        <h3 class="mt-4 text-lg font-semibold text-slate-900 group-hover:text-brand-700">{{ $page['name'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $page['summary'] }}</p>
                        <ul class="mt-4 flex-1 space-y-2 text-sm text-slate-700">
                            @foreach (array_slice($page['items'], 0, 3) as [, $itemTitle])
                                <li class="flex gap-2"><x-site.icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />{{ $itemTitle }}</li>
                            @endforeach
                        </ul>
                        @if ($partner !== null)
                            <p class="mt-4 text-xs font-medium text-slate-500">Runs together with {{ \App\Support\SiteIndustries::find($partner)['short'] }}</p>
                        @endif
                        <span class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700">See every feature <x-site.icon name="arrow-right" class="h-4 w-4 transition group-hover:translate-x-0.5" /></span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-slate-50">
        <div class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
            <x-site.section-heading eyebrow="Built in" :title="$group['shared_title']" />
            <x-site.item-grid class="mt-14" :items="$group['items']" />
        </div>
    </section>

    @if (! empty($group['spotlights']))
        <x-site.spotlights :spotlights="$group['spotlights']" />
    @endif

    <x-site.related-features :slugs="$group['related']" />

    <section class="border-t border-slate-100">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
            <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-accent-600">Other industries</h2>
            <div class="mt-6 grid grid-cols-1 gap-5 md:grid-cols-3">
                @foreach ($others as $other)
                    <a href="{{ $other['url'] }}" class="group rounded-2xl border border-slate-200 bg-white p-6 transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-lg hover:shadow-brand-950/5">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-site.icon :name="$other['icon']" class="h-5 w-5" /></span>
                        <h3 class="mt-4 font-semibold text-slate-900 group-hover:text-brand-700">{{ $other['name'] }}@if ($other['badge'])<span class="ml-1.5 inline-flex rounded-full bg-accent-100 px-2 py-0.5 align-middle text-[10px] font-semibold uppercase tracking-wide text-accent-700">{{ $other['badge'] }}</span>@endif</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $other['summary'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <x-site.cta />
</x-site.layout>
