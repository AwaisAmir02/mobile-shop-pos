@props([
    'href' => null,
    'danger' => false,
    // A styled confirmation before the action (replaces wire:confirm).
    'confirm' => null,
    'confirmTitle' => null,
    'confirmLabel' => null,
])

{{-- One item in an x-ui.row-actions menu. Closes the menu when chosen. --}}
@php
    $classes = 'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium transition-colors focus:outline-none '
        .($danger
            ? 'text-danger hover:bg-danger-soft focus-visible:bg-danger-soft'
            : 'text-ink-muted hover:bg-surface-sunken hover:text-ink focus-visible:bg-surface-sunken focus-visible:text-ink');
@endphp

@if ($href)
    <a href="{{ $href }}" role="menuitem" {{ $attributes->class([$classes]) }} x-on:click="open = false">{{ $slot }}</a>
@elseif ($confirm)
    <button
        type="button"
        role="menuitem"
        {{ $attributes->whereDoesntStartWith('wire:')->class([$classes]) }}
        x-on:click="{{ \App\Support\ConfirmAction::handler($confirm, $confirmTitle, $confirmLabel, $danger, 'open = false;') }}"
    >{{ $slot }}</button>
    <button type="button" hidden tabindex="-1" aria-hidden="true" data-confirm-twin {{ $attributes->whereStartsWith('wire:') }}></button>
@else
    <button type="button" role="menuitem" {{ $attributes->class([$classes]) }} x-on:click="open = false">{{ $slot }}</button>
@endif
