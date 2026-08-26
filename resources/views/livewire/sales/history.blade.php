<?php

use App\Models\Sale;
use App\Services\InvoicePdfService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Sales History')] class extends Component
{
    use WithPagination;

    public string $from = '';
    public string $to = '';

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
                ->with('customer')
                ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
                ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
                ->latest()
                ->paginate(15),
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

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <x-ui.field label="From" name="from" for="from" class="sm:max-w-[10rem]">
            <x-ui.input wire:model.live="from" id="from" type="date" />
        </x-ui.field>

        <x-ui.field label="To" name="to" for="to" class="sm:max-w-[10rem]">
            <x-ui.input wire:model.live="to" id="to" type="date" />
        </x-ui.field>

        @if ($from || $to)
            <div class="sm:pt-6">
                <x-ui.button type="button" variant="ghost" wire:click="clearDateFilters">
                    Clear
                </x-ui.button>
            </div>
        @endif
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
        <x-ui.table :headers="['Invoice', 'Date', 'Customer', 'Items', 'Discount', 'Total', '']">
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
                    <x-ui.table-cell>
                        {{ $sale->discount_amount > 0 ? 'Rs '.number_format($sale->discount_amount, 2) : '—' }}
                    </x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($sale->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <x-ui.button size="sm" variant="ghost" wire:click="downloadInvoice({{ $sale->id }})">
                            Download
                        </x-ui.button>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $sales->links() }}
        </div>
    @endif
</div>
