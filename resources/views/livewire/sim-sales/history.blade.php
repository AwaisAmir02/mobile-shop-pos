<?php

use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Network;
use App\Models\SimSale;
use App\Services\SimSaleReceiptPdfService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('SIM Sale History')] class extends Component
{
    use Toasts, WithPagination;

    public string $from = '';
    public string $to = '';
    public string $network = '';
    public string $paymentStatusFilter = '';

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

    public function updatingNetwork(): void
    {
        $this->resetPage();
    }

    public function updatingPaymentStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function filteredQuery()
    {
        return SimSale::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->network, fn ($query) => $query->where('network', $this->network))
            ->when($this->paymentStatusFilter, fn ($query) => $query->where('payment_status', $this->paymentStatusFilter));
    }

    public function with(): array
    {
        return [
            'sales' => $this->filteredQuery()->with('customer')->latest()->paginate(15),
            'totalRevenue' => $this->filteredQuery()->sum('total'),
            'totalOwed' => SimSale::query()
                ->where('payment_status', '!=', PaymentStatus::Paid->value)
                ->get()
                ->sum(fn (SimSale $sale) => $sale->amountOwed()),
            'allNetworks' => Network::query()->orderBy('name')->pluck('name'),
            'networksByName' => Network::query()->get()->keyBy('name'),
            'paymentStatuses' => PaymentStatus::cases(),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'network', 'paymentStatusFilter']);
    }

    public function downloadReceipt(int $saleId, SimSaleReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(SimSale::findOrFail($saleId));
    }

    public function markAsPaid(int $id): void
    {
        $sale = SimSale::findOrFail($id);

        $sale->update([
            'payment_status' => PaymentStatus::Paid,
            'amount_paid' => $sale->total,
        ]);

        $this->toastSuccess('Marked as paid.');
    }

    public function openAddPayment(int $id): void
    {
        $this->payingId = $id;
        $this->paymentAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'add-payment-form');
    }

    public function submitPayment(): void
    {
        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $sale = SimSale::findOrFail($this->payingId);
        $newAmountPaid = (float) $sale->amount_paid + (float) $this->paymentAmount;

        $sale->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => $newAmountPaid >= (float) $sale->total
                ? PaymentStatus::Paid
                : PaymentStatus::Partial,
        ]);

        $this->toastSuccess('Payment recorded.');
        $this->dispatch('close-modal', name: 'add-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }

    public function closePaymentForm(): void
    {
        $this->dispatch('close-modal', name: 'add-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">SIM Sale History</h1>
            <a href="{{ route('sim-sales.index') }}" wire:navigate>
                <x-ui.button size="sm">New SIM Sale</x-ui.button>
            </a>
        </div>
    </x-slot>

    <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:flex-wrap">
            <x-ui.field label="From" name="from" for="from" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="from" id="from" type="date" />
            </x-ui.field>

            <x-ui.field label="To" name="to" for="to" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="to" id="to" type="date" />
            </x-ui.field>

            <x-ui.field label="Network" name="network" for="network" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="network" id="network">
                    <option value="">All Networks</option>
                    @foreach ($allNetworks as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Payment Status" name="paymentStatusFilter" for="paymentStatusFilter" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="paymentStatusFilter" id="paymentStatusFilter">
                    <option value="">All Statuses</option>
                    @foreach ($paymentStatuses as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            @if ($from || $to || $network || $paymentStatusFilter)
                <div class="sm:pb-1">
                    <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                        Clear
                    </x-ui.button>
                </div>
            @endif
        </div>

        <div class="flex gap-4">
            <x-ui.card :padding="false" class="w-full sm:w-auto">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Revenue</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalRevenue, 2) }}</p>
                </div>
            </x-ui.card>

            <x-ui.card :padding="false" class="w-full sm:w-auto">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Owed by Customers</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalOwed, 2) }}</p>
                </div>
            </x-ui.card>
        </div>
    </div>

    @if ($sales->isEmpty())
        <x-ui.empty-state
            title="No SIM sales yet"
            description="Completed SIM sales will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('sim-sales.index') }}" wire:navigate>
                    <x-ui.button>New SIM Sale</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Receipt', 'Date', 'Network', 'Plan', 'SIM Number', 'Customer', 'Total', 'Payment', 'Owed', '']">
            @foreach ($sales as $sale)
                <x-ui.table-row wire:key="sim-sale-{{ $sale->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $sale->receiptNumber() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $sale->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @php $network = $networksByName->get($sale->network); @endphp
                        <div class="flex items-center gap-2">
                            <x-ui.thumbnail :src="$network?->imageUrl()" :label="$sale->network" :color="$network?->color" />
                            {{ $sale->network }}
                            <x-ui.color-dot :color="$network?->color" />
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>
                        <div class="flex flex-col">
                            <span class="text-slate-700">{{ $sale->sim_type->label() }} · {{ $sale->sim_form->label() }}</span>
                            @if ($sale->is_duplicate)
                                <span><x-ui.badge variant="warning">Duplicate</x-ui.badge></span>
                            @endif
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $sale->sim_number }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $sale->customer?->name ?? 'Walk-in' }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($sale->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($sale->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($sale->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">
                        @if ($sale->amountOwed() > 0)
                            Rs {{ number_format($sale->amountOwed(), 2) }}
                        @else
                            —
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            @if ($sale->payment_status->value !== 'paid')
                                <x-ui.button size="sm" variant="ghost" wire:click="openAddPayment({{ $sale->id }})">
                                    Add Payment
                                </x-ui.button>
                                <x-ui.button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="markAsPaid({{ $sale->id }})"
                                    wire:confirm="Mark this SIM sale as fully paid?"
                                >
                                    Mark as Paid
                                </x-ui.button>
                            @endif
                            <x-ui.button size="sm" variant="ghost" wire:click="downloadReceipt({{ $sale->id }})">
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

    <x-ui.modal name="add-payment-form" max-width="sm">
        <form wire:submit="submitPayment" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Add Payment</h2>

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
