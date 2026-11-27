@props(['spotlights'])

<section class="bg-slate-50">
    <div @class(['mx-auto grid grid-cols-1 gap-6 px-4 py-20 sm:px-6 lg:px-8', 'max-w-3xl' => count($spotlights) === 1, 'max-w-5xl md:grid-cols-2' => count($spotlights) > 1])>
        @foreach ($spotlights as $i => [$title, $text])
            <div class="rounded-3xl bg-white p-8 shadow-sm ring-1 ring-slate-200" data-reveal style="--reveal-delay: {{ $i * 90 }}ms">
                <h2 class="text-lg font-semibold text-slate-900">{{ $title }}</h2>
                <p class="mt-2 text-slate-600">{{ $text }}</p>
            </div>
        @endforeach
    </div>
</section>
