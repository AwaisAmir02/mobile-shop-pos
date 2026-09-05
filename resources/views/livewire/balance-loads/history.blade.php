<?php

use App\Models\BalanceLoad;
use App\Models\Network;
use App\Services\BalanceLoadReceiptPdfService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Balance Load History')] class extends Component
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

    protected function filteredQuery()
    {
        return BalanceLoad::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to));
    }

    public function with(): array
    {
        return [
            'loads' => $this->filteredQuery()->latest()->paginate(15),
            'totalLoaded' => $this->filteredQuery()->sum('amount'),
            'totalFees' => $this->filteredQuery()->sum('fee') - $this->filteredQuery()->sum('discount'),
            'networksByName' => Network::query()->get()->keyBy('name'),
        ];
    }

    public function clearDateFilters(): void
    {
        $this->reset(['from', 'to']);
    }

    public function downloadReceipt(int $loadId, BalanceLoadReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(BalanceLoad::findOrFail($loadId));
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Balance Load History</h1>
            <a href="{{ route('balance-loads.index') }}" wire:navigate>
                <x-ui.button size="sm">New Balance Load</x-ui.button>
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

        <div class="flex flex-col gap-4 sm:flex-row">
            <x-ui.card :padding="false" class="w-full sm:w-auto">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Loaded</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalLoaded, 2) }}</p>
                </div>
            </x-ui.card>

            <x-ui.card :padding="false" class="w-full sm:w-auto">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Fees</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalFees, 2) }}</p>
                </div>
            </x-ui.card>
        </div>
    </div>

    @if ($loads->isEmpty())
        <x-ui.empty-state
            title="No balance loads yet"
            description="Completed balance loads will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('balance-loads.index') }}" wire:navigate>
                    <x-ui.button>New Balance Load</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Receipt', 'Date', 'Network', 'Phone', 'Amount', 'Fee', 'Discount', 'Total', '']">
            @foreach ($loads as $load)
                <x-ui.table-row wire:key="load-{{ $load->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $load->receiptNumber() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $load->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @php $network = $networksByName->get($load->network); @endphp
                        <div class="flex items-center gap-2">
                            <x-ui.thumbnail :src="$network?->imageUrl()" :label="$load->network" :color="$network?->color" />
                            {{ $load->network }}
                            <x-ui.color-dot :color="$network?->color" />
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $load->phone_number ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($load->amount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($load->fee, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($load->discount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($load->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <x-ui.button size="sm" variant="ghost" wire:click="downloadReceipt({{ $load->id }})">
                            Download
                        </x-ui.button>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $loads->links() }}
        </div>
    @endif
</div>
