<?php

use App\Actions\CancelPurchaseOrder;
use App\Livewire\Concerns\Toasts;
use App\Models\PurchaseOrder;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Services\PurchaseOrderPdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Purchase Order')] class extends Component
{
    use Toasts;

    public int $orderId;
    public string $cancelReason = '';

    public function mount(int $order): void
    {
        $this->orderId = $this->order($order)->id;
    }

    protected function order(?int $id = null): PurchaseOrder
    {
        return PurchaseOrder::withoutGlobalScope(ExcludesCancelledScope::class)
            ->inCurrentBranch()
            ->with(['vendor', 'branch', 'user', 'lines.activeGrnLines', 'lines.product', 'grns.lines', 'grns.user'])
            ->findOrFail($id ?? $this->orderId);
    }

    public function with(): array
    {
        $order = $this->order();
        $enabled = (bool) Auth::user()->shop?->purchase_orders_enabled;
        $canManage = $enabled && Auth::user()->hasAccessTo('purchase-orders');

        return [
            'order' => $order,
            'enabled' => $enabled,
            'canEdit' => $canManage && $order->isEditable(),
            'canCancel' => $canManage && ! $order->isCancelled() && $order->grns->isEmpty(),
            'canClose' => $canManage && $order->canReceive() && $order->grns->isNotEmpty(),
            'canReceive' => $enabled && $order->canReceive() && Auth::user()->hasAccessTo('receive-goods'),
            'canSeeReceipts' => Auth::user()->hasAccessTo('receive-goods'),
        ];
    }

    public function closeShort(): void
    {
        abort_unless(Auth::user()->hasAccessTo('purchase-orders'), 403);

        CancelPurchaseOrder::closeShort($this->order(), Auth::id());
        $this->toastSuccess('Order closed short.');
    }

    public function cancelOrder(): void
    {
        abort_unless(Auth::user()->hasAccessTo('purchase-orders'), 403);

        CancelPurchaseOrder::handle($this->order(), Auth::id(), $this->cancelReason);
        $this->dispatch('close-modal', name: 'cancel-po-form');
        $this->cancelReason = '';
        $this->toastSuccess('Order cancelled.');
    }

    public function downloadPdf(PurchaseOrderPdfService $pdf): StreamedResponse
    {
        return $pdf->download($this->order());
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">{{ $order->number() }}</h1>
            <a href="{{ route('purchase-orders.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">← All Purchase Orders</a>
        </div>
    </x-slot>

    @unless ($enabled)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Purchase orders aren't on your plan right now, so this order is read-only. Use <a href="{{ route('stock-ins.index') }}" wire:navigate class="font-medium underline">Stock In</a> to receive goods.
        </div>
    @endunless

    <x-ui.error :messages="$errors->get('order')" class="mb-4" />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <x-ui.badge :variant="$order->status->badge()">{{ $order->status->label() }}</x-ui.badge>
        <div class="grow"></div>
        @if ($canReceive)
            <a href="{{ route('purchase-orders.receive', $order->id) }}" wire:navigate><x-ui.button size="sm">Receive Goods</x-ui.button></a>
        @endif
        @if ($canEdit)
            <a href="{{ route('purchase-orders.edit', $order->id) }}" wire:navigate><x-ui.button size="sm" variant="secondary">Edit</x-ui.button></a>
        @endif
        <x-ui.button size="sm" variant="secondary" wire:click="downloadPdf">Download PDF</x-ui.button>
        @if ($canClose)
            <x-ui.button size="sm" variant="secondary" wire:click="closeShort" wire:confirm="Close this order short? You won't be able to receive the rest against it. What already arrived stays, along with what you owe for it.">Close Short</x-ui.button>
        @endif
        @if ($canCancel)
            <x-ui.button size="sm" variant="ghost" class="text-red-600 hover:bg-red-50" x-on:click="$dispatch('open-modal', { name: 'cancel-po-form' })">Cancel Order</x-ui.button>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card title="Details" class="lg:col-span-1">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-slate-500">Vendor</dt><dd class="font-medium text-slate-900">{{ $order->vendor?->name ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Ordered</dt><dd class="font-medium text-slate-900">{{ $order->order_date->format('d M Y') }}</dd></div>
                <div><dt class="text-slate-500">Expected</dt><dd class="font-medium text-slate-900">{{ $order->expected_date?->format('d M Y') ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Deliver to</dt><dd class="font-medium text-slate-900">{{ $order->branch?->name ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Order value</dt><dd class="font-medium text-slate-900">Rs {{ number_format($order->orderedAmount(), 2) }}</dd></div>
                @if ($order->note)
                    <div><dt class="text-slate-500">Note</dt><dd class="font-medium text-slate-900">{{ $order->note }}</dd></div>
                @endif
                @if ($order->cancel_reason)
                    <div><dt class="text-slate-500">Cancelled because</dt><dd class="font-medium text-slate-900">{{ $order->cancel_reason }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Products" :padding="false">
                <x-ui.table :headers="['Product', 'Ordered', 'Received', 'Still to come', 'Expected Cost']">
                    @foreach ($order->lines as $line)
                        <x-ui.table-row wire:key="po-line-{{ $line->id }}">
                            <x-ui.table-cell class="font-medium text-slate-900">
                                {{ $line->product_name }}
                                @if ($line->product_id === null)
                                    <span class="text-xs text-red-600">(product deleted)</span>
                                @endif
                            </x-ui.table-cell>
                            <x-ui.table-cell>{{ $line->unit->groupedAmount($line->quantity) }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $line->unit->groupedAmount($line->receivedQuantity()) }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $line->unit->groupedAmount($line->outstandingQuantity()) }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $line->expected_cost !== null ? 'Rs '.number_format($line->expected_cost, 2) : '—' }}</x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Goods Received" :padding="false">
                @if ($order->grns->isEmpty())
                    <p class="px-5 py-4 text-sm text-slate-400">Nothing received yet.</p>
                @else
                    <x-ui.table :headers="['Receipt', 'Received', 'Products', 'By']">
                        @foreach ($order->grns as $grn)
                            <x-ui.table-row wire:key="po-grn-{{ $grn->id }}">
                                <x-ui.table-cell class="font-medium text-slate-900">{{ $grn->number() }}</x-ui.table-cell>
                                <x-ui.table-cell>{{ $grn->received_on->format('d M Y') }}</x-ui.table-cell>
                                <x-ui.table-cell>{{ $grn->lines->map(fn ($line) => $line->product_name.' × '.$line->unit->amount($line->quantity))->implode(', ') }}</x-ui.table-cell>
                                <x-ui.table-cell>{{ $grn->user?->name ?? '—' }}</x-ui.table-cell>
                            </x-ui.table-row>
                        @endforeach
                    </x-ui.table>
                    @if ($canSeeReceipts)
                        <p class="px-5 py-3 text-sm"><a href="{{ route('purchase-orders.receipts', ['po' => $order->id]) }}" wire:navigate class="font-medium text-brand-700 hover:text-brand-800">Manage these receipts →</a></p>
                    @endif
                @endif
            </x-ui.card>
        </div>
    </div>

    @if ($canCancel)
        <x-ui.modal name="cancel-po-form" max-width="sm">
            <form wire:submit="cancelOrder" class="p-6">
                <h2 class="text-lg font-semibold text-slate-900">Cancel This Order?</h2>
                <p class="mt-2 text-sm text-slate-600">This cannot be undone. Nothing has been received against it, so no stock or vendor balance changes.</p>
                <div class="mt-5">
                    <x-ui.field label="Reason" name="cancelReason" for="cancelReason" help="Optional">
                        <x-ui.input wire:model="cancelReason" id="cancelReason" />
                    </x-ui.field>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'cancel-po-form' })">Keep Order</x-ui.button>
                    <x-ui.button type="submit" class="bg-red-600 hover:bg-red-700 focus:ring-red-500">Cancel Order</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
