<?php

use App\Models\Vendor;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Vendor Profile')] class extends Component
{
    public Vendor $vendor;

    public function mount(Vendor $vendor): void
    {
        $this->vendor = $vendor;
    }

    public function with(): array
    {
        $this->vendor->load('stockIns.product');

        return [
            'stockIns' => $this->vendor->stockIns,
            'totalUnits' => $this->vendor->stockIns->sum('quantity'),
            'totalCost' => $this->vendor->stockIns->sum('total_cost'),
            'totalOwed' => $this->vendor->totalOwed(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">{{ $vendor->name }}</h1>
            <a href="{{ route('vendors.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                ← All Vendors
            </a>
        </div>
    </x-slot>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Stock Entries" :value="number_format($stockIns->count())" />
        <x-ui.stat label="Total Units Purchased" :value="number_format($totalUnits)" />
        <x-ui.stat label="Total Purchase Cost" value="Rs {{ number_format($totalCost, 2) }}" />
        <x-ui.stat label="Total Owed to Vendor" value="Rs {{ number_format($totalOwed, 2) }}" />
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card title="Vendor Info" class="lg:col-span-1">
            <dl class="space-y-4 text-sm">
                <div>
                    <dt class="text-slate-500">Phone</dt>
                    <dd class="mt-0.5 font-medium text-slate-900">{{ $vendor->phone ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Company / Shop Name</dt>
                    <dd class="mt-0.5 font-medium text-slate-900">{{ $vendor->company_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Address</dt>
                    <dd class="mt-0.5 font-medium text-slate-900">{{ $vendor->address ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Notes</dt>
                    <dd class="mt-0.5 font-medium text-slate-900">{{ $vendor->notes ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Stock Entries" description="Every stock-in entry recorded from this vendor." class="lg:col-span-2">
            @if ($stockIns->isEmpty())
                <x-ui.empty-state
                    title="No stock entries yet"
                    description="Stock logged from this vendor on the Stock In screen will show up here."
                />
            @else
                <x-ui.table :headers="['Date', 'Product', 'Quantity', 'Cost', 'Payment', 'Owed', 'Note']">
                    @foreach ($stockIns as $entry)
                        <x-ui.table-row wire:key="vendor-stock-in-{{ $entry->id }}">
                            <x-ui.table-cell>{{ $entry->stock_date->format('d M Y') }}</x-ui.table-cell>
                            <x-ui.table-cell class="font-medium text-slate-900">{{ $entry->product_name }}</x-ui.table-cell>
                            <x-ui.table-cell class="font-semibold text-emerald-600">+{{ number_format($entry->quantity) }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $entry->total_cost !== null ? 'Rs '.number_format($entry->total_cost, 2) : '—' }}</x-ui.table-cell>
                            <x-ui.table-cell>
                                @if ($entry->payment_status->value === 'paid')
                                    <x-ui.badge variant="success">Paid</x-ui.badge>
                                @elseif ($entry->payment_status->value === 'partial')
                                    <x-ui.badge variant="warning">Partial</x-ui.badge>
                                @else
                                    <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                                @endif
                            </x-ui.table-cell>
                            <x-ui.table-cell class="font-semibold text-slate-900">
                                {{ $entry->amountOwed() > 0 ? 'Rs '.number_format($entry->amountOwed(), 2) : '—' }}
                            </x-ui.table-cell>
                            <x-ui.table-cell>{{ $entry->note ?? '—' }}</x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            @endif

            <div class="mt-4">
                <a href="{{ route('stock-ins.history', ['vendorFilter' => $vendor->id]) }}" wire:navigate>
                    <x-ui.button variant="secondary" class="w-full justify-center">
                        View in Stock In History (Mark as Paid / Add Payment)
                    </x-ui.button>
                </a>
            </div>
        </x-ui.card>
    </div>
</div>
