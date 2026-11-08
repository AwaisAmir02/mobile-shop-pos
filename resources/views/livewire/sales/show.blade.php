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

    // True only when arriving straight from completing this sale on the register
    // (a one-time session flash), so the page opens as a confirmation.
    public bool $justCompleted = false;

    public function mount(Sale $sale): void
    {
        $this->sale = $sale->load(['items', 'customer', 'payments.shopAccount']);
        $this->justCompleted = session('sale-completed') === $sale->id;
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

        $this->sale = $this->sale->fresh(['items', 'customer', 'payments.shopAccount']);
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
        <x-ui.page-header back="{{ route('sales.history') }}" back-label="Sales History">
            {{ $sale->invoiceNumber() }}
            <x-slot:actions>
                <x-ui.button href="{{ route('sales.index') }}" wire:navigate variant="accent" size="sm" @class(['hidden' => $justCompleted && ! $sale->isCancelled()])>New Sale</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot>

    <div class="mx-auto max-w-2xl">
        @if ($justCompleted && ! $sale->isCancelled())
            @php($due = $sale->amountDue())
            <div class="mb-5 rounded-2xl border border-success/20 bg-success-soft px-6 py-7 text-center" data-sale-complete>
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-success text-surface">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                </span>
                <h2 class="mt-3 text-lg font-extrabold tracking-tight text-ink">Sale complete</h2>
                <p class="mt-1 text-4xl font-extrabold tracking-tight tabular-nums text-ink">Rs {{ number_format($sale->total, 2) }}</p>
                <p class="mt-1 text-sm text-ink-muted">
                    @if ($due > 0)
                        Rs {{ number_format($due, 2) }} due{{ $sale->customer ? ' from '.$sale->customer->name : '' }}
                    @else
                        Paid in full
                    @endif
                    · {{ $sale->items->count() }} {{ \Illuminate\Support\Str::plural('line', $sale->items->count()) }}
                </p>
                <div class="mt-5 flex flex-col items-stretch justify-center gap-2 sm:flex-row sm:items-center">
                    <x-ui.button href="{{ route('sales.index') }}" wire:navigate variant="accent" size="lg" x-init="$el.focus()" data-next-sale class="justify-center px-8 py-3 text-lg">New Sale</x-ui.button>
                    <x-ui.button wire:click="downloadInvoice" variant="secondary" size="lg" class="justify-center">Download invoice</x-ui.button>
                </div>
                <p class="mt-3 hidden text-xs text-ink-faint sm:block">Press Enter to start the next sale.</p>
            </div>
        @endif

        @if ($sale->isCancelled())
            <x-ui.alert variant="danger" class="mb-4">
                <p class="font-semibold">This sale was cancelled{{ $sale->cancelled_at ? ' on '.$sale->cancelled_at->format('d M Y, h:i A') : '' }}.</p>
                @if ($sale->cancel_reason)
                    <p class="mt-1">Reason: {{ $sale->cancel_reason }}</p>
                @endif
                <p class="mt-1">Stock was restored and this sale no longer counts toward any total.</p>
            </x-ui.alert>
        @endif

        <x-ui.card>
            <x-slot name="actions">
                <div class="flex gap-2">
                    @if (! $sale->isCancelled() && auth()->user()->hasAccessTo('sales-edit-cancel'))
                        <x-ui.button href="{{ route('sales.edit', $sale) }}" wire:navigate variant="secondary" size="sm">Edit</x-ui.button>
                        <x-ui.button wire:click="openCancel" variant="secondary" size="sm" class="text-danger hover:bg-danger-soft">
                            Delete
                        </x-ui.button>
                    @endif
                    <x-ui.button wire:click="downloadInvoice" variant="secondary" size="sm">
                        Download PDF
                    </x-ui.button>
                </div>
            </x-slot>

            <div class="flex items-center justify-between border-b border-line pb-4">
                <div>
                    <p class="text-sm text-ink-faint">Date</p>
                    <p class="font-semibold text-ink">{{ $sale->created_at->format('d M Y, h:i A') }}</p>
                </div>
                @if ($sale->user)
                    <div class="text-right">
                        <p class="text-sm text-ink-faint">Served by</p>
                        <p class="font-semibold text-ink">{{ $sale->user->name }}</p>
                    </div>
                @endif
            </div>

            @if ($sale->customer)
                <div class="flex items-center justify-between border-b border-line py-4">
                    <p class="text-sm text-ink-faint">Customer</p>
                    <a href="{{ route('customers.show', $sale->customer) }}" wire:navigate class="font-semibold text-link hover:underline">
                        {{ $sale->customer->name }}
                    </a>
                </div>
            @endif

            <div class="mt-4 divide-y divide-line">
                @foreach ($sale->items as $item)
                    <div class="flex items-center justify-between gap-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-ink">{{ $item->product_name }}</p>
                            <p class="text-xs text-ink-faint">
                                {{ $item->unit->amount($item->quantity) }} × Rs {{ number_format($item->unit_price, 2) }}
                                @if ($item->discount_amount > 0)
                                    · Rs {{ number_format($item->discount_amount, 2) }} off
                                @endif
                            </p>
                        </div>
                        <span class="whitespace-nowrap text-sm font-bold tabular-nums text-ink">Rs {{ number_format($item->line_total, 2) }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 space-y-2 border-t border-line pt-4">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-ink-faint">Subtotal</span>
                    <span class="font-medium tabular-nums text-ink">Rs {{ number_format($sale->subtotal, 2) }}</span>
                </div>
                @if ((float) $sale->discount_amount > 0)
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-ink-faint">Discount</span>
                        <span class="font-medium tabular-nums text-ink">Rs {{ number_format($sale->discount_amount, 2) }}</span>
                    </div>
                @endif
                <div class="flex items-center justify-between border-t border-line pt-2">
                    <span class="font-semibold text-ink-faint">Total</span>
                    <span class="text-display-sm font-extrabold tabular-nums text-ink">Rs {{ number_format($sale->total, 2) }}</span>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between rounded-xl border border-line bg-surface-sunken/50 px-4 py-4">
                <div class="flex gap-6">
                    <div>
                        <p class="text-sm text-ink-faint">Paid</p>
                        <p class="font-bold tabular-nums text-ink">Rs {{ number_format($sale->amountPaid(), 2) }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-ink-faint">Due</p>
                        <p class="font-bold tabular-nums text-ink">Rs {{ number_format($sale->amountDue(), 2) }}</p>
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

            @php($recordedPayments = $sale->payments->filter(fn ($payment) => (float) $payment->amount != 0.0)->sortBy('id'))
            @if ($recordedPayments->isNotEmpty())
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($recordedPayments as $payment)
                        <li class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-ink-faint">{{ $payment->payment_date->format('d M Y') }} · Rs {{ number_format($payment->amount, 2) }}</span>
                            <span class="text-ink-muted">Paid by: <span class="font-semibold text-ink">{{ $payment->methodLabel() }}</span></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.modal name="cancel-sale-form" max-width="sm">
            <form wire:submit="cancelSale" class="p-6">
                <h2 class="text-lg font-bold text-ink">Cancel This Sale?</h2>
                <p class="mt-2 text-sm text-ink-muted">
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

                    <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="cancelSale">
                        Cancel Sale
                    </x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    </div>
</div>
