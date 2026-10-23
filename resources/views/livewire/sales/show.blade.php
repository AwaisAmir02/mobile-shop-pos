<?php

use App\Actions\CancelSale;
use App\Livewire\Concerns\Toasts;
use App\Models\Sale;
use App\Services\InvoicePdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Sale Receipt')] class extends Component
{
    use Toasts;

    public Sale $sale;

    public string $cancelReason = '';

    public function mount(Sale $sale): void
    {
        $this->sale = $sale->load(['items', 'customer', 'payments']);
    }

    public function downloadInvoice(InvoicePdfService $pdf): StreamedResponse
    {
        return $pdf->download($this->sale);
    }

    public function openCancel(): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales-edit-cancel'), 403);

        $this->cancelReason = '';
        $this->dispatch('open-modal', name: 'cancel-sale-form');
    }

    public function closeCancelForm(): void
    {
        $this->dispatch('close-modal', name: 'cancel-sale-form');
        $this->cancelReason = '';
    }

    public function cancelSale(): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales-edit-cancel'), 403);

        $result = CancelSale::handle($this->sale, Auth::id(), $this->cancelReason);

        $this->sale = $this->sale->fresh(['items', 'customer', 'payments']);
        $this->dispatch('close-modal', name: 'cancel-sale-form');

        $message = 'Sale cancelled and stock restored.';
        if (! empty($result['skipped'])) {
            $message .= ' Could not restock (product deleted): '.implode(', ', $result['skipped']).'.';
        }

        $this->toastSuccess($message);
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">{{ $sale->invoiceNumber() }}</h1>
            <a href="{{ route('sales.history') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                ← Sales History
            </a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-2xl">
        @if ($sale->isCancelled())
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-semibold">This sale was cancelled{{ $sale->cancelled_at ? ' on '.$sale->cancelled_at->format('d M Y, h:i A') : '' }}.</p>
                @if ($sale->cancel_reason)
                    <p class="mt-1">Reason: {{ $sale->cancel_reason }}</p>
                @endif
                <p class="mt-1">Stock was restored and this sale no longer counts toward any total.</p>
            </div>
        @endif

        <x-ui.card>
            <x-slot name="actions">
                <div class="flex gap-2">
                    @if (! $sale->isCancelled() && auth()->user()->hasAccessTo('sales-edit-cancel'))
                        <a href="{{ route('sales.edit', $sale) }}" wire:navigate>
                            <x-ui.button variant="secondary" size="sm">Edit</x-ui.button>
                        </a>
                        <x-ui.button wire:click="openCancel" variant="secondary" size="sm" class="text-red-600 hover:bg-red-50">
                            Delete
                        </x-ui.button>
                    @endif
                    <x-ui.button wire:click="downloadInvoice" variant="secondary" size="sm">
                        Download PDF
                    </x-ui.button>
                </div>
            </x-slot>

            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <div>
                    <p class="text-sm text-slate-500">Date</p>
                    <p class="font-medium text-slate-900">{{ $sale->created_at->format('d M Y, h:i A') }}</p>
                </div>
                @if ($sale->user)
                    <div class="text-right">
                        <p class="text-sm text-slate-500">Served by</p>
                        <p class="font-medium text-slate-900">{{ $sale->user->name }}</p>
                    </div>
                @endif
            </div>

            @if ($sale->customer)
                <div class="flex items-center justify-between border-b border-slate-100 py-4">
                    <p class="text-sm text-slate-500">Customer</p>
                    <a href="{{ route('customers.show', $sale->customer) }}" wire:navigate class="font-medium text-brand-700 hover:text-brand-800">
                        {{ $sale->customer->name }}
                    </a>
                </div>
            @endif

            <div class="mt-4 divide-y divide-slate-100">
                @foreach ($sale->items as $item)
                    <div class="flex items-center justify-between py-3">
                        <div>
                            <p class="text-sm font-medium text-slate-900">{{ $item->product_name }}</p>
                            <p class="text-xs text-slate-400">
                                {{ $item->quantity }} × Rs {{ number_format($item->unit_price, 2) }}
                                @if ($item->discount_amount > 0)
                                    · Rs {{ number_format($item->discount_amount, 2) }} off
                                @endif
                            </p>
                        </div>
                        <span class="text-sm font-semibold text-slate-900">Rs {{ number_format($item->line_total, 2) }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 space-y-2 border-t border-slate-100 pt-4">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-500">Subtotal</span>
                    <span class="font-medium text-slate-900">Rs {{ number_format($sale->subtotal, 2) }}</span>
                </div>
                @if ((float) $sale->discount_amount > 0)
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-500">Discount</span>
                        <span class="font-medium text-slate-900">Rs {{ number_format($sale->discount_amount, 2) }}</span>
                    </div>
                @endif
                <div class="flex items-center justify-between border-t border-slate-100 pt-2">
                    <span class="font-medium text-slate-500">Total</span>
                    <span class="text-display-sm text-slate-900">Rs {{ number_format($sale->total, 2) }}</span>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between rounded-xl border border-slate-200 px-4 py-4">
                <div class="flex gap-6">
                    <div>
                        <p class="text-sm text-slate-500">Paid</p>
                        <p class="font-semibold text-slate-900">Rs {{ number_format($sale->amountPaid(), 2) }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500">Due</p>
                        <p class="font-semibold text-slate-900">Rs {{ number_format($sale->amountDue(), 2) }}</p>
                    </div>
                </div>
                @if ($sale->isCancelled())
                    <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                @elseif ($sale->paymentStatus() === 'paid')
                    <x-ui.badge variant="success">Paid</x-ui.badge>
                @elseif ($sale->paymentStatus() === 'partial')
                    <x-ui.badge variant="warning">Partial</x-ui.badge>
                @else
                    <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                @endif
            </div>
        </x-ui.card>

        <x-ui.modal name="cancel-sale-form" max-width="sm">
            <form wire:submit="cancelSale" class="p-6">
                <h2 class="text-lg font-semibold text-slate-900">Cancel This Sale?</h2>
                <p class="mt-2 text-sm text-slate-600">
                    This sale will be marked <strong>Cancelled</strong> and its stock will be restored. It will no longer count toward any total, report, or outstanding balance. This cannot be undone.
                </p>

                <div class="mt-5">
                    <x-ui.field label="Reason" name="cancelReason" for="cancelReason" help="Optional">
                        <x-ui.input wire:model="cancelReason" id="cancelReason" />
                    </x-ui.field>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" wire:click="closeCancelForm">
                        Keep Sale
                    </x-ui.button>

                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelSale" class="bg-red-600 hover:bg-red-700 focus:ring-red-500">
                        Cancel Sale
                    </x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        <div class="mt-4 text-center">
            <a href="{{ route('sales.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                Start a new sale →
            </a>
        </div>
    </div>
</div>
