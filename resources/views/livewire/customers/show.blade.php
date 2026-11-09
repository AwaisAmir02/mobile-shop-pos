<?php

use App\Models\Customer;
use App\ReportQueries\UdhaarReport;
use App\Support\BranchContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Customer Profile')] class extends Component
{
    public Customer $customer;

    /**
     * Customers and vendors belong to the whole shop, so this screen shows
     * every branch's lines and balances to everyone, restricted staff
     * included. boot() runs on every Livewire request, not just the first.
     */
    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(Customer $customer): void
    {
        $this->customer = $customer;
    }

    public function with(): array
    {
        $this->customer->load(['sales', 'udhaarTransactions']);

        return [
            'sales' => $this->customer->sales,
            'totalSalesRevenue' => $this->customer->sales->sum('total'),
            'salesOutstanding' => $this->customer->salesOutstandingBalance(),
            'udhaarBalance' => $this->customer->udhaarBalance(),
            'udhaarStatus' => $this->customer->udhaarStatus(),
            // Everything they owe, exactly as the Udhaar list and the Customers
            // list's Balance column show it (loans plus unpaid sales and counter
            // services). Read from the same report, so the screens can't disagree.
            'totalOwed' => (new UdhaarReport)->rows()->first(fn (array $row) => $row['customer']->id === $this->customer->id)['balance'] ?? 0.0,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header
            back="{{ route('customers.index') }}"
            back-label="Customers"
            :subtitle="collect([$customer->phone, $customer->address])->filter()->implode(' · ') ?: null"
        >{{ $customer->name }}</x-ui.page-header>
    </x-slot>

    <x-party.tabs type="customer" :party="$customer" active="profile" />

    <x-ui.stat-row>
        <x-ui.stat label="Total Owed" value="Rs {{ number_format(abs($totalOwed), 2) }}" :sub="$totalOwed < 0 ? 'In advance · as on the Udhaar list' : 'Udhaar, sales and services · as on the Udhaar list'" />
        <x-ui.stat label="Sales Outstanding" value="Rs {{ number_format($salesOutstanding, 2) }}" sub="Unpaid part of their sales" />
        <x-ui.stat label="Udhaar Loans" value="Rs {{ number_format(abs($udhaarBalance), 2) }}" :sub="match ($udhaarStatus) { 'due' => 'Due · given as udhaar', 'advance' => 'Advance · paid ahead', default => 'Settled' }" />
        <x-ui.stat label="Total Sales" value="Rs {{ number_format($totalSalesRevenue, 2) }}" :sub="$sales->count().' sale(s)'" />
    </x-ui.stat-row>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card title="Sales History" description="Every sale linked to this customer.">
            @if ($sales->isEmpty())
                <x-ui.empty-state
                    title="No sales yet"
                    description="Sales linked to this customer from the POS screen will show up here."
                />
            @else
                <div class="-mx-2 divide-y divide-line">
                    @foreach ($sales as $sale)
                        <a href="{{ route('sales.show', $sale) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg px-2 py-3 transition-colors hover:bg-surface-sunken">
                            <div>
                                <p class="text-sm font-semibold text-ink">{{ $sale->invoiceNumber() }}</p>
                                <p class="text-xs text-ink-faint">{{ $sale->created_at->format('d M Y, h:i A') }}</p>
                            </div>
                            <span class="text-sm font-semibold tabular-nums text-ink">Rs {{ number_format($sale->total, 2) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Udhaar Loans" description="Money given or received as udhaar, kept separate from sales.">
            <div class="flex items-center justify-between rounded-xl border border-line px-4 py-4">
                <div>
                    <p class="text-sm text-ink-faint">Loans balance</p>
                    <p class="text-display-sm tabular-nums text-ink">Rs {{ number_format(abs($udhaarBalance), 2) }}</p>
                </div>
                @if ($udhaarStatus === 'due')
                    <x-ui.badge variant="warning">Due</x-ui.badge>
                @elseif ($udhaarStatus === 'advance')
                    <x-ui.badge variant="brand">Advance</x-ui.badge>
                @else
                    <x-ui.badge variant="success">Settled</x-ui.badge>
                @endif
            </div>

            @if (auth()->user()->hasAccessTo('udhaar'))
                <div class="mt-4">
                    <x-ui.button href="{{ route('udhaar.show', $customer) }}" wire:navigate variant="secondary" class="w-full justify-center">
                        Open their Udhaar
                    </x-ui.button>
                </div>
            @endif
        </x-ui.card>
    </div>
</div>
