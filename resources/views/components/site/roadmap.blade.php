{{-- Planned modules only. None of these is available yet, so each carries the Coming soon label and none is linked or sold. --}}
<div class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 sm:p-8" data-reveal>
    <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
        <h2 class="text-lg font-semibold text-slate-900">On the roadmap</h2>
        <p class="text-sm text-slate-500">Planned, not available yet — not part of any plan today.</p>
    </div>
    <ul class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-3">
        @foreach (\App\Support\SiteFeatures::ROADMAP as [$module, $text])
            <li class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
                <p class="flex flex-wrap items-center gap-2 font-semibold text-slate-900">{{ $module }} <span class="rounded-full bg-accent-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-accent-700">Coming soon</span></p>
                <p class="mt-1.5 text-sm leading-6 text-slate-600">{{ $text }}</p>
            </li>
        @endforeach
    </ul>
</div>
