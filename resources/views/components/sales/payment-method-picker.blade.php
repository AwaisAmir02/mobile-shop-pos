@props([
    'accounts',
    'value',
    'amount',
    'credit' => 0,
])

{{-- Hidden while the amount field holds an explicit 0 — a zero payment never records a method. --}}
<div x-show="$wire.{{ $amount }} === '' || $wire.{{ $amount }} === null || parseFloat($wire.{{ $amount }}) !== 0">
    {{-- Keyed by the value so a server-side change (e.g. after "+ New account") re-renders the select's label. --}}
    <div wire:key="payment-method-{{ $value }}">
        <x-ui.field label="Paid by" name="paymentMethod" for="paymentMethod">
            <x-ui.select wire:model.live="paymentMethod" id="paymentMethod">
                <option value="">Not recorded</option>
                <option value="cash">Cash</option>
                @if ($credit > 0)
                    <option value="store_credit">Store credit · Rs {{ number_format($credit, 2) }} available</option>
                @endif
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }} · {{ $account->provider_type }}</option>
                @endforeach
                <option value="__create__">+ New account</option>
            </x-ui.select>
        </x-ui.field>
    </div>
</div>
