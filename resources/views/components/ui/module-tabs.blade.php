@props([
    // A module key from App\Support\ModuleNav.
    'module',
    // Route name of the current tab. Defaults to the current route.
    'active' => null,
    'label' => null,
])

@php
    $tabs = \App\Support\ModuleNav::tabs($module);
    $active ??= request()->route()?->getName();
@endphp

{{-- A single tab is no navigation, so nothing renders. --}}
@if (count($tabs) > 1)
    <nav {{ $attributes->class(['-mt-2 mb-6 flex gap-1 overflow-x-auto border-b border-line']) }} aria-label="{{ $label ?? 'Sections' }}">
        @foreach ($tabs as $tab)
            @php($isActive = $tab['route'] === $active)
            <a
                href="{{ route($tab['route']) }}"
                wire:navigate
                data-module-tab
                @if ($isActive) aria-current="page" @endif
                @class([
                    'relative shrink-0 whitespace-nowrap px-3.5 pb-3 pt-2 text-sm font-semibold transition-colors focus-visible:rounded-lg',
                    'text-on-primary-soft after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-full after:bg-primary' => $isActive,
                    'text-ink-faint hover:text-ink' => ! $isActive,
                ])
            >{{ $tab['label'] }}</a>
        @endforeach
    </nav>
@endif
