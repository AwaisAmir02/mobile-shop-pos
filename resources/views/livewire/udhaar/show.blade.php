<?php

use App\Actions\CreateUdhaarTransaction;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\UdhaarTransaction;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Udhaar History')] class extends Component
{
    use Toasts;

    public Customer $customer;

    public string $type = 'given';
    public string $amount = '';
    public string $transaction_date = '';
    public string $note = '';

    public function mount(Customer $customer): void
    {
        $this->customer = $customer;
        $this->transaction_date = now()->toDateString();
    }

    public function with(): array
    {
        $this->customer->load(['udhaarTransactions.user', 'udhaarTransactions.reverses']);

        $running = 0.0;
        $rows = $this->customer->udhaarTransactions->map(function (UdhaarTransaction $transaction) use (&$running) {
            $running += $transaction->signedAmount();

            return [
                'transaction' => $transaction,
                'runningBalance' => $running,
            ];
        })->reverse()->values();

        return [
            'rows' => $rows,
            'balance' => $running,
            'status' => match (true) {
                $running > 0 => 'due',
                $running < 0 => 'advance',
                default => 'settled',
            },
        ];
    }

    public function openAddTransaction(): void
    {
        $this->reset(['amount', 'note']);
        $this->type = 'given';
        $this->transaction_date = now()->toDateString();
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'transaction-form');
    }

    public function save(): void
    {
        $this->validate(CreateUdhaarTransaction::rules());

        CreateUdhaarTransaction::handle($this->customer, $this->type, $this->amount, $this->transaction_date, $this->note);

        $this->toastSuccess('Transaction recorded.');
        $this->dispatch('close-modal', name: 'transaction-form');
        $this->reset(['amount', 'note']);
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'transaction-form');
    }

    public function reverse(int $transactionId): void
    {
        $original = UdhaarTransaction::where('customer_id', $this->customer->id)->findOrFail($transactionId);

        if ($original->type->isReversal()) {
            $this->toastError('A reversal entry cannot itself be reversed.');

            return;
        }

        if ($original->isReversed()) {
            $this->toastError('This transaction has already been reversed.');

            return;
        }

        UdhaarTransaction::create([
            'customer_id' => $this->customer->id,
            'user_id' => Auth::id(),
            'type' => $original->type->reversalTypeFor(),
            'amount' => $original->amount,
            'transaction_date' => now()->toDateString(),
            'note' => 'Correction of transaction #'.$original->id,
            'reverses_transaction_id' => $original->id,
        ]);

        $this->toastSuccess('Transaction reversed.');
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">{{ $customer->name }} — Udhaar History</h1>
            <a href="{{ route('udhaar.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                ← All Customers
            </a>
        </div>
    </x-slot>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat label="Current Balance" value="Rs {{ number_format(abs($balance), 2) }}" />
        <x-ui.stat label="Status" :value="ucfirst($status)" />
        <div class="flex items-center justify-end">
            <x-ui.button wire:click="openAddTransaction">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Add Transaction
            </x-ui.button>
        </div>
    </div>

    @if ($rows->isEmpty())
        <x-ui.empty-state
            title="No transactions yet"
            description="Record udhaar given or a repayment to start this customer's history."
        >
            <x-slot name="action">
                <x-ui.button wire:click="openAddTransaction">Add Transaction</x-ui.button>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Date', 'Type', 'Amount', 'Running Balance', 'Note', 'Entered By', '']">
            @foreach ($rows as $row)
                @php $transaction = $row['transaction']; @endphp
                <x-ui.table-row wire:key="udhaar-txn-{{ $transaction->id }}">
                    <x-ui.table-cell>{{ $transaction->transaction_date->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        <div class="flex flex-col">
                            <span class="text-slate-700">{{ $transaction->type->label() }}</span>
                            @if ($transaction->reverses_transaction_id)
                                <span class="text-xs text-slate-400">Reverses #{{ $transaction->reverses_transaction_id }}</span>
                            @endif
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold {{ $transaction->type->sign() > 0 ? 'text-red-600' : 'text-emerald-600' }}">
                        {{ $transaction->type->sign() > 0 ? '+' : '−' }} Rs {{ number_format($transaction->amount, 2) }}
                    </x-ui.table-cell>
                    <x-ui.table-cell class="font-medium text-slate-900">
                        Rs {{ number_format(abs($row['runningBalance']), 2) }}
                        {{ $row['runningBalance'] < 0 ? '(advance)' : '' }}
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $transaction->note ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $transaction->user?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        @if (! $transaction->type->isReversal() && ! $transaction->isReversed())
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                wire:click="reverse({{ $transaction->id }})"
                                wire:confirm="Reverse this {{ strtolower($transaction->type->label()) }} of Rs {{ number_format($transaction->amount, 2) }}? This creates an offsetting entry — nothing is edited or deleted."
                                class="text-red-600 hover:bg-red-50"
                            >
                                Reverse
                            </x-ui.button>
                        @endif
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif

    <x-ui.modal name="transaction-form" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Add Transaction</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Type" name="type" for="type">
                    <x-ui.select wire:model="type" id="type">
                        <option value="given">Udhaar Given</option>
                        <option value="repayment">Repayment</option>
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Amount" name="amount" for="amount">
                    <x-ui.input wire:model="amount" id="amount" type="number" min="0.01" step="0.01" class="text-lg" autofocus />
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
</div>
