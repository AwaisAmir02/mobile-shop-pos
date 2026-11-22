<?php

use App\Actions\Buybacks;
use App\Enums\BuybackCondition;
use App\Livewire\Concerns\ChoosesPaymentSource;
use App\Livewire\Concerns\Toasts;
use App\Models\Buyback;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\StockIn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Used Phone Buyback: buy a used phone from a customer into stock (a Mobile
 * product plus one paid, vendor-less batch; see App\Actions\Buybacks), and
 * the list of phones bought. Mobile & Electronics shops only.
 */
new #[Layout('layouts.app')] #[Title('Used Phone Buyback')] class extends Component
{
    use ChoosesPaymentSource, Toasts, WithPagination;

    public string $seller_name = '';
    public string $seller_phone = '';
    public string $seller_cnic = '';
    public string $brand = '';
    public string $model = '';
    public string $imei = '';
    public string $condition = 'good';
    public string $battery_health = '';
    public string $pta = '';
    public bool $has_box = false;
    public bool $has_charger = false;
    public string $price = '';
    public string $selling_price = '';
    public string $bought_on = '';
    public string $note = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->sellsDevices(), 404);

        $this->bought_on = now()->toDateString();
    }

    public function save(): void
    {
        abort_unless(Auth::user()->shop?->sellsDevices() && Auth::user()->hasAccessTo('buybacks'), 403);

        // pta_approved comes from the three-way "pta" choice below.
        $this->validate(array_merge(collect(Buybacks::rules())->except('pta_approved')->all(), ['pta' => ['nullable', 'in:yes,no']]), [], [
            'seller_name' => 'seller name', 'seller_phone' => 'seller phone', 'seller_cnic' => 'CNIC',
            'price' => 'price paid', 'selling_price' => 'selling price', 'bought_on' => 'date', 'battery_health' => 'battery health',
        ]);

        [$method, $accountId] = $this->paidFromParts();

        $buyback = Buybacks::record(Auth::user()->shop, [
            'seller_name' => $this->seller_name,
            'seller_phone' => $this->seller_phone,
            'seller_cnic' => $this->seller_cnic,
            'brand' => $this->brand,
            'model' => $this->model,
            'imei' => $this->imei,
            'condition' => $this->condition,
            'battery_health' => $this->battery_health !== '' ? (int) $this->battery_health : null,
            'pta_approved' => match ($this->pta) { 'yes' => true, 'no' => false, default => null },
            'has_box' => $this->has_box,
            'has_charger' => $this->has_charger,
            'price' => $this->price,
            'selling_price' => $this->selling_price,
            'bought_on' => $this->bought_on,
            'note' => $this->note,
        ], Auth::id(), $method, $accountId);

        $this->reset(['seller_name', 'seller_phone', 'seller_cnic', 'brand', 'model', 'imei', 'battery_health', 'pta', 'has_box', 'has_charger', 'price', 'selling_price', 'note']);
        $this->condition = 'good';
        $this->paidFrom = 'cash';
        $this->resetPage();

        $this->toastSuccess($buyback->phoneName().' bought for Rs '.number_format((float) $buyback->price).' and added to stock.');
    }

    public function cancel(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('buybacks-cancel'), 403);

        $buyback = Buyback::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);

        try {
            Buybacks::cancel($buyback, Auth::id());
        } catch (ValidationException $e) {
            $this->toastError(collect($e->errors())->flatten()->first());

            return;
        }

        $this->toastSuccess('Buyback of '.$buyback->phoneName().' cancelled; the phone is out of stock and its payment reversed.');
    }

    public function with(): array
    {
        $buybacks = Buyback::withoutGlobalScope(ExcludesCancelledScope::class)
            ->with(['product', 'customer', 'sale'])
            ->latest('bought_on')->latest('id')
            ->paginate(15);

        $remaining = StockIn::withoutGlobalScopes()->whereIn('id', $buybacks->pluck('stock_in_id'))->pluck('quantity_remaining', 'id');

        return [
            'buybacks' => $buybacks,
            'remaining' => $remaining,
            'conditions' => BuybackCondition::cases(),
            'canCancel' => Auth::user()->hasAccessTo('buybacks-cancel'),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Buy a used phone from a customer and put it in stock to resell">
            Used Phone Buyback
        </x-ui.page-header>
    </x-slot>

    <div class="space-y-6">
        <x-ui.card title="Buy a phone">
            <form wire:submit="save" class="space-y-4" data-buyback-form>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Seller name" name="seller_name" for="seller_name" required class="sm:col-span-2">
                        <x-ui.input wire:model="seller_name" id="seller_name" autocomplete="off" />
                    </x-ui.field>
                    <x-ui.field label="Seller phone" name="seller_phone" for="seller_phone" help="Links to the customer with this number, if there is one.">
                        <x-ui.input wire:model="seller_phone" id="seller_phone" type="tel" inputmode="tel" />
                    </x-ui.field>
                    <x-ui.field label="Seller CNIC" name="seller_cnic" for="seller_cnic" help="Recommended: proof of who sold it.">
                        <x-ui.input wire:model="seller_cnic" id="seller_cnic" inputmode="numeric" placeholder="35202-1234567-1" />
                    </x-ui.field>

                    <x-ui.field label="Brand" name="brand" for="brand" required>
                        <x-ui.input wire:model="brand" id="brand" placeholder="e.g. Samsung" />
                    </x-ui.field>
                    <x-ui.field label="Model" name="model" for="model">
                        <x-ui.input wire:model="model" id="model" placeholder="e.g. Galaxy A54 8/256" />
                    </x-ui.field>
                    <x-ui.field label="IMEI" name="imei" for="imei" class="sm:col-span-2" help="Dial *#06# on the phone. A phone this shop sold before is restocked on its old product.">
                        <x-ui.input wire:model="imei" id="imei" inputmode="numeric" autocomplete="off" />
                    </x-ui.field>

                    <x-ui.field label="Condition" name="condition" for="condition" required>
                        <x-ui.select wire:model="condition" id="condition">
                            @foreach ($conditions as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field label="Battery health %" name="battery_health" for="battery_health">
                        <x-ui.input wire:model="battery_health" id="battery_health" type="number" min="0" max="100" />
                    </x-ui.field>
                    <x-ui.field label="PTA status" name="pta" for="pta">
                        <x-ui.select wire:model="pta" id="pta">
                            <option value="">Not checked</option>
                            <option value="yes">PTA approved</option>
                            <option value="no">Not PTA approved</option>
                        </x-ui.select>
                    </x-ui.field>
                    <div class="flex items-end gap-4 pb-2.5">
                        <x-ui.checkbox wire:model="has_box" label="Box" />
                        <x-ui.checkbox wire:model="has_charger" label="Charger" />
                    </div>

                    <x-ui.field label="Price paid (Rs)" name="price" for="price" required>
                        <x-ui.input wire:model="price" id="price" type="number" min="0" step="0.01" />
                    </x-ui.field>
                    <x-ui.field label="Selling price (Rs)" name="selling_price" for="selling_price" required>
                        <x-ui.input wire:model="selling_price" id="selling_price" type="number" min="0" step="0.01" />
                    </x-ui.field>

                    <x-ledger.paid-from :value="$paidFrom" />
                    <x-ui.field label="Date" name="bought_on" for="bought_on" required>
                        <x-ui.input wire:model="bought_on" id="bought_on" type="date" max="{{ now()->toDateString() }}" />
                    </x-ui.field>

                    <x-ui.field label="Note" name="note" for="note" class="sm:col-span-2">
                        <x-ui.input wire:model="note" id="note" placeholder="Scratches, repairs, anything to remember" />
                    </x-ui.field>
                </div>

                <div class="flex justify-end">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Buy and add to stock</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <div>
            @if ($buybacks->isEmpty())
                <x-ui.empty-state title="No phones bought yet" description="Each phone you buy here becomes a product with one in stock, at the price you paid as its cost." />
            @else
                <x-ui.table stack :headers="['Phone', 'Seller', 'Paid', 'Status', '']">
                    @foreach ($buybacks as $buyback)
                        @php
                            $sold = ! $buyback->isCancelled() && (float) ($remaining[$buyback->stock_in_id] ?? 0) <= 0;
                        @endphp
                        <x-ui.table-row wire:key="buyback-{{ $buyback->id }}" data-buyback="{{ $buyback->id }}">
                            <x-ui.table-cell primary>
                                <span class="font-medium text-ink">{{ $buyback->phoneName() }}</span>
                                <x-ui.badge :variant="$buyback->condition->badge()" class="ml-1">{{ $buyback->condition->label() }}</x-ui.badge>
                                <span class="block text-xs text-ink-faint">
                                    {{ $buyback->imei ? 'IMEI '.$buyback->imei : 'No IMEI' }}
                                    @if ($buyback->battery_health !== null) · Battery {{ $buyback->battery_health }}% @endif
                                    @if ($buyback->pta_approved !== null) · {{ $buyback->pta_approved ? 'PTA approved' : 'Not PTA' }} @endif
                                </span>
                            </x-ui.table-cell>
                            <x-ui.table-cell label="Seller">
                                {{ $buyback->seller_name }}
                                <span class="block text-xs text-ink-faint">{{ collect([$buyback->seller_phone, $buyback->seller_cnic])->filter()->implode(' · ') ?: '—' }}</span>
                            </x-ui.table-cell>
                            <x-ui.table-cell label="Paid">
                                <span class="tabular-nums">Rs {{ number_format((float) $buyback->price) }}</span>
                                <span class="block text-xs text-ink-faint">{{ $buyback->bought_on->format('d M Y') }} · sells at Rs {{ number_format((float) $buyback->selling_price) }}</span>
                                @if ($buyback->isTradeIn() && $buyback->sale)
                                    <span class="block text-xs text-ink-faint" data-trade-in-of="{{ $buyback->sale_id }}">Trade-in on <a href="{{ route('sales.show', $buyback->sale) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $buyback->sale->invoiceNumber() }}</a></span>
                                @endif
                            </x-ui.table-cell>
                            <x-ui.table-cell label="Status">
                                @if ($buyback->isCancelled())
                                    <x-ui.badge variant="neutral">Cancelled</x-ui.badge>
                                @elseif ($sold)
                                    <x-ui.badge variant="success">Sold</x-ui.badge>
                                @else
                                    <x-ui.badge variant="brand">In stock</x-ui.badge>
                                @endif
                            </x-ui.table-cell>
                            <x-ui.table-cell actions align="right">
                                @if ($canCancel && ! $buyback->isCancelled() && ! $sold && ! $buyback->isTradeIn())
                                    <x-ui.button size="sm" variant="ghost" wire:click="cancel({{ $buyback->id }})" confirm="Cancel the buyback of {{ $buyback->phoneName() }}? The phone comes out of stock and the Rs {{ number_format((float) $buyback->price) }} paid is reversed.">Cancel</x-ui.button>
                                @endif
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>

                <div class="mt-4">{{ $buybacks->links() }}</div>
            @endif
        </div>
    </div>
</div>
