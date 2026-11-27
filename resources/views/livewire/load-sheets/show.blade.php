<?php

use App\Actions\LoadSheets;
use App\Livewire\Concerns\Toasts;
use App\Models\LoadSheet;
use App\Models\SalesOrder;
use App\Services\LoadSheetPdfService;
use App\Support\BranchContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One van run (W7): what to load (each product totalled), the stops in route
 * order with what each customer owes, and the settlement at the end of the
 * day: every order delivered or brought back.
 */
new #[Layout('layouts.app')] #[Title('Load Sheet')] class extends Component
{
    use Toasts;

    public LoadSheet $sheet;

    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(LoadSheet $sheet): void
    {
        $this->sheet = $sheet;
    }

    public function bringBack(int $orderId): void
    {
        LoadSheets::bringBack(SalesOrder::where('load_sheet_id', $this->sheet->id)->findOrFail($orderId));
        $this->toastSuccess('Taken off the van. It stays booked.');
    }

    public function settle(): void
    {
        LoadSheets::settle($this->sheet);
        $this->sheet = $this->sheet->fresh();
        $this->toastSuccess('Load sheet settled.');
    }

    public function pdf(): StreamedResponse
    {
        return app(LoadSheetPdfService::class)->download($this->sheet);
    }

    public function with(): array
    {
        $stops = LoadSheets::stops($this->sheet);

        return [
            'loading' => LoadSheets::loadingList($this->sheet),
            'stops' => $stops,
            'collected' => round((float) $stops->filter(fn ($o) => $o->sale)->sum(fn ($o) => $o->sale->amountPaid()), 2),
            'deliveredValue' => round((float) $stops->filter(fn ($o) => $o->sale)->sum(fn ($o) => (float) $o->sale->total), 2),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('load-sheets.index') }}" back-label="Load Sheets" :subtitle="collect([$sheet->load_date->format('d M Y'), $sheet->deliveryRoute?->name, $sheet->vehicle, $sheet->driver, $sheet->salesman?->name])->filter()->implode(' · ')">{{ $sheet->number() }}</x-ui.page-header>
    </x-slot>

    <x-ui.stat-row>
        <x-ui.stat label="Stops" :value="$stops->count()" :sub="$stops->where('status', \App\Enums\SalesOrderStatus::Booked)->count().' still to deliver'" />
        <x-ui.stat label="Delivered" value="Rs {{ number_format($deliveredValue, 2) }}" />
        <x-ui.stat label="Collected" value="Rs {{ number_format($collected, 2) }}" sub="Cash and account, on delivery" />
    </x-ui.stat-row>

    <div class="mb-4 flex flex-wrap justify-end gap-2">
        <x-ui.button variant="secondary" wire:click="pdf" data-load-sheet-pdf>Print Load Sheet</x-ui.button>
        @if ($sheet->isOpen())
            <x-ui.button wire:click="settle" data-settle-sheet>Settle</x-ui.button>
        @endif
    </div>
    @error('settle')<p class="mb-4 text-sm text-danger">{{ $message }}</p>@enderror

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card title="Load" description="Undelivered orders, product by product" data-loading-list>
            @if ($loading === [])
                <p class="text-sm text-ink-faint">Nothing left to deliver.</p>
            @else
                <ul class="divide-y divide-line text-sm">
                    @foreach ($loading as $row)
                        <li class="flex justify-between gap-3 py-2">
                            <span class="text-ink">{{ $row['product'] }}</span>
                            <span class="font-semibold tabular-nums text-ink">
                                {{ \App\Support\Quantity::grouped($row['quantity']) }}
                                @if ($row['pack_size'] > 1 && $row['pack_name'])
                                    <span class="block text-xs font-normal text-ink-faint">{{ intdiv((int) $row['quantity'], (int) $row['pack_size']) }} {{ mb_strtolower($row['pack_name']) }} + {{ (int) $row['quantity'] % (int) $row['pack_size'] }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card title="Stops" description="In route order" class="lg:col-span-2" :padding="false" data-stops>
            <ul class="divide-y divide-line">
                @foreach ($stops as $i => $order)
                    <li wire:key="stop-{{ $order->id }}" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0 text-sm">
                            <p class="font-semibold text-ink">{{ $i + 1 }}. {{ $order->customer?->displayName() }}</p>
                            <p class="text-xs text-ink-faint">{{ collect([$order->customer?->area, $order->customer?->phone, $order->number(), 'Rs '.number_format($order->estimated_total, 0)])->filter()->implode(' · ') }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($order->status === \App\Enums\SalesOrderStatus::Delivered)
                                <x-ui.badge variant="success">Delivered</x-ui.badge>
                                @if ($order->sale)
                                    <a href="{{ route('sales.show', $order->sale) }}" wire:navigate class="text-xs text-link hover:underline">{{ $order->sale->invoiceNumber() }}</a>
                                @endif
                            @elseif ($order->isOpen())
                                <x-ui.button size="sm" href="{{ route('sales-orders.show', $order) }}" wire:navigate>Deliver</x-ui.button>
                                <x-ui.button size="sm" variant="secondary" wire:click="bringBack({{ $order->id }})">Not delivered</x-ui.button>
                            @else
                                <x-ui.badge>{{ $order->statusLabel() }}</x-ui.badge>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>
</div>
