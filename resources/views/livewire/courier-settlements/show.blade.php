<?php

use App\Actions\CourierSettlements;
use App\Livewire\Concerns\Toasts;
use App\Models\CourierSettlement;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * One courier payout (O4): its parcels and deductions. Cancelling it undoes
 * the payments it made on their sales, so the courier owes that cash again.
 */
new #[Layout('layouts.app')] #[Title('Courier Payout')] class extends Component
{
    use Toasts;

    public CourierSettlement $settlement;

    public function cancel(): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales-edit-cancel'), 403);

        CourierSettlements::cancel($this->settlement, Auth::id());

        $this->settlement->refresh();
        $this->toastSuccess('Payout cancelled. Its parcels are waiting for a payout again.');
    }

    public function with(): array
    {
        return [
            'items' => $this->settlement->items()->with(['shipment', 'sale'])->get(),
            'canCancel' => ! $this->settlement->isCancelled() && Auth::user()->hasAccessTo('sales-edit-cancel'),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('courier-settlements.index') }}" back-label="Courier Payouts" :subtitle="$settlement->courier->label().' · '.$settlement->settlement_date->format('d M Y').($settlement->reference ? ' · '.$settlement->reference : '')">
            Payout {{ $settlement->number() }}
        </x-ui.page-header>
    </x-slot>

    @if ($settlement->isCancelled())
        <x-ui.alert variant="warning" class="mb-4">Cancelled{{ $settlement->cancelled_at ? ' on '.$settlement->cancelled_at->format('d M Y') : '' }}. Its parcels are waiting for a payout again.</x-ui.alert>
    @endif

    <x-ui.stat-row>
        <x-ui.stat label="Cash Collected" value="Rs {{ number_format($settlement->cod_total, 2) }}" />
        <x-ui.stat label="Deductions" value="Rs {{ number_format($settlement->deductions(), 2) }}" sub="Charges Rs {{ number_format($settlement->charges_total, 2) }} · tax Rs {{ number_format($settlement->tax_withheld, 2) }} · other Rs {{ number_format($settlement->other_deductions, 2) }}" />
        <x-ui.stat label="Paid to You" value="Rs {{ number_format($settlement->net_amount, 2) }}" :sub="$settlement->payment_method === 'cash' ? 'Cash' : ($settlement->shopAccount?->name ?? 'Not recorded')" />
    </x-ui.stat-row>

    <x-ui.table stack :headers="['Parcel', 'Sale', 'Cash collected', 'Delivery charge', 'Return charge']">
        @foreach ($items as $item)
            <x-ui.table-row wire:key="item-{{ $item->id }}">
                <x-ui.table-cell primary><span class="font-semibold text-ink">{{ $item->shipment?->tracking_number }}</span><span class="block text-xs text-ink-faint">{{ $item->shipment?->status->label() }}</span></x-ui.table-cell>
                <x-ui.table-cell label="Sale">
                    @if ($item->sale)
                        <a href="{{ route('sales.show', $item->sale) }}" wire:navigate class="text-link hover:underline">{{ $item->sale->invoiceNumber() }}</a>
                    @endif
                </x-ui.table-cell>
                <x-ui.table-cell label="Cash collected" class="tabular-nums">Rs {{ number_format($item->cod_collected, 2) }}</x-ui.table-cell>
                <x-ui.table-cell label="Delivery charge" class="tabular-nums">Rs {{ number_format($item->courier_charge, 2) }}</x-ui.table-cell>
                <x-ui.table-cell label="Return charge" class="tabular-nums">Rs {{ number_format($item->return_charge, 2) }}</x-ui.table-cell>
            </x-ui.table-row>
        @endforeach
    </x-ui.table>

    @if ($settlement->note)
        <p class="mt-4 text-sm text-ink-muted">{{ $settlement->note }}</p>
    @endif

    @if ($canCancel)
        <div class="mt-6 flex justify-end">
            <x-ui.button variant="danger" wire:click="cancel" wire:confirm="Cancel this payout? The cash it paid on each sale is taken off again and the courier owes it once more." data-cancel-payout>Cancel Payout</x-ui.button>
        </div>
    @endif
</div>
