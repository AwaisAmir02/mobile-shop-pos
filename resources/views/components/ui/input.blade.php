@props(['type' => 'text'])

@php
    // Phones keep 16px text (smaller makes iOS zoom on focus); desktop drops to 14px
    // unless the screen asked for its own size, e.g. the large amount inputs.
    $ownSize = preg_match('/(^|\s)text-(xs|sm|base|lg|xl|\dxl)(\s|$)/', (string) $attributes->get('class'));
@endphp

<input type="{{ $type }}" {{ $attributes->merge(['class' => 'block w-full rounded-md border-slate-300 py-2 px-3 text-base text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500 disabled:bg-slate-50 disabled:text-slate-500'.($ownSize ? '' : ' sm:text-sm')]) }}>
