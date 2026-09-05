<?php

use App\Models\Customer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Customer Profile')] class extends Component
{
    public Customer $customer;

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
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">{{ $customer->name }}</h1>
            <a href="{{ route('customers.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                ← All Customers
            </a>
        </div>
    </x-slot>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Phone" :value="$customer->phone ?? '—'" />
        <x-ui.stat label="Address / Notes" :value="$customer->address ?? '—'" />
        <x-ui.stat label="Total Sales" value="Rs {{ number_format($totalSalesRevenue, 2) }}" :sub="$sales->count().' sale(s)'" />
        <x-ui.stat label="Sales Outstanding" value="Rs {{ number_format($salesOutstanding, 2) }}" sub="Separate from Udhaar" />
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card title="Sales History" description="Every sale linked to this customer.">
            @if ($sales->isEmpty())
                <x-ui.empty-state
                    title="No sales yet"
                    description="Sales linked to this customer from the POS screen will show up here."
                />
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($sales as $sale)
                        <a href="{{ route('sales.show', $sale) }}" wire:navigate class="flex items-center justify-between py-3 hover:bg-slate-50">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $sale->invoiceNumber() }}</p>
                                <p class="text-xs text-slate-400">{{ $sale->created_at->format('d M Y, h:i A') }}</p>
                            </div>
                            <span class="text-sm font-semibold text-slate-900">Rs {{ number_format($sale->total, 2) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Udhaar" description="This customer's credit balance — kept separate from sales.">
            <div class="flex items-center justify-between rounded-xl border border-slate-200 px-4 py-4">
                <div>
                    <p class="text-sm text-slate-500">Current Balance</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format(abs($udhaarBalance), 2) }}</p>
                </div>
                @if ($udhaarStatus === 'due')
                    <x-ui.badge variant="warning">Due</x-ui.badge>
                @elseif ($udhaarStatus === 'advance')
                    <x-ui.badge variant="brand">Advance</x-ui.badge>
                @else
                    <x-ui.badge variant="success">Settled</x-ui.badge>
                @endif
            </div>

            <div class="mt-4">
                <a href="{{ route('udhaar.show', $customer) }}" wire:navigate>
                    <x-ui.button variant="secondary" class="w-full justify-center">
                        View Full Udhaar History
                    </x-ui.button>
                </a>
            </div>
        </x-ui.card>
    </div>
</div>
