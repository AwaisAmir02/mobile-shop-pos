<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Sale;
use App\Services\InvoicePdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Sales History')] class extends Component
{
    use Toasts, WithPagination;

    public string $from = '';
    public string $to = '';

    public ?int $payingId = null;
    public string $paymentAmount = '';

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'sales' => Sale::query()
                ->withCount('items')
                ->withSum('payments', 'amount')
                ->with('customer')
                ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
                ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
                ->latest()
                ->paginate(15),
            // A running balance, like Udhaar's own Total Outstanding — deliberately
            // not scoped to the from/to filters above, which describe which sales
            // to list, not which sales still owe money.
            'totalOutstanding' => Sale::query()
                ->withSum('payments', 'amount')
                ->get()
                ->sum(fn (Sale $sale) => $sale->amountDue()),
        ];
    }

    public function clearDateFilters(): void
    {
        $this->reset(['from', 'to']);
    }

    public function downloadInvoice(int $saleId, InvoicePdfService $pdf): StreamedResponse
    {
        return $pdf->download(Sale::findOrFail($saleId));
    }

    public function openRecordPayment(int $saleId): void
    {
        $this->payingId = $saleId;
        $this->paymentAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'record-payment-form');
    }

    public function submitPayment(): void
    {
        $sale = Sale::findOrFail($this->payingId);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01', 'max:'.$sale->amountDue()],
        ]);

        $sale->payments()->create([
            'user_id' => Auth::id(),
            'amount' => $this->paymentAmount,
            'payment_date' => now()->toDateString(),
        ]);

        $this->toastSuccess('Payment recorded.');
        $this->dispatch('close-modal', name: 'record-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }

    public function closePaymentForm(): void
    {
        $this->dispatch('close-modal', name: 'record-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Sales History</h1>
            <a href="{{ route('sales.index') }}" wire:navigate>
                <x-ui.button size="sm">New Sale</x-ui.button>
            </a>
        </div>
    </x-slot>

    <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <x-ui.field label="From" name="from" for="from" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="from" id="from" type="date" />
            </x-ui.field>

            <x-ui.field label="To" name="to" for="to" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="to" id="to" type="date" />
            </x-ui.field>

            @if ($from || $to)
                <div class="sm:pb-1">
                    <x-ui.button type="button" variant="ghost" wire:click="clearDateFilters">
                        Clear
                    </x-ui.button>
                </div>
            @endif
        </div>

        <x-ui.card :padding="false" class="w-full sm:w-auto">
            <div class="px-5 py-3 text-right">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Outstanding</p>
                <p class="text-display-sm text-slate-900">Rs {{ number_format($totalOutstanding, 2) }}</p>
            </div>
        </x-ui.card>
    </div>

    @if ($sales->isEmpty())
        <x-ui.empty-state
            title="No sales yet"
            description="Completed sales will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('sales.index') }}" wire:navigate>
                    <x-ui.button>New Sale</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Invoice', 'Date', 'Customer', 'Items', 'Total', 'Paid', 'Status', '']">
            @foreach ($sales as $sale)
                <x-ui.table-row wire:key="sale-{{ $sale->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">
                        <a href="{{ route('sales.show', $sale) }}" wire:navigate class="hover:text-brand-700">
                            {{ $sale->invoiceNumber() }}
                        </a>
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $sale->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $sale->customer?->name ?? 'Walk-in' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $sale->items_count }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($sale->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($sale->amountPaid(), 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($sale->paymentStatus() === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($sale->paymentStatus() === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            @if ($sale->amountDue() > 0)
                                <x-ui.button size="sm" variant="ghost" wire:click="openRecordPayment({{ $sale->id }})">
                                    Record Payment
                                </x-ui.button>
                            @endif
                            <x-ui.button size="sm" variant="ghost" wire:click="downloadInvoice({{ $sale->id }})">
                                Download
                            </x-ui.button>
                        </div>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $sales->links() }}
        </div>
    @endif

    <x-ui.modal name="record-payment-form" max-width="sm">
        <form wire:submit="submitPayment" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Record Payment</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Amount Paid Now" name="paymentAmount" for="paymentAmount">
                    <x-ui.input wire:model="paymentAmount" id="paymentAmount" type="number" min="0.01" step="0.01" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closePaymentForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submitPayment">
                    Save Payment
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
