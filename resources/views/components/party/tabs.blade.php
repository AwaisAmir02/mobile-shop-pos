@props([
    // 'customer' or 'vendor'
    'type',
    // The Customer or Vendor model
    'party',
    // Which tab this page is: 'profile', 'udhaar' or 'statement'
    'active',
])

{{--
    One person's screens as tabs: their profile, their Udhaar (customers only)
    and their Party Ledger statement. Every URL is the screen's own existing one,
    and each tab shows only when the user can already open that screen
    (User::hasAccessTo, the same check as the routes' shop.access middleware).
    Hiding a tab is never the protection; the routes keep their guards.
--}}
@php
    $user = auth()->user();

    $tabs = $type === 'vendor'
        ? [
            ['key' => 'profile', 'label' => 'Profile', 'href' => route('vendors.show', $party), 'show' => $user->hasAccessTo('vendors')],
            ['key' => 'statement', 'label' => 'Statement', 'href' => route('party-ledger.index', ['party' => 'vendor', 'vendor' => $party->id]), 'show' => $user->hasAccessTo('party-ledger') && $user->hasAccessTo('vendors')],
        ]
        : [
            ['key' => 'profile', 'label' => 'Profile', 'href' => route('customers.show', $party), 'show' => $user->hasAccessTo('customers')],
            ['key' => 'udhaar', 'label' => 'Udhaar', 'href' => route('udhaar.show', $party), 'show' => $user->hasAccessTo('udhaar')],
            ['key' => 'statement', 'label' => 'Statement', 'href' => route('party-ledger.index', ['party' => 'customer', 'customer' => $party->id]), 'show' => $user->hasAccessTo('party-ledger')],
        ];

    $tabs = array_values(array_filter($tabs, fn (array $tab) => $tab['show']));
@endphp

@if (count($tabs) > 1)
    <nav {{ $attributes->class(['-mt-2 mb-6 flex gap-1 overflow-x-auto border-b border-line']) }} aria-label="{{ $party->name }}" data-party-tabs>
        @foreach ($tabs as $tab)
            <a
                href="{{ $tab['href'] }}"
                wire:navigate
                @if ($tab['key'] === $active) aria-current="page" @endif
                @class([
                    'relative shrink-0 whitespace-nowrap px-3.5 pb-3 pt-2 text-sm font-semibold transition-colors focus-visible:rounded-lg',
                    'text-on-primary-soft after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-full after:bg-primary' => $tab['key'] === $active,
                    'text-ink-faint hover:text-ink' => $tab['key'] !== $active,
                ])
            >{{ $tab['label'] }}</a>
        @endforeach
    </nav>
@endif
