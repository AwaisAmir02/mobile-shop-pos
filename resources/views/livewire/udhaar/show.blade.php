<?php

use App\Actions\CreateUdhaarTransaction;
use App\Actions\RecordModulePayment;
use App\Actions\RecordSalePayment;
use App\Livewire\Concerns\Toasts;
use App\Models\BalanceLoad;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\NadraVerification;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\SimSale;
use App\Models\UdhaarTransaction;
use App\Models\WalletLoad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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

    public string $settlingSource = '';
    public ?int $settlingId = null;
    public string $dueSettleAmount = '';

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
            'dues' => $this->duesForCustomer(),
        ];
    }

    /**
     * Everything else this customer still owes across other modules — a
     * consolidated CHECKLIST, not a merged balance. Each row stays
     * individually labeled and individually payable, and this list is
     * deliberately kept separate from the Udhaar ledger above: it never
     * feeds into $balance/$status, and settling a row here only ever
     * updates that row's own source record, never anything Udhaar-related.
     *
     * Wallet Load Cash Out is intentionally excluded — its "owed" amount
     * means the shop still owes the customer cash, the opposite direction
     * from every other source here, which all represent the customer
     * owing the shop.
     */
    protected function duesForCustomer(): Collection
    {
        $customerId = $this->customer->id;
        $rows = collect();

        Sale::where('customer_id', $customerId)->withSum('payments', 'amount')->get()
            ->each(function (Sale $sale) use ($rows) {
                if ($sale->amountDue() > 0) {
                    $rows->push([
                        'source' => 'sale',
                        'sourceLabel' => 'Sale',
                        'id' => $sale->id,
                        'description' => $sale->invoiceNumber(),
                        'date' => $sale->created_at,
                        'amountDue' => $sale->amountDue(),
                    ]);
                }
            });

        WalletLoad::where('customer_id', $customerId)->where('direction', 'cash_in')->get()
            ->each(function (WalletLoad $load) use ($rows) {
                if ($load->amountOwed() > 0) {
                    $rows->push([
                        'source' => 'wallet_load',
                        'sourceLabel' => 'Wallet Load',
                        'id' => $load->id,
                        'description' => $load->receiptNumber().' · '.$load->provider,
                        'date' => $load->created_at,
                        'amountDue' => $load->amountOwed(),
                    ]);
                }
            });

        BalanceLoad::where('customer_id', $customerId)->get()
            ->each(function (BalanceLoad $load) use ($rows) {
                if ($load->amountOwed() > 0) {
                    $rows->push([
                        'source' => 'balance_load',
                        'sourceLabel' => 'Balance Load',
                        'id' => $load->id,
                        'description' => $load->receiptNumber().' · '.$load->network,
                        'date' => $load->created_at,
                        'amountDue' => $load->amountOwed(),
                    ]);
                }
            });

        BillPayment::where('customer_id', $customerId)->with('billCategory')->get()
            ->each(function (BillPayment $payment) use ($rows) {
                if ($payment->amountOwed() > 0) {
                    $rows->push([
                        'source' => 'bill_payment',
                        'sourceLabel' => 'Bill',
                        'id' => $payment->id,
                        'description' => $payment->receiptNumber().' · '.($payment->billCategory?->name ?? $payment->consumer_name),
                        'date' => $payment->created_at,
                        'amountDue' => $payment->amountOwed(),
                    ]);
                }
            });

        Repair::where('customer_id', $customerId)->get()
            ->each(function (Repair $repair) use ($rows) {
                if ($repair->amountOwed() > 0) {
                    $rows->push([
                        'source' => 'repair',
                        'sourceLabel' => 'Repair',
                        'id' => $repair->id,
                        'description' => $repair->receiptNumber().' · '.$repair->categoryLabel(),
                        'date' => $repair->created_at,
                        'amountDue' => $repair->amountOwed(),
                    ]);
                }
            });

        NadraVerification::where('customer_id', $customerId)->get()
            ->each(function (NadraVerification $verification) use ($rows) {
                if ($verification->amountOwed() > 0) {
                    $rows->push([
                        'source' => 'nadra_verification',
                        'sourceLabel' => 'NADRA Verification',
                        'id' => $verification->id,
                        'description' => $verification->receiptNumber(),
                        'date' => $verification->created_at,
                        'amountDue' => $verification->amountOwed(),
                    ]);
                }
            });

        SimSale::where('customer_id', $customerId)->get()
            ->each(function (SimSale $simSale) use ($rows) {
                if ($simSale->amountOwed() > 0) {
                    $rows->push([
                        'source' => 'sim_sale',
                        'sourceLabel' => 'SIM Sale',
                        'id' => $simSale->id,
                        'description' => $simSale->receiptNumber().' · '.$simSale->network,
                        'date' => $simSale->created_at,
                        'amountDue' => $simSale->amountOwed(),
                    ]);
                }
            });

        return $rows->sortByDesc('date')->values();
    }

    protected function dueRecordFor(string $source, int $id): Model
    {
        $model = match ($source) {
            'sale' => Sale::findOrFail($id),
            'wallet_load' => WalletLoad::findOrFail($id),
            'balance_load' => BalanceLoad::findOrFail($id),
            'bill_payment' => BillPayment::findOrFail($id),
            'repair' => Repair::findOrFail($id),
            'nadra_verification' => NadraVerification::findOrFail($id),
            'sim_sale' => SimSale::findOrFail($id),
            default => abort(404),
        };

        // A tampered source/id pair must never settle another customer's
        // balance — every source here carries a customer_id to check.
        abort_unless((int) $model->customer_id === $this->customer->id, 403);

        return $model;
    }

    public function openSettleDue(string $source, int $id): void
    {
        $this->settlingSource = $source;
        $this->settlingId = $id;
        $this->dueSettleAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'settle-due-form');
    }

    public function submitDueSettlement(): void
    {
        $record = $this->dueRecordFor($this->settlingSource, $this->settlingId);
        $amountDue = $this->settlingSource === 'sale' ? $record->amountDue() : $record->amountOwed();

        $this->validate([
            'dueSettleAmount' => ['required', 'numeric', 'min:0.01', 'max:'.$amountDue],
        ]);

        if ($this->settlingSource === 'sale') {
            RecordSalePayment::handle($record, (float) $this->dueSettleAmount, Auth::id());
        } else {
            RecordModulePayment::handle($record, (float) $this->dueSettleAmount);
        }

        $this->toastSuccess('Payment recorded.');
        $this->dispatch('close-modal', name: 'settle-due-form');
        $this->reset(['settlingSource', 'settlingId', 'dueSettleAmount']);
    }

    public function closeDueSettlement(): void
    {
        $this->dispatch('close-modal', name: 'settle-due-form');
        $this->reset(['settlingSource', 'settlingId', 'dueSettleAmount']);
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

    @if ($dues->isNotEmpty())
        <div class="mt-8">
            <x-ui.card
                title="Other Amounts Owed"
                description="Unsettled balances from Sales, Wallet Loads, Balance Loads, Bills, Repairs, NADRA Verifications, and SIM Sales — separate from the Udhaar loan balance above. Each item is its own transaction, settled on its own."
            >
                <x-ui.table :headers="['Source', 'Description', 'Date', 'Amount Due', '']">
                    @foreach ($dues as $due)
                        <x-ui.table-row wire:key="due-{{ $due['source'] }}-{{ $due['id'] }}">
                            <x-ui.table-cell>
                                <x-ui.badge variant="neutral">{{ $due['sourceLabel'] }}</x-ui.badge>
                            </x-ui.table-cell>
                            <x-ui.table-cell>{{ $due['description'] }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $due['date']->format('d M Y') }}</x-ui.table-cell>
                            <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($due['amountDue'], 2) }}</x-ui.table-cell>
                            <x-ui.table-cell align="right">
                                <x-ui.button size="sm" variant="ghost" wire:click="openSettleDue('{{ $due['source'] }}', {{ $due['id'] }})">
                                    Record Payment
                                </x-ui.button>
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        </div>
    @endif

    <x-ui.modal name="settle-due-form" max-width="sm">
        <form wire:submit="submitDueSettlement" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Record Payment</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Amount Paid Now" name="dueSettleAmount" for="dueSettleAmount">
                    <x-ui.input wire:model="dueSettleAmount" id="dueSettleAmount" type="number" min="0.01" step="0.01" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeDueSettlement">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submitDueSettlement">
                    Save Payment
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

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
