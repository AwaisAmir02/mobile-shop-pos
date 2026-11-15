@props(['saleId' => null])

{{-- A sale's courier parcel: its status if booked, else a Book courier button. Shown only to shops with courier booking. --}}
@php
    $user = auth()->user();
    $shipment = $saleId && $user?->shop?->couriers_enabled
        ? \App\Models\Shipment::where('sale_id', $saleId)->where('status', '!=', \App\Enums\ShipmentStatus::Cancelled->value)->latest('id')->first()
        : null;
@endphp

@if ($saleId && $user?->shop?->couriers_enabled && $user->hasAccessTo('sales'))
    <div {{ $attributes->class(['flex flex-wrap items-center justify-between gap-2']) }} data-order-courier>
        @if ($shipment)
            <span class="text-sm text-ink-muted">
                {{ $shipment->courier->label() }} ·
                <a href="{{ route('shipments.index', ['focus' => $shipment->id, 'show' => 'all']) }}" wire:navigate class="font-semibold tabular-nums text-link hover:underline">{{ $shipment->tracking_number }}</a>
            </span>
            <x-ui.badge :variant="$shipment->status->variant()">{{ $shipment->status->label() }}</x-ui.badge>
        @else
            <span class="text-sm text-ink-faint">Not sent by courier yet</span>
            <x-ui.button size="sm" variant="secondary" href="{{ route('shipments.book', $saleId) }}" wire:navigate>Book courier</x-ui.button>
        @endif
    </div>
@endif
