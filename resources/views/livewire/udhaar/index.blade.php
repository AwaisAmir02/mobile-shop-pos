<?php

use App\Actions\CreateUdhaarTransaction;
use App\Livewire\Concerns\GuardsExportSize;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\ReportQueries\UdhaarReport;
use App\Services\TableExportService;
use App\Support\BranchContext;
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
    use \App\Livewire\Concerns\ChoosesPaymentSource;

    use GuardsExportSize, Toasts;

    public string $customerId = '';
    public string $type = 'given';
    public string $amount = '';
    public string $transaction_date = '';
    public string $note = '';

    /**
     * Customers and vendors belong to the whole shop, so this screen shows
     * every branch's lines and balances to everyone, restricted staff
     * included. boot() runs on every Livewire request, not just the first.
     */
    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(): void
    {
        $this->transaction_date = now()->toDateString();
    }

    protected function report(): UdhaarReport
    {
        return new UdhaarReport;
    }

    public function with(): array
    {
        $report = $this->report();

        return [
            'rows' => $report->rows(),
            'totalDue' => $report->totalDue(),
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

        CreateUdhaarTransaction::handle($customer, $this->type, $this->amount, $this->transaction_date, $this->note, ...$this->paidFromParts());
        $this->paidFrom = '';

        $this->toastSuccess('Transaction recorded.');
        $this->dispatch('close-modal', name: 'quick-transaction-form');
        $this->reset(['customerId', 'amount', 'note']);
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'quick-transaction-form');
    }

    public function exportPdf(TableExportService $exportService): ?StreamedResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toPdf(
            'Udhaar',
            Auth::user()->shop->name,
            $report->filtersSummary(),
            $report->tableHeaders(),
            $report->tableRows(forExcel: false),
        ));
    }

    public function exportExcel(TableExportService $exportService): ?BinaryFileResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toExcel(
            'Udhaar',
            Auth::user()->shop->name,
            $report->filtersSummary(),
            $report->tableHeaders(),
            $report->tableRows(forExcel: true),
            $report->columnTypes(),
        ));
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="What each customer owes: udhaar loans plus unpaid sales and counter services">Udhaar</x-ui.page-header>
    </x-slot>

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-[14rem] flex-1 sm:max-w-sm">
            <x-ui.stat label="Total Outstanding" value="Rs {{ number_format($totalDue, 2) }}" sub="Across all customers who currently owe money" />
        </div>

        <div class="flex items-center gap-2">
            <x-ui.export-dropdown />
            <x-ui.button wire:click="openAddTransaction" class="shrink-0">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Add Transaction
            </x-ui.button>
        </div>
    </div>

    @if ($rows->isEmpty())
        <x-ui.empty-state
            title="No udhaar activity yet"
            description="Record udhaar given or a repayment to start a customer's history."
        />
    @else
        <x-ui.table stack :headers="['Customer', 'Phone', 'Balance', '']">
            @foreach ($rows as $row)
                <x-ui.table-row wire:key="udhaar-customer-{{ $row['customer']->id }}">
                    <x-ui.table-cell primary class="font-semibold text-ink">
                        <a href="{{ route('udhaar.show', $row['customer']) }}" wire:navigate class="hover:text-link hover:underline">{{ $row['customer']->name }}</a>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Phone">{{ $row['customer']->phone ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Balance" data-balance>
                        <span class="inline-flex flex-wrap items-center gap-1.5">
                            <span class="font-semibold tabular-nums text-ink">Rs {{ number_format(abs($row['balance']), 2) }}</span>
                            @if ($row['status'] === 'due')
                                <x-ui.badge variant="warning">Due</x-ui.badge>
                            @elseif ($row['status'] === 'advance')
                                <x-ui.badge variant="brand">Advance</x-ui.badge>
                            @else
                                <x-ui.badge variant="success">Settled</x-ui.badge>
                            @endif
                            @if ($row['hasOtherDues'])
                                <x-ui.badge variant="neutral">Other Dues</x-ui.badge>
                            @endif
                        </span>
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <x-ui.button href="{{ route('udhaar.show', $row['customer']) }}" wire:navigate size="sm" variant="ghost">View History</x-ui.button>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif

    <x-ui.modal name="quick-transaction-form" max-width="md">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-bold text-ink">Add Transaction</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Customer" name="customerId" for="customerId" required>
                    <x-ui.select wire:model.live="customerId" id="customerId">
                        <option value="">Select a customer</option>
                        <option value="__create__">+ New Customer</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Type" name="type" for="type" required>
                        <x-ui.select wire:model="type" id="type">
                            <option value="given">Udhaar Given</option>
                            <option value="repayment">Repayment</option>
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Date" name="transaction_date" for="transaction_date" required>
                        <x-ui.input wire:model="transaction_date" id="transaction_date" type="date" />
                    </x-ui.field>
                </div>

                <x-ui.field label="Amount" name="amount" for="amount" required>
                    <x-ui.input wire:model="amount" id="amount" type="number" min="0.01" step="0.01" class="text-lg" />
                </x-ui.field>
                <x-ledger.paid-from :value="$paidFrom" label="Cash or Account" blank="Not recorded" />

                <x-ui.field label="Note" name="note" for="note">
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
