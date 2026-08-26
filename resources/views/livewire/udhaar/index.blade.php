<?php

use App\Models\Customer;
use App\Models\UdhaarTransaction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Udhaar')] class extends Component
{
    public function with(): array
    {
        $balances = UdhaarTransaction::balancesByCustomer();

        $rows = Customer::query()
            ->whereIn('id', $balances->keys())
            ->orderBy('name')
            ->get()
            ->map(function (Customer $customer) use ($balances) {
                $balance = $balances[$customer->id] ?? 0.0;

                return [
                    'customer' => $customer,
                    'balance' => $balance,
                    'status' => match (true) {
                        $balance > 0 => 'due',
                        $balance < 0 => 'advance',
                        default => 'settled',
                    },
                ];
            })
            ->sortByDesc('balance')
            ->values();

        return [
            'rows' => $rows,
            'totalDue' => $rows->sum(fn ($row) => max($row['balance'], 0)),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Udhaar</h1>
    </x-slot>

    <div class="mb-6">
        <x-ui.stat label="Total Outstanding" value="Rs {{ number_format($totalDue, 2) }}" sub="Across all customers who currently owe money" />
    </div>

    @if ($rows->isEmpty())
        <x-ui.empty-state
            title="No udhaar activity yet"
            description="Once you record udhaar given or repayments against a customer, they'll show up here."
        >
            <x-slot name="action">
                <a href="{{ route('customers.index') }}" wire:navigate>
                    <x-ui.button>Go to Customers</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Customer', 'Phone', 'Balance', 'Status', '']">
            @foreach ($rows as $row)
                @php
                    $balance = $row['balance'];
                    $status = $row['status'];
                @endphp
                <x-ui.table-row wire:key="udhaar-customer-{{ $row['customer']->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $row['customer']->name }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $row['customer']->phone ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format(abs($balance), 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($status === 'due')
                            <x-ui.badge variant="warning">Due</x-ui.badge>
                        @elseif ($status === 'advance')
                            <x-ui.badge variant="brand">Advance</x-ui.badge>
                        @else
                            <x-ui.badge variant="success">Settled</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <a href="{{ route('udhaar.show', $row['customer']) }}" wire:navigate>
                            <x-ui.button size="sm" variant="ghost">View History</x-ui.button>
                        </a>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif
</div>
