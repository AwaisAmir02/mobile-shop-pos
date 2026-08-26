<?php

use App\Models\Product;
use App\Models\StockIn;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Stock In History')] class extends Component
{
    use WithPagination;

    public string $from = '';
    public string $to = '';
    public string $productFilter = '';

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

    protected function filteredQuery()
    {
        return StockIn::query()
            ->when($this->from, fn ($query) => $query->whereDate('stock_date', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('stock_date', '<=', $this->to))
            ->when($this->productFilter, fn ($query) => $query->where('product_id', $this->productFilter));
    }

    public function with(): array
    {
        return [
            'entries' => $this->filteredQuery()->latest('stock_date')->latest('id')->paginate(15),
            'totalUnits' => $this->filteredQuery()->sum('quantity'),
            'products' => Product::query()->orderBy('name')->get(),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'productFilter']);
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
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
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

            @if ($from || $to || $productFilter)
                <div class="sm:pb-1">
                    <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                        Clear
                    </x-ui.button>
                </div>
            @endif
        </div>

        <x-ui.card :padding="false" class="w-full sm:w-auto">
            <div class="px-5 py-3 text-right">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Units</p>
                <p class="text-display-sm text-slate-900">{{ number_format($totalUnits) }}</p>
            </div>
        </x-ui.card>
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
        <x-ui.table :headers="['Date', 'Product', 'Quantity', 'Note', 'Entered By']">
            @foreach ($entries as $entry)
                <x-ui.table-row wire:key="stock-in-{{ $entry->id }}">
                    <x-ui.table-cell>{{ $entry->stock_date->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $entry->product_name }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-emerald-600">+{{ number_format($entry->quantity) }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $entry->note ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $entry->user?->name ?? '—' }}</x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $entries->links() }}
        </div>
    @endif
</div>
