<?php

use App\Actions\SalePaymentLinks;
use App\Livewire\Concerns\Toasts;
use App\Models\Sale;
use App\Models\SalePaymentLink;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * A JazzCash link for this one order (O7), on the sale page of an online shop
 * with JazzCash connected. Blank amount: the whole of what's owed.
 */
new class extends Component
{
    use Toasts;

    #[Locked]
    public int $saleId;

    public string $linkAmount = '';

    public function mount(int $saleId): void
    {
        $this->saleId = $saleId;
    }

    public function create(): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales') && SalePaymentLinks::available(Auth::user()->shop), 403);
        $this->validate(['linkAmount' => ['nullable', 'numeric', 'min:0']], [], ['linkAmount' => 'amount']);

        SalePaymentLinks::create(Sale::findOrFail($this->saleId), $this->linkAmount !== '' ? (float) $this->linkAmount : null, Auth::id());
        $this->linkAmount = '';
        $this->toastSuccess('Payment link ready. Send it to the customer.');
    }

    public function cancelLink(int $id): void
    {
        SalePaymentLinks::cancel(SalePaymentLink::where('sale_id', $this->saleId)->findOrFail($id));
    }

    public function with(): array
    {
        $sale = Sale::with('customer')->findOrFail($this->saleId);
        $link = SalePaymentLink::where('sale_id', $sale->id)->whereNull('cancelled_at')->latest('id')->first();

        return [
            'sale' => $sale,
            'link' => $link,
            'asked' => $link ? SalePaymentLinks::amountDue($link) : 0.0,
            'whatsapp' => $link && $sale->customer?->phone
                ? 'https://wa.me/'.\App\Support\PhoneNumber::normalize($sale->customer->phone).'?text='.rawurlencode('Assalam o Alaikum! Pay for your order '.$sale->invoiceNumber().' here: '.$link->url())
                : null,
        ];
    }
}; ?>

<div class="rounded-xl border border-line px-4 py-3 text-sm" data-payment-link>
    @if ($link)
        <p class="font-medium text-ink">JazzCash link for Rs {{ number_format($asked, 2) }}</p>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <input type="text" readonly value="{{ $link->url() }}" x-on:focus="$el.select()" class="min-w-0 flex-1 rounded-lg border-line-strong bg-surface-sunken py-1.5 text-xs text-ink" aria-label="Payment link">
            @if ($whatsapp)
                <x-ui.button size="sm" variant="secondary" href="{{ $whatsapp }}" target="_blank">Send on WhatsApp</x-ui.button>
            @endif
            <x-ui.button size="sm" variant="ghost" wire:click="cancelLink({{ $link->id }})">Stop link</x-ui.button>
        </div>
    @elseif ($sale->amountDue() >= \App\Actions\CustomerPayments::MIN_AMOUNT && ! $sale->isCancelled() && $sale->customer_id)
        <form wire:submit="create" class="flex flex-wrap items-end gap-2">
            <x-ui.field label="Ask online by JazzCash" name="linkAmount" for="linkAmount" :help="'Blank: all Rs '.number_format($sale->amountDue(), 2).'. Or an advance, like the delivery charge.'" class="min-w-0 flex-1">
                <x-ui.input wire:model="linkAmount" id="linkAmount" type="number" min="0" step="1" placeholder="{{ number_format($sale->amountDue(), 0, '.', '') }}" />
            </x-ui.field>
            <x-ui.button type="submit" size="sm">Make payment link</x-ui.button>
        </form>
    @else
        <p class="text-ink-faint">Nothing to collect online on this order.</p>
    @endif
</div>
