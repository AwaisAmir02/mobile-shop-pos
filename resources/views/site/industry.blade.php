@php
    // One business type's page (SiteIndustries::all()), under its group in the breadcrumb.
    $group = \App\Support\SiteIndustries::findGroup($page['group']);
    $groupUrl = route('site.industry', $page['group']);
    $partner = \App\Support\SiteIndustries::partnerOf($slug);
    $siblings = array_diff_key(\App\Support\SiteIndustries::pagesIn($page['group']), [$slug => true]);
@endphp

<x-site.layout :title="$page['seo_title']" :description="$page['description']" :breadcrumbs="[['Home', route('home')], ['Industries', route('site.industries')], [$group['name'], $groupUrl], [$page['name'], route('site.industry', $slug)]]">
    <x-site.page-hero :eyebrow="$page['eyebrow']" :title="$page['title']" :lead="$page['lead']">
        @if (! empty($page['notice']))
            <x-site.notice :badge="$page['badge'] ?? null" :text="$page['notice']" />
        @endif
        <p class="mt-8 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-sm font-semibold" data-reveal style="--reveal-delay: 240ms">
            <a href="{{ $groupUrl }}" class="text-brand-700 hover:text-brand-800">&larr; {{ $group['name'] }}</a>
            <a href="{{ route('site.industries') }}" class="text-slate-500 hover:text-brand-700">All industries</a>
        </p>
    </x-site.page-hero>

    <section>
        <div class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
            <h2 class="sr-only">{{ $page['name'] }}</h2>
            <x-site.item-grid :items="$page['items']" />
        </div>
    </section>

    @if ($partner !== null && ! empty($page['pair']))
        @php($partnerPage = \App\Support\SiteIndustries::find($partner))
        <section>
            <div class="mx-auto max-w-5xl px-4 pb-24 sm:px-6 lg:px-8">
                <div class="flex flex-col gap-6 rounded-3xl border border-brand-100 bg-brand-50/60 p-8 sm:flex-row sm:items-center" data-reveal>
                    <div class="flex shrink-0 -space-x-2" aria-hidden="true">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-brand-600 shadow-sm ring-1 ring-brand-100"><x-site.icon :name="$page['icon']" /></span>
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-accent-600 shadow-sm ring-1 ring-brand-100"><x-site.icon :name="$partnerPage['icon']" /></span>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-lg font-semibold text-slate-900">{{ $page['pair'][0] }}</h2>
                        <p class="mt-1 text-slate-600">{{ $page['pair'][1] }}</p>
                    </div>
                    <a href="{{ route('site.industry', $partner) }}" class="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-700 hover:text-brand-800">See {{ $partnerPage['name'] }} <x-site.icon name="arrow-right" class="h-4 w-4" /></a>
                </div>
            </div>
        </section>
    @endif

    @if (! empty($page['spotlights']))
        <x-site.spotlights :spotlights="$page['spotlights']" />
    @endif

    @if ($siblings !== [])
        <section class="border-t border-slate-100">
            <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
                <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-accent-600">More in {{ $group['name'] }}</h2>
                <div class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($siblings as $siblingSlug => $sibling)
                        <a href="{{ route('site.industry', $siblingSlug) }}" class="group flex items-start gap-4 rounded-2xl border border-slate-200 bg-white p-5 transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-lg hover:shadow-brand-950/5">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-site.icon :name="$sibling['icon']" class="h-5 w-5" /></span>
                            <span class="min-w-0">
                                <span class="block font-semibold text-slate-900 group-hover:text-brand-700">{{ $sibling['name'] }}</span>
                                <span class="mt-1 line-clamp-2 text-sm leading-6 text-slate-600">{{ $sibling['summary'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <x-site.related-features :slugs="$page['related']" />

    <x-site.cta />
</x-site.layout>
