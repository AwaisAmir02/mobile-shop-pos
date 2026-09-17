<?php

use App\Actions\CreateUdhaarTransaction;
use App\Enums\PaymentStatus;
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
use App\Services\TableExportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Customer IDs with an outstanding item in any of the non-Udhaar
     * sources also surfaced on each customer's own consolidated "Other
     * Amounts Owed" view. A customer who never took an actual Udhaar loan
     * but has, say, a partial Sale must still be reachable from this list
     * — otherwise the shop has no way to find their way to the page where
     * that balance can actually be settled.
     *
     * Wallet Load Cash Out is intentionally excluded — its "owed" amount
     * means the shop owes the customer, not the other way around, matching
     * the same exclusion already enforced in the per-customer view.
     *
     * `payment_status != 'paid'` is used instead of loading every record
     * and calling amountOwed(): Unpaid rows always have amount_paid = 0
     * with a positive total, and Partial rows always have amount_paid <
     * total by construction (the settle actions flip to Paid as soon as
     * amount_paid reaches total), so the two are equivalent here.
     */
    protected function otherDueCustomerIds(): Collection
    {
        $unpaidOrPartial = fn ($query) => $query
            ->whereNotNull('customer_id')
            ->where('payment_status', '!=', PaymentStatus::Paid->value);

        return collect()
            ->merge($unpaidOrPartial(WalletLoad::query()->where('direction', 'cash_in'))->pluck('customer_id'))
            ->merge($unpaidOrPartial(BalanceLoad::query())->pluck('customer_id'))
            ->merge($unpaidOrPartial(BillPayment::query())->pluck('customer_id'))
            ->merge($unpaidOrPartial(Repair::query())->pluck('customer_id'))
            ->merge($unpaidOrPartial(NadraVerification::query())->pluck('customer_id'))
            ->merge($unpaidOrPartial(SimSale::query())->pluck('customer_id'))
            ->merge(
                Sale::query()
                    ->whereNotNull('customer_id')
                    ->withSum('payments', 'amount')
                    ->get()
                    ->filter(fn (Sale $sale) => $sale->amountDue() > 0)
                    ->pluck('customer_id')
            )
            ->unique()
            ->values();
    }

    protected function buildRows(): Collection
    {
        $balances = UdhaarTransaction::balancesByCustomer();
        $otherDueCustomerIds = $this->otherDueCustomerIds();
        $customerIds = $balances->keys()->merge($otherDueCustomerIds)->unique();

        return Customer::query()
            ->whereIn('id', $customerIds)
            ->orderBy('name')
            ->get()
            ->map(function (Customer $customer) use ($balances, $otherDueCustomerIds) {
                $balance = $balances[$customer->id] ?? 0.0;

                return [
                    'customer' => $customer,
                    'balance' => $balance,
                    'status' => match (true) {
                        $balance > 0 => 'due',
                        $balance < 0 => 'advance',
                        default => 'settled',
                    },
                    'hasOtherDues' => $otherDueCustomerIds->contains($customer->id),
                ];
            })
            ->sortByDesc('balance')
            ->values();
    }

    public function with(): array
    {
        $rows = $this->buildRows();

        return [
            'rows' => $rows,
            // Scoped to actual Udhaar loan balances only, exactly as before —
            // cross-module dues never feed into this figure, per the
            // standing rule against combining different kinds of owed money.
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

    protected function exportHeaders(): array
    {
        return ['Customer', 'Phone', 'Balance', 'Status', 'Other Dues'];
    }

    protected function exportRows(bool $forExcel): array
    {
        return $this->buildRows()->map(function (array $row) use ($forExcel) {
            $balance = abs($row['balance']);
            $status = ucfirst($row['status']);
            $phone = TableExportService::sanitizeCell($row['customer']->phone ?? '');

            return $forExcel
                ? [$row['customer']->name, $phone, (float) $balance, $status, $row['hasOtherDues'] ? 'Yes' : 'No']
                : [$row['customer']->name, $phone ?: '—', 'Rs '.number_format($balance, 2), $status, $row['hasOtherDues'] ? 'Yes' : 'No'];
        })->all();
    }

    public function exportPdf(TableExportService $exportService): StreamedResponse
    {
        return $exportService->toPdf(
            'Udhaar',
            Auth::user()->shop->name,
            'All records',
            $this->exportHeaders(),
            $this->exportRows(forExcel: false),
        );
    }

    public function exportExcel(TableExportService $exportService): BinaryFileResponse
    {
        return $exportService->toExcel(
            'Udhaar',
            Auth::user()->shop->name,
            'All records',
            $this->exportHeaders(),
            $this->exportRows(forExcel: true),
            ['string', 'string', 'currency', 'string', 'string'],
        );
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Udhaar</h1>
    </x-slot>

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <x-ui.stat label="Total Outstanding" value="Rs {{ number_format($totalDue, 2) }}" sub="Across all customers who currently owe money" />

        <div class="flex items-center gap-2">
            <x-ui.export-dropdown />
            <x-ui.button wire:click="openAddTransaction" class="shrink-0">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Add Transaction
            </x-ui.button>
        </div>
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
                        <div class="flex flex-wrap items-center gap-1.5">
                            @if ($status === 'due')
                                <x-ui.badge variant="warning">Due</x-ui.badge>
                            @elseif ($status === 'advance')
                                <x-ui.badge variant="brand">Advance</x-ui.badge>
                            @else
                                <x-ui.badge variant="success">Settled</x-ui.badge>
                            @endif
                            @if ($row['hasOtherDues'])
                                <x-ui.badge variant="neutral">Other Dues</x-ui.badge>
                            @endif
                        </div>
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

    <x-ui.modal name="quick-transaction-form" max-width="md">
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

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Type" name="type" for="type">
                        <x-ui.select wire:model="type" id="type">
                            <option value="given">Udhaar Given</option>
                            <option value="repayment">Repayment</option>
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Date" name="transaction_date" for="transaction_date">
                        <x-ui.input wire:model="transaction_date" id="transaction_date" type="date" />
                    </x-ui.field>
                </div>

                <x-ui.field label="Amount" name="amount" for="amount">
                    <x-ui.input wire:model="amount" id="amount" type="number" min="0.01" step="0.01" class="text-lg" />
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
