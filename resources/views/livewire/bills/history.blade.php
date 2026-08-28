<?php

use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Services\BillPaymentReceiptPdfService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Bill Payment History')] class extends Component
{
    use WithPagination;

    public string $from = '';
    public string $to = '';
    public string $billCategoryId = '';
    public string $billProviderId = '';

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function updatingBillCategoryId(): void
    {
        $this->billProviderId = '';
        $this->resetPage();
    }

    public function updatingBillProviderId(): void
    {
        $this->resetPage();
    }

    protected function filteredQuery()
    {
        return BillPayment::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->billCategoryId, fn ($query) => $query->where('bill_category_id', $this->billCategoryId))
            ->when($this->billProviderId, fn ($query) => $query->where('bill_provider_id', $this->billProviderId));
    }

    public function with(): array
    {
        return [
            'payments' => $this->filteredQuery()->with(['billCategory', 'billProvider', 'customer'])->latest()->paginate(15),
            'totalCollected' => $this->filteredQuery()->sum('total'),
            'allCategories' => BillCategory::query()->orderBy('name')->get(),
            'allProviders' => BillProvider::query()
                ->when($this->billCategoryId, fn ($query) => $query->where('bill_category_id', $this->billCategoryId))
                ->orderBy('name')
                ->get(),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'billCategoryId', 'billProviderId']);
    }

    public function downloadReceipt(int $paymentId, BillPaymentReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(BillPayment::findOrFail($paymentId));
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Bill Payment History</h1>
            <a href="{{ route('bills.index') }}" wire:navigate>
                <x-ui.button size="sm">New Bill Payment</x-ui.button>
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

            <x-ui.field label="Category" name="billCategoryId" for="billCategoryId" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="billCategoryId" id="billCategoryId">
                    <option value="">All Categories</option>
                    @foreach ($allCategories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Provider" name="billProviderId" for="billProviderId" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="billProviderId" id="billProviderId">
                    <option value="">All Providers</option>
                    @foreach ($allProviders as $provider)
                        <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            @if ($from || $to || $billCategoryId || $billProviderId)
                <div class="sm:pb-1">
                    <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                        Clear
                    </x-ui.button>
                </div>
            @endif
        </div>

        <x-ui.card :padding="false" class="w-full sm:w-auto">
            <div class="px-5 py-3 text-right">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Collected</p>
                <p class="text-display-sm text-slate-900">Rs {{ number_format($totalCollected, 2) }}</p>
            </div>
        </x-ui.card>
    </div>

    @if ($payments->isEmpty())
        <x-ui.empty-state
            title="No bill payments yet"
            description="Completed bill payments will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('bills.index') }}" wire:navigate>
                    <x-ui.button>New Bill Payment</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Receipt', 'Date', 'Category', 'Provider', 'Consumer', 'Customer', 'Total', '']">
            @foreach ($payments as $payment)
                <x-ui.table-row wire:key="bill-payment-{{ $payment->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $payment->receiptNumber() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payment->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payment->billCategory?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payment->billProvider?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        <div class="flex flex-col">
                            <span class="text-slate-700">{{ $payment->consumer_name }}</span>
                            <span class="text-xs text-slate-400">{{ $payment->consumer_number }}</span>
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $payment->customer?->name ?? 'Walk-in' }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($payment->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <x-ui.button size="sm" variant="ghost" wire:click="downloadReceipt({{ $payment->id }})">
                            Download
                        </x-ui.button>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $payments->links() }}
        </div>
    @endif
</div>
