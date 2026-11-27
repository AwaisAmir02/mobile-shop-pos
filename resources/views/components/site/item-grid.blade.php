@props(['items'])

{{-- Capability cards: icon, title and one line each, four to a row on desktop. The caller supplies the section around it. --}}
<div {{ $attributes->class('grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4') }}>
    @foreach ($items as $i => [$icon, $title, $text])
        <div class="rounded-2xl border border-slate-200 bg-white p-6 transition duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-brand-950/5" data-reveal style="--reveal-delay: {{ ($i % 4) * 70 }}ms">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-50 text-accent-600"><x-site.icon :name="$icon" class="h-5 w-5" /></span>
            <h3 class="mt-4 font-semibold text-slate-900">{{ $title }}</h3>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ $text }}</p>
        </div>
    @endforeach
</div>
