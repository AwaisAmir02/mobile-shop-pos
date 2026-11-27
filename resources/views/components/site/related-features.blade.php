@props(['slugs', 'title' => 'Related features'])

{{-- Cards for SiteFeatures pages, by slug. --}}
<section class="border-t border-slate-100">
    <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-accent-600">{{ $title }}</h2>
        <div class="mt-6 grid grid-cols-1 gap-5 md:grid-cols-3">
            @foreach ($slugs as $relatedSlug)
                @php($relatedFeature = \App\Support\SiteFeatures::find($relatedSlug))
                <a href="{{ route('site.feature', $relatedSlug) }}" class="group rounded-2xl border border-slate-200 bg-white p-6 transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-lg hover:shadow-brand-950/5">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-site.icon :name="$relatedFeature['icon']" class="h-5 w-5" /></span>
                    <h3 class="mt-4 font-semibold text-slate-900 group-hover:text-brand-700">{{ $relatedFeature['name'] }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $relatedFeature['summary'] }}</p>
                </a>
            @endforeach
        </div>
    </div>
</section>
