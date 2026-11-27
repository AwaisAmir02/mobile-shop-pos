<?php

use App\Actions\GiftVouchers;
use App\Livewire\Concerns\PicksPaymentMethod;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\GiftVoucher;
use App\Services\GiftVoucherPdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gift Vouchers (salons, spas): sold for money now, spent at New Sale with
 * the code by whoever holds it. What's left is worked out from the bills that
 * used it.
 */
new #[Layout('layouts.app')] #[Title('Gift Vouchers')] class extends Component
{
    use PicksPaymentMethod, Toasts;

    public string $amount = '';
    public string $buyerCustomerId = '';
    public string $buyerName = '';
    public string $recipientName = '';
    public string $expiresOn = '';
    public string $search = '';

    public function mount(): void
    {
        $this->paymentMethod = 'cash';
        $this->expiresOn = now('Asia/Karachi')->addMonths(6)->toDateString();
    }

    public function sell(): void
    {
        $this->validate(['amount' => ['required', 'numeric', 'min:1', 'max:9999999'], 'expiresOn' => ['nullable', 'date', 'after:today'], 'buyerName' => ['nullable', 'string', 'max:100'], 'recipientName' => ['nullable', 'string', 'max:100']]);
        [$method, $account] = $this->chosenPaymentMethod();
        $voucher = GiftVouchers::sell([
            'amount' => $this->amount,
            'buyer_customer_id' => $this->buyerCustomerId !== '' ? (int) $this->buyerCustomerId : null,
            'buyer_name' => $this->buyerName ?: null,
            'recipient_name' => $this->recipientName ?: null,
            'expires_on' => $this->expiresOn ?: null,
            'payment_method' => $method,
            'shop_account_id' => $account,
        ], Auth::id(), Auth::user()->shop_id);

        $this->reset(['amount', 'buyerCustomerId', 'buyerName', 'recipientName']);
        $this->toastSuccess('Voucher '.$voucher->code.' sold.');
    }

    public function cancel(int $id): void
    {
        GiftVouchers::cancel(GiftVoucher::findOrFail($id));
        $this->toastSuccess('Voucher cancelled.');
    }

    public function download(int $id, GiftVoucherPdfService $pdf): StreamedResponse
    {
        return $pdf->download(GiftVoucher::findOrFail($id));
    }

    public function with(): array
    {
        return [
            'vouchers' => GiftVoucher::query()->with('buyer')->when(trim($this->search) !== '', fn ($q) => $q->where('code', 'like', '%'.strtoupper(trim($this->search)).'%')->orWhere('recipient_name', 'like', '%'.trim($this->search).'%'))->latest('id')->limit(100)->get(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'paymentAccounts' => $this->paymentAccounts(),
            'today' => now('Asia/Karachi')->toDateString(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Sold for money now, spent on a bill later with the code">Gift Vouchers</x-ui.page-header>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card title="Sell a voucher" data-sell-voucher>
            <form wire:submit="sell" class="space-y-3">
                <x-ui.field label="Value (Rs)" name="amount" for="voucherAmount"><x-ui.input wire:model="amount" id="voucherAmount" type="number" min="1" step="1" /></x-ui.field>
                <x-ui.field label="Bought by" name="buyerCustomerId" for="voucherBuyer">
                    <x-ui.select wire:model.live="buyerCustomerId" id="voucherBuyer">
                        <option value="">Not saved: type a name</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                @if ($buyerCustomerId === '')
                    <x-ui.field label="Buyer's name" name="buyerName" for="voucherBuyerName"><x-ui.input wire:model="buyerName" id="voucherBuyerName" /></x-ui.field>
                @endif
                <x-ui.field label="For (printed on it)" name="recipientName" for="voucherFor"><x-ui.input wire:model="recipientName" id="voucherFor" /></x-ui.field>
                <x-ui.field label="Valid until" name="expiresOn" for="voucherExpires" help="Blank: no end date"><x-ui.input wire:model="expiresOn" id="voucherExpires" type="date" /></x-ui.field>
                <x-sales.payment-method-picker :accounts="$paymentAccounts" :value="$paymentMethod" amount="amount" />
                <x-ui.button type="submit">Sell voucher</x-ui.button>
            </form>
        </x-ui.card>

        <div class="lg:col-span-2">
            <div class="mb-3"><x-ui.input wire:model.live.debounce.300ms="search" type="search" placeholder="Code or name" class="w-64" aria-label="Search vouchers" /></div>
            @if ($vouchers->isEmpty())
                <x-ui.empty-state title="No vouchers yet" description="A voucher is spent at New Sale by typing its code." />
            @else
                <x-ui.table :headers="['Code', 'For', 'Value', 'Left', 'Valid until', '']" data-vouchers>
                    @foreach ($vouchers as $voucher)
                        @php($left = $voucher->remaining())
                        <tr wire:key="voucher-{{ $voucher->id }}">
                            <td class="px-4 py-2.5 font-mono text-sm font-semibold text-ink">{{ $voucher->code }}</td>
                            <td class="px-4 py-2.5 text-sm text-ink-muted">{{ $voucher->recipient_name }}<span class="block text-xs text-ink-faint">from {{ $voucher->buyer_name ?? $voucher->buyer?->name ?? '—' }}, {{ $voucher->sold_on->format('d M Y') }}</span></td>
                            <td class="px-4 py-2.5 text-sm tabular-nums text-ink">Rs {{ number_format((float) $voucher->amount, 0) }}</td>
                            <td class="px-4 py-2.5 text-sm tabular-nums font-semibold text-ink">{{ $voucher->status === \App\Enums\RecordStatus::Cancelled ? 'Cancelled' : 'Rs '.number_format($left, 0) }}</td>
                            <td class="px-4 py-2.5 text-sm {{ $voucher->isExpiredOn($today) ? 'text-danger' : 'text-ink-muted' }}">{{ $voucher->expires_on?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <div class="flex justify-end gap-1">
                                    <x-ui.button size="sm" variant="secondary" wire:click="download({{ $voucher->id }})">PDF</x-ui.button>
                                    @if ($voucher->status !== \App\Enums\RecordStatus::Cancelled && $voucher->used() <= 0)
                                        <x-ui.button size="sm" variant="danger-ghost" wire:click="cancel({{ $voucher->id }})" confirm="Cancel this voucher? Hand the money back first.">Cancel</x-ui.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </div>
    </div>

    <livewire:shop-accounts.quick-create />
</div>
