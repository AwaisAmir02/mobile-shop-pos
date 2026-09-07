<?php

use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Product;
use App\Models\StockIn;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Stock In History')] class extends Component
{
    use Toasts, WithPagination;

    public string $from = '';
    public string $to = '';
    public string $productFilter = '';
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

    public function updatingProductFilter(): void
    {
        $this->resetPage();
    }

    public function updatingPaymentStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function filteredQuery()
    {
        return StockIn::query()
            ->when($this->from, fn ($query) => $query->whereDate('stock_date', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('stock_date', '<=', $this->to))
            ->when($this->productFilter, fn ($query) => $query->where('product_id', $this->productFilter))
            ->when($this->paymentStatusFilter, fn ($query) => $query->where('payment_status', $this->paymentStatusFilter));
    }

    public function with(): array
    {
        return [
            'entries' => $this->filteredQuery()->latest('stock_date')->latest('id')->paginate(15),
            'totalUnits' => $this->filteredQuery()->sum('quantity'),
            'totalOwed' => StockIn::query()
                ->where('payment_status', '!=', PaymentStatus::Paid->value)
                ->get()
                ->sum(fn (StockIn $entry) => $entry->amountOwed()),
            'products' => Product::query()->orderBy('name')->get(),
            'paymentStatuses' => PaymentStatus::cases(),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'productFilter', 'paymentStatusFilter']);
    }

    public function markAsPaid(int $id): void
    {
        $entry = StockIn::findOrFail($id);

        $entry->update([
            'payment_status' => PaymentStatus::Paid,
            'amount_paid' => $entry->total_cost ?? $entry->amount_paid,
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

        $entry = StockIn::findOrFail($this->payingId);
        $newAmountPaid = (float) $entry->amount_paid + (float) $this->paymentAmount;

        $entry->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => $entry->total_cost !== null && $newAmountPaid >= (float) $entry->total_cost
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
            <h1 class="text-xl font-semibold text-slate-900">Stock In History</h1>
            <a href="{{ route('stock-ins.index') }}" wire:navigate>
                <x-ui.button size="sm">Add Stock</x-ui.button>
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

            <x-ui.field label="Product" name="productFilter" for="productFilter" class="sm:max-w-[14rem]">
                <x-ui.select wire:model.live="productFilter" id="productFilter">
                    <option value="">All Products</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }}</option>
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

            @if ($from || $to || $productFilter || $paymentStatusFilter)
                <div class="sm:pb-1">
                    <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                        Clear
                    </x-ui.button>
                </div>
            @endif
        </div>

        <div class="flex gap-4">
            <x-ui.card :padding="false">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Units</p>
                    <p class="text-display-sm text-slate-900">{{ number_format($totalUnits) }}</p>
                </div>
            </x-ui.card>

            <x-ui.card :padding="false">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Owed to Suppliers</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalOwed, 2) }}</p>
                </div>
            </x-ui.card>
        </div>
    </div>

    @if ($entries->isEmpty())
        <x-ui.empty-state
            title="No stock-in entries yet"
            description="Entries logged from the Stock In screen will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('stock-ins.index') }}" wire:navigate>
                    <x-ui.button>Add Stock</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Date', 'Product', 'Quantity', 'Payment', 'Owed', 'Note', 'Entered By', '']">
            @foreach ($entries as $entry)
                <x-ui.table-row wire:key="stock-in-{{ $entry->id }}">
                    <x-ui.table-cell>{{ $entry->stock_date->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $entry->product_name }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-emerald-600">+{{ number_format($entry->quantity) }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($entry->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($entry->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">
                        @if ($entry->amountOwed() > 0)
                            Rs {{ number_format($entry->amountOwed(), 2) }}
                        @else
                            —
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $entry->note ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $entry->user?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        @if ($entry->payment_status->value !== 'paid')
                            <div class="flex justify-end gap-2">
                                <x-ui.button size="sm" variant="ghost" wire:click="openAddPayment({{ $entry->id }})">
                                    Add Payment
                                </x-ui.button>
                                <x-ui.button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="markAsPaid({{ $entry->id }})"
                                    wire:confirm="Mark this stock-in as fully paid?"
                                >
                                    Mark as Paid
                                </x-ui.button>
                            </div>
                        @endif
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $entries->links() }}
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
