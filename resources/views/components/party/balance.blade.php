@props([
    // A balance as the screen already worked it out. Positive: they owe the shop
    // (customers) or the shop owes them (vendors, with direction="owed").
    'amount' => 0,
    // 'customer': positive is Due, negative is Advance. 'vendor': positive is Owed.
    'direction' => 'customer',
])

{{-- One way to show a party's balance in lists and headers. Display only. --}}
@php
    $amount = (float) $amount;
@endphp

<span {{ $attributes->class(['inline-flex flex-wrap items-center gap-1.5']) }}>
    @if ($amount > 0)
        <span class="font-semibold tabular-nums text-ink">Rs {{ number_format($amount, 2) }}</span>
        <x-ui.badge variant="warning">{{ $direction === 'vendor' ? 'Owed' : 'Due' }}</x-ui.badge>
    @elseif ($amount < 0)
        <span class="font-semibold tabular-nums text-ink">Rs {{ number_format(abs($amount), 2) }}</span>
        <x-ui.badge variant="brand">Advance</x-ui.badge>
    @else
        <span class="text-ink-faint">Settled</span>
    @endif
</span>
