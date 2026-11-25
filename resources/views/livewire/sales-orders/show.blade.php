<?php

use App\Actions\SalesOrders;
use App\Livewire\Concerns\ChoosesPaymentSource;
use App\Livewire\Concerns\Toasts;
use App\Models\SalesOrder;
use App\Support\BranchContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** One order: deliver it (it becomes a sale), take it off the van, cancel it, or print its challan. */
new #[Layout('layouts.app')] #[Title('Order')] class extends Component
{
    use ChoosesPaymentSource, Toasts;

    public SalesOrder $order;

    /** @var array<int, string> line id => quantity delivered */
    public array $delivered = [];

    public string $collected = '';
    public string $cancelReason = '';

    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(SalesOrder $order): void
    {
        abort_unless(Auth::user()->shop?->usesTool('orders'), 404);
        $this->order = $order;
    }

    public function openDeliver(): void
    {
        $this->delivered = $this->order->lines->mapWithKeys(fn ($line) => [$line->id => (string) $line->quantity])->all();
        $this->collected = '';
        $this->paidFrom = 'cash';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'deliver-order');
    }

    public function deliver(): void
    {
        $this->validate(['collected' => ['nullable', 'numeric', 'min:0'], 'delivered.*' => ['nullable', 'numeric', 'min:0']], [], ['collected' => 'amount collected']);
        [$method, $accountId] = $this->paidFromParts();

        $sale = SalesOrders::deliver($this->order, $this->delivered, (float) ($this->collected ?: 0), $method, $accountId, Auth::id(), (bool) Auth::user()->shop?->usesTool('schemes'));

        $this->dispatch('close-modal', name: 'deliver-order');
        $this->order = $this->order->fresh();
        $this->toastSuccess('Delivered: '.$sale->invoiceNumber().' for Rs '.number_format((float) $sale->total, 2).($sale->amountDue() > 0 ? ', Rs '.number_format($sale->amountDue(), 2).' on credit.' : ', paid in full.'));
    }

    public function bringBack(): void
    {
        SalesOrders::bringBack($this->order);
        $this->order = $this->order->fresh();
        $this->toastSuccess('Taken off the van. It stays booked for another day.');
    }

    public function cancel(): void
    {
        SalesOrders::cancel($this->order, $this->cancelReason);
        $this->dispatch('close-modal', name: 'cancel-order');
        $this->order = $this->order->fresh();
        $this->toastSuccess('Order cancelled. Its stock is free again.');
    }

    public function challan(): StreamedResponse
    {
        $order = $this->order->fresh(['lines.product', 'customer', 'booker', 'loadSheet', 'sale.items']);
        $output = Pdf::loadView('pdf.delivery-challan', ['order' => $order, 'shop' => Auth::user()->shop])->setPaper('a5')->output();

        return response()->streamDownload(fn () => print ($output), 'Challan-'.$order->number().'.pdf');
    }

    public function with(): array
    {
        $this->order->load(['lines', 'customer', 'booker', 'route', 'loadSheet', 'sale']);

        return [
            'owed' => $this->order->customer ? \App\Support\CustomerDuesStatement::for($this->order->customer)->total() : 0.0,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('sales-orders.index') }}" back-label="Orders" :subtitle="$order->customer?->displayName()">{{ $order->number() }}</x-ui.page-header>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card title="Items" class="lg:col-span-2">
            <x-slot name="actions">
                <div class="flex flex-wrap gap-2">
                    <x-ui.button size="sm" variant="secondary" wire:click="challan" data-challan>Delivery challan</x-ui.button>
                    @if ($order->isOpen())
                        <x-ui.button size="sm" variant="secondary" href="{{ route('sales-orders.edit', $order) }}" wire:navigate>Change</x-ui.button>
                        <x-ui.button size="sm" wire:click="openDeliver" data-deliver>Delivered</x-ui.button>
                    @endif
                </div>
            </x-slot>
            <ul class="divide-y divide-line">
                @foreach ($order->lines as $line)
                    <li class="flex justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0"><span class="font-semibold text-ink">{{ $line->product_name }}</span> <span class="text-ink-faint">× {{ $line->quantityLabel() }} @ Rs {{ number_format($line->unit_price, 2) }}{{ (float) $line->discount > 0 ? ' less Rs '.number_format($line->discount, 2) : '' }}</span></span>
                        <span class="shrink-0 font-semibold tabular-nums text-ink">Rs {{ number_format($line->lineTotal(), 2) }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="mt-3 flex justify-between border-t border-line pt-3 text-sm font-bold text-ink"><span>Order value</span><span class="tabular-nums">Rs {{ number_format($order->estimated_total, 2) }}</span></div>
        </x-ui.card>

        <x-ui.card title="Details">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-ink-faint">Status</dt><dd class="font-semibold text-ink">{{ $order->statusLabel() }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-faint">Booked</dt><dd class="text-ink">{{ $order->order_date->format('d M Y') }}{{ $order->booker ? ' by '.$order->booker->name : '' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-faint">Deliver on</dt><dd class="text-ink">{{ $order->delivery_date?->format('d M Y') ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-faint">Route</dt><dd class="text-ink">{{ $order->route?->name ?? '—' }}</dd></div>
                @if ($order->loadSheet)
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Van</dt><dd><a href="{{ route('load-sheets.show', $order->loadSheet) }}" wire:navigate class="text-link hover:underline">{{ $order->loadSheet->number() }}</a></dd></div>
                @endif
                @if ($order->sale)
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Sale</dt><dd><a href="{{ route('sales.show', $order->sale) }}" wire:navigate class="text-link hover:underline">{{ $order->sale->invoiceNumber() }}</a></dd></div>
                @endif
                <div class="flex justify-between gap-3"><dt class="text-ink-faint">Customer owes now</dt><dd class="tabular-nums text-ink">Rs {{ number_format($owed, 2) }}</dd></div>
                @if ($order->cancel_reason)
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Cancelled</dt><dd class="text-ink">{{ $order->cancel_reason }}</dd></div>
                @endif
            </dl>
            @if ($order->isOpen())
                <div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">
                    @if ($order->load_sheet_id)
                        <x-ui.button size="sm" variant="secondary" wire:click="bringBack" data-bring-back>Not delivered</x-ui.button>
                    @endif
                    <x-ui.button size="sm" variant="danger-outline" x-on:click="$dispatch('open-modal', { name: 'cancel-order' })">Cancel order</x-ui.button>
                </div>
            @endif
        </x-ui.card>
    </div>

    <x-ui.modal name="deliver-order" max-width="lg">
        <form wire:submit="deliver" class="p-6">
            <h2 class="text-lg font-bold text-ink">Delivered</h2>
            <p class="mt-1 text-sm text-ink-muted">What was handed over. Anything refused comes back into stock as unsold. It becomes a sale today.</p>
            <ul class="mt-4 divide-y divide-line">
                @foreach ($order->lines as $line)
                    <li class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0"><span class="font-semibold text-ink">{{ $line->product_name }}</span> <span class="text-ink-faint">ordered {{ $line->quantityLabel() }}</span>@error('delivered.'.$line->id)<span class="block text-xs text-danger">{{ $message }}</span>@enderror</span>
                        <input type="number" min="0" step="any" wire:model="delivered.{{ $line->id }}" aria-label="Delivered quantity of {{ $line->product_name }}" class="w-24 rounded-lg border-line-strong bg-surface py-1.5 text-right text-sm text-ink" />
                    </li>
                @endforeach
            </ul>
            <div class="mt-4 space-y-4">
                <x-ui.field label="Money collected (Rs)" name="collected" for="collected" help="Blank or 0 if it is all on credit">
                    <x-ui.input wire:model="collected" id="collected" type="number" min="0" step="0.01" />
                </x-ui.field>
                <x-ledger.paid-from :value="$paidFrom" label="Received Into" />
                @error('deliver')<p class="text-sm text-danger">{{ $message }}</p>@enderror
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'deliver-order' })">Back</x-ui.button>
                <x-ui.button type="submit" data-confirm-deliver>Save Delivery</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="cancel-order" max-width="sm">
        <form wire:submit="cancel" class="p-6">
            <h2 class="text-lg font-bold text-ink">Cancel {{ $order->number() }}</h2>
            <div class="mt-4">
                <x-ui.field label="Why" name="cancelReason" for="cancelReason">
                    <x-ui.input wire:model="cancelReason" id="cancelReason" maxlength="255" />
                </x-ui.field>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'cancel-order' })">Back</x-ui.button>
                <x-ui.button type="submit" variant="danger">Cancel Order</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
