@props(['type' => 'text'])

@php
    // Phones keep 16px text (smaller makes iOS zoom on focus); desktop drops to 14px
    // unless the screen asked for its own size, e.g. the large amount inputs.
    $ownSize = preg_match('/(^|\s)text-(xs|sm|base|lg|xl|\dxl)(\s|$)/', (string) $attributes->get('class'));
@endphp

<input type="{{ $type }}" {{ $attributes->merge(['class' => 'block w-full rounded-lg border-line-strong bg-surface py-2 px-3 text-base text-ink shadow-soft placeholder:text-ink-faint focus:border-link focus:ring-link disabled:bg-surface-sunken disabled:text-ink-faint'.($ownSize ? '' : ' sm:text-sm')]) }}>
