<?php

use App\Models\Vendor;
use App\Support\BranchContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Vendor Profile')] class extends Component
{
    public Vendor $vendor;

    /**
     * Customers and vendors belong to the whole shop, so this screen shows
     * every branch's lines and balances to everyone, restricted staff
     * included. boot() runs on every Livewire request, not just the first.
     */
    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(Vendor $vendor): void
    {
        $this->vendor = $vendor;
    }

    public function with(): array
    {
        $this->vendor->load('stockIns.product');

        return [
            'stockIns' => $this->vendor->stockIns,
            'totalUnits' => \App\Support\Quantity::sum($this->vendor->stockIns->filter(fn ($entry) => ! $entry->unit->allowsDecimals())->pluck('quantity')),
            'measuredTotals' => \App\Enums\QuantityUnit::summarize($this->vendor->stockIns->filter(fn ($entry) => $entry->unit->allowsDecimals())->groupBy(fn ($entry) => $entry->unit->value)->map(fn ($entries) => \App\Support\Quantity::sum($entries->pluck('quantity')))->all()),
            'totalCost' => $this->vendor->stockIns->sum('total_cost'),
            'totalOwed' => $this->vendor->totalOwed(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header
            back="{{ route('vendors.index') }}"
            back-label="Vendors"
            :subtitle="collect([$vendor->company_name, $vendor->phone])->filter()->implode(' · ') ?: null"
        >{{ $vendor->name }}</x-ui.page-header>
    </x-slot>

    <x-party.tabs type="vendor" :party="$vendor" active="profile" />

    <x-ui.stat-row>
        <x-ui.stat label="Total Owed to Vendor" value="Rs {{ number_format($totalOwed, 2) }}" />
        <x-ui.stat label="Total Purchase Cost" value="Rs {{ number_format($totalCost, 2) }}" />
        <x-ui.stat label="Stock Entries" :value="number_format($stockIns->count())" />
        <x-ui.stat label="Total Units Purchased" :value="number_format($totalUnits)" />
        @if ($measuredTotals !== '')
            <x-ui.stat label="Weight, Volume & Length" :value="$measuredTotals" />
        @endif
    </x-ui.stat-row>

    <x-ui.card class="mb-6">
        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-ink-faint">Phone</dt>
                <dd class="mt-0.5 font-medium text-ink">{{ $vendor->phone ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-ink-faint">Company / Shop Name</dt>
                <dd class="mt-0.5 font-medium text-ink">{{ $vendor->company_name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-ink-faint">Address</dt>
                <dd class="mt-0.5 font-medium text-ink">{{ $vendor->address ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-ink-faint">Notes</dt>
                <dd class="mt-0.5 font-medium text-ink">{{ $vendor->notes ?? '—' }}</dd>
            </div>
        </dl>
    </x-ui.card>

    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-base font-bold tracking-tight text-ink">Stock Entries</h2>
            <p class="text-sm text-ink-faint">Every stock-in entry recorded from this vendor.</p>
        </div>
        @if (auth()->user()->hasAccessTo('stock-ins'))
            <x-ui.button href="{{ route('stock-ins.history', ['vendorFilter' => $vendor->id]) }}" wire:navigate variant="secondary" size="sm">
                Record payments in Stock In
            </x-ui.button>
        @endif
    </div>

    @if ($stockIns->isEmpty())
        <x-ui.empty-state
            title="No stock entries yet"
            description="Stock logged from this vendor on the Stock In screen will show up here."
        />
    @else
        <x-ui.table stack :headers="['Product', 'Date', 'Quantity', 'Cost', 'Payment', 'Owed', 'Note']">
            @foreach ($stockIns as $entry)
                <x-ui.table-row wire:key="vendor-stock-in-{{ $entry->id }}">
                    <x-ui.table-cell primary class="font-semibold text-ink">{{ $entry->product_name }}</x-ui.table-cell>
                    <x-ui.table-cell label="Date">{{ $entry->stock_date->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell label="Quantity" class="font-semibold text-success">+{{ $entry->unit->groupedAmount($entry->quantity) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Cost">{{ $entry->total_cost !== null ? 'Rs '.number_format($entry->total_cost, 2) : '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Payment">
                        @if ($entry->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($entry->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Owed" class="font-semibold text-ink">
                        {{ $entry->amountOwed() > 0 ? 'Rs '.number_format($entry->amountOwed(), 2) : '—' }}
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Note" class="max-w-[14rem] truncate" title="{{ $entry->note }}">{{ $entry->note ?? '—' }}</x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif
</div>
