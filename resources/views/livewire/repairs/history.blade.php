<?php

use App\Enums\RepairCategory;
use App\Models\Repair;
use App\Services\RepairReceiptPdfService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Repair History')] class extends Component
{
    use WithPagination;

    public string $from = '';
    public string $to = '';
    public string $category = '';

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function updatingCategory(): void
    {
        $this->resetPage();
    }

    protected function filteredQuery()
    {
        return Repair::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->category, fn ($query) => $query->where('category', $this->category));
    }

    public function with(): array
    {
        return [
            'repairs' => $this->filteredQuery()->with('customer')->latest()->paginate(15),
            'totalCollected' => $this->filteredQuery()->sum('total'),
            'categories' => RepairCategory::cases(),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'category']);
    }

    public function downloadReceipt(int $repairId, RepairReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(Repair::findOrFail($repairId));
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Repair History</h1>
            <a href="{{ route('repairs.index') }}" wire:navigate>
                <x-ui.button size="sm">New Repair</x-ui.button>
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

            <x-ui.field label="Category" name="category" for="category" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="category" id="category">
                    <option value="">All Categories</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            @if ($from || $to || $category)
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

    @if ($repairs->isEmpty())
        <x-ui.empty-state
            title="No repairs yet"
            description="Completed repairs will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('repairs.index') }}" wire:navigate>
                    <x-ui.button>New Repair</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Receipt', 'Date', 'Category', 'Description', 'Customer', 'Total', '']">
            @foreach ($repairs as $repair)
                <x-ui.table-row wire:key="repair-{{ $repair->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $repair->receiptNumber() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $repair->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $repair->category->label() }}</x-ui.table-cell>
                    <x-ui.table-cell class="max-w-xs truncate">{{ $repair->description }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $repair->customer?->name ?? 'Walk-in' }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($repair->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <x-ui.button size="sm" variant="ghost" wire:click="downloadReceipt({{ $repair->id }})">
                            Download
                        </x-ui.button>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $repairs->links() }}
        </div>
    @endif
</div>
