@props(['label'])

<div {{ $attributes->merge(['class' => 'relative w-[260px] rounded-[2.6rem] bg-slate-900 p-2.5 shadow-2xl shadow-brand-950/30 ring-1 ring-slate-700']) }} role="img" aria-label="{{ $label }}">
    <div class="absolute left-1/2 top-2.5 z-10 h-5 w-24 -translate-x-1/2 rounded-b-2xl bg-slate-900"></div>
    <div class="overflow-hidden rounded-[2.1rem] bg-white">
        {{ $slot }}
    </div>
</div>
