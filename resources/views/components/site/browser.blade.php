@props(['url' => 'hisabokitab.com', 'label'])

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xl shadow-brand-950/15 ring-1 ring-slate-900/5']) }} role="img" aria-label="{{ $label }}">
    <div class="flex items-center gap-3 border-b border-slate-100 bg-slate-50 px-4 py-2.5">
        <div class="flex gap-1.5">
            <span class="h-2.5 w-2.5 rounded-full bg-rose-400"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
        </div>
        <div class="mx-auto flex min-w-0 max-w-xs flex-1 items-center justify-center gap-1.5 rounded-md bg-white px-3 py-1 text-[11px] text-slate-400 ring-1 ring-slate-200">
            <x-site.icon name="lock" class="h-3 w-3 shrink-0" />
            <span class="truncate">{{ $url }}</span>
        </div>
        <div class="hidden w-12 sm:block"></div>
    </div>
    {{ $slot }}
</div>
