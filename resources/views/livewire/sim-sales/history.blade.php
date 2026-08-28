<?php

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
    use WithPagination;

    public string $from = '';
    public string $to = '';
    public string $network = '';

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

    protected function filteredQuery()
    {
        return SimSale::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->network, fn ($query) => $query->where('network', $this->network));
    }

    public function with(): array
    {
        return [
            'sales' => $this->filteredQuery()->with('customer')->latest()->paginate(15),
            'totalRevenue' => $this->filteredQuery()->sum('total'),
            'allNetworks' => Network::query()->orderBy('name')->pluck('name'),
            'networksByName' => Network::query()->get()->keyBy('name'),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'network']);
    }

    public function downloadReceipt(int $saleId, SimSaleReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(SimSale::findOrFail($saleId));
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
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
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

            @if ($from || $to || $network)
                <div class="sm:pb-1">
                    <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                        Clear
                    </x-ui.button>
                </div>
            @endif
        </div>

        <x-ui.card :padding="false" class="w-full sm:w-auto">
            <div class="px-5 py-3 text-right">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Revenue</p>
                <p class="text-display-sm text-slate-900">Rs {{ number_format($totalRevenue, 2) }}</p>
            </div>
        </x-ui.card>
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
        <x-ui.table :headers="['Receipt', 'Date', 'Network', 'Plan', 'SIM Number', 'Customer', 'Total', '']">
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
                    <x-ui.table-cell align="right">
                        <x-ui.button size="sm" variant="ghost" wire:click="downloadReceipt({{ $sale->id }})">
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
