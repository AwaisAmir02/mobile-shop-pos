<?php

use App\Actions\CreateUdhaarTransaction;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\UdhaarTransaction;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Udhaar')] class extends Component
{
    use Toasts;

    public string $customerId = '';
    public string $type = 'given';
    public string $amount = '';
    public string $transaction_date = '';
    public string $note = '';

    public function mount(): void
    {
        $this->transaction_date = now()->toDateString();
    }

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
            'customers' => Customer::query()->orderBy('name')->get(),
        ];
    }

    public function updatedCustomerId(): void
    {
        if ($this->customerId === '__create__') {
            $this->customerId = '';
            $this->dispatch('open-modal', name: 'quick-create-customer');
        }
    }

    #[On('customer-created')]
    public function onCustomerCreated(int $customerId): void
    {
        $this->customerId = (string) $customerId;
    }

    public function openAddTransaction(): void
    {
        $this->reset(['customerId', 'amount', 'note']);
        $this->type = 'given';
        $this->transaction_date = now()->toDateString();
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'quick-transaction-form');
    }

    public function save(): void
    {
        $this->validate([
            'customerId' => ['required', 'integer', Rule::exists('customers', 'id')->where('shop_id', auth()->user()->shop_id)],
            ...CreateUdhaarTransaction::rules(),
        ]);

        $customer = Customer::findOrFail($this->customerId);

        CreateUdhaarTransaction::handle($customer, $this->type, $this->amount, $this->transaction_date, $this->note);

        $this->toastSuccess('Transaction recorded.');
        $this->dispatch('close-modal', name: 'quick-transaction-form');
        $this->reset(['customerId', 'amount', 'note']);
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'quick-transaction-form');
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Udhaar</h1>
            <x-ui.button wire:click="openAddTransaction">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Add Transaction
            </x-ui.button>
        </div>
    </x-slot>

    <div class="mb-6">
        <x-ui.stat label="Total Outstanding" value="Rs {{ number_format($totalDue, 2) }}" sub="Across all customers who currently owe money" />
    </div>

    @if ($rows->isEmpty())
        <x-ui.empty-state
            title="No udhaar activity yet"
            description="Record udhaar given or a repayment above to start a customer's history."
        >
            <x-slot name="action">
                <x-ui.button wire:click="openAddTransaction">Add Transaction</x-ui.button>
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

    <x-ui.modal name="quick-transaction-form" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Add Transaction</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Customer" name="customerId" for="customerId">
                    <x-ui.select wire:model.live="customerId" id="customerId">
                        <option value="">Select a customer</option>
                        <option value="__create__">+ New Customer</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Type" name="type" for="type">
                    <x-ui.select wire:model="type" id="type">
                        <option value="given">Udhaar Given</option>
                        <option value="repayment">Repayment</option>
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Amount" name="amount" for="amount">
                    <x-ui.input wire:model="amount" id="amount" type="number" min="0.01" step="0.01" class="text-lg" />
                </x-ui.field>

                <x-ui.field label="Date" name="transaction_date" for="transaction_date">
                    <x-ui.input wire:model="transaction_date" id="transaction_date" type="date" />
                </x-ui.field>

                <x-ui.field label="Note" name="note" for="note" help="Optional">
                    <x-ui.input wire:model="note" id="note" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    Save
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <livewire:customers.quick-create />
</div>
