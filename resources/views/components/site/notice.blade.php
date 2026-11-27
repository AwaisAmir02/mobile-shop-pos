@props(['badge' => null, 'text'])

{{-- The "built, but not on for everyone yet" note under a page's hero. --}}
<div class="mx-auto mt-8 flex max-w-2xl items-start gap-3 rounded-2xl border border-accent-200 bg-accent-50 p-4 text-left" role="note" data-reveal style="--reveal-delay: 200ms">
    <span class="mt-0.5 shrink-0 rounded-full bg-accent-500 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">{{ $badge ?? 'Note' }}</span>
    <p class="text-sm leading-6 text-slate-700">{{ $text }}</p>
</div>
