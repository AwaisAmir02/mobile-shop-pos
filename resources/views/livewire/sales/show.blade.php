<?php

use App\Models\Sale;
use App\Services\InvoicePdfService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Sale Receipt')] class extends Component
{
    public Sale $sale;

    public function mount(Sale $sale): void
    {
        $this->sale = $sale->load('items');
    }

    public function downloadInvoice(InvoicePdfService $pdf): StreamedResponse
    {
        return $pdf->download($this->sale);
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
        <x-ui.card>
            <x-slot name="actions">
                <x-ui.button wire:click="downloadInvoice" variant="secondary" size="sm">
                    Download PDF
                </x-ui.button>
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
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-500">Discount</span>
                    <span class="font-medium text-slate-900">Rs {{ number_format($sale->discount_amount, 2) }}</span>
                </div>
                <div class="flex items-center justify-between border-t border-slate-100 pt-2">
                    <span class="font-medium text-slate-500">Total</span>
                    <span class="text-display-sm text-slate-900">Rs {{ number_format($sale->total, 2) }}</span>
                </div>
            </div>
        </x-ui.card>

        <div class="mt-4 text-center">
            <a href="{{ route('sales.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                Start a new sale →
            </a>
        </div>
    </div>
</div>
