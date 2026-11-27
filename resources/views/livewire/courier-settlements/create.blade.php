<?php

use App\Actions\CourierSettlements;
use App\Enums\CourierKey;
use App\Livewire\Concerns\PicksPaymentMethod;
use App\Livewire\Concerns\Toasts;
use App\Models\Shipment;
use App\Support\BatchExpiry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Records one courier payout (O4) against the parcels it covers, as the
 * courier's payment statement lists them: what it collected on each, what it
 * charged, and what it withheld. App\Actions\CourierSettlements does the rest.
 */
new #[Layout('layouts.app')] #[Title('Record Courier Payout')] class extends Component
{
    use PicksPaymentMethod, Toasts;

    #[Url]
    public string $courier = '';

    #[Url]
    public string $name = '';

    public string $settlementDate = '';
    public string $reference = '';
    public string $taxWithheld = '';
    public string $otherDeductions = '';
    public string $note = '';

    /** @var array<int, array{picked: bool, cod_collected: string, courier_charge: string, return_charge: string}> by shipment id */
    public array $lines = [];

    public function mount(): void
    {
        abort_unless(CourierKey::tryFrom($this->courier) !== null, 404);

        $this->settlementDate = BatchExpiry::today();
        $this->paymentMethod = '';

        foreach ($this->waiting() as $shipment) {
            $line = CourierSettlements::defaultLine($shipment);
            $this->lines[$shipment->id] = [
                'picked' => true,
                'cod_collected' => (string) $line['cod_collected'],
                'courier_charge' => '',
                'return_charge' => $line['return_charge'] > 0 ? (string) $line['return_charge'] : '',
            ];
        }
    }

    protected function courierKey(): CourierKey
    {
        return CourierKey::from($this->courier);
    }

    protected function waiting()
    {
        return CourierSettlements::waiting($this->courierKey(), $this->name !== '' ? $this->name : null);
    }

    /** @return array{collected: float, charges: float, tax: float, other: float, net: float, count: int} */
    public function totals(): array
    {
        $picked = array_filter($this->lines, fn (array $line) => $line['picked']);
        $number = fn ($value) => is_numeric($value) ? (float) $value : 0.0;
        $collected = round(array_sum(array_map(fn ($line) => $number($line['cod_collected']), $picked)), 2);
        $charges = round(array_sum(array_map(fn ($line) => $number($line['courier_charge']) + $number($line['return_charge']), $picked)), 2);
        $tax = round($number($this->taxWithheld), 2);
        $other = round($number($this->otherDeductions), 2);

        return ['collected' => $collected, 'charges' => $charges, 'tax' => $tax, 'other' => $other, 'net' => round($collected - $charges - $tax - $other, 2), 'count' => count($picked)];
    }

    public function save(): void
    {
        $this->validate(CourierSettlements::rules(), [], ['settlementDate' => 'payout date', 'taxWithheld' => 'tax withheld', 'otherDeductions' => 'other deductions']);

        [$method, $accountId] = $this->chosenPaymentMethod();
        $picked = collect($this->lines)->filter(fn (array $line) => $line['picked'])
            ->map(fn (array $line, int $id) => ['shipment_id' => $id, 'cod_collected' => (float) ($line['cod_collected'] ?: 0), 'courier_charge' => (float) ($line['courier_charge'] ?: 0), 'return_charge' => (float) ($line['return_charge'] ?: 0)])
            ->values()->all();

        $settlement = CourierSettlements::record(Auth::user()->shop, $this->courierKey(), $picked, [
            'settlement_date' => $this->settlementDate,
            'reference' => $this->reference,
            'tax_withheld' => $this->taxWithheld ?: 0,
            'other_deductions' => $this->otherDeductions ?: 0,
            'payment_method' => $method,
            'shop_account_id' => $accountId,
            'note' => $this->note,
        ], Auth::id());

        $this->toastSuccess('Payout '.$settlement->number().' recorded: Rs '.number_format((float) $settlement->net_amount, 2).' paid to you.');
        $this->redirectRoute('courier-settlements.show', $settlement, navigate: true);
    }

    public function with(): array
    {
        return [
            'shipments' => $this->waiting()->keyBy('id'),
            'courierLabel' => $this->courierKey() === CourierKey::Other && $this->name !== '' ? $this->name : $this->courierKey()->label(),
            'paymentAccounts' => $this->paymentAccounts(),
            'totals' => $this->totals(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('courier-settlements.index') }}" back-label="Courier Payouts" subtitle="Tick the parcels on {{ $courierLabel }}'s payment statement and copy its amounts">Record a {{ $courierLabel }} Payout</x-ui.page-header>
    </x-slot>

    @if ($shipments->isEmpty())
        <x-ui.empty-state title="Nothing waiting for a payout" description="{{ $courierLabel }} has no delivered or returned parcels left to pay out." />
    @else
        <form wire:submit="save" class="space-y-6">
            @error('lines') <x-ui.alert variant="danger">{{ $message }}</x-ui.alert> @enderror

            <x-ui.table stack :headers="['', 'Parcel', 'Status', 'Cash collected', 'Delivery charge', 'Return charge']" data-payout-lines>
                @foreach ($shipments as $shipment)
                    <x-ui.table-row wire:key="line-{{ $shipment->id }}" data-payout-line="{{ $shipment->id }}">
                        <x-ui.table-cell><x-ui.checkbox wire:model.live="lines.{{ $shipment->id }}.picked" label="" aria-label="On this payout" /></x-ui.table-cell>
                        <x-ui.table-cell primary>
                            <span class="font-semibold text-ink">{{ $shipment->tracking_number }}</span>
                            <span class="block text-xs text-ink-faint">{{ $shipment->sale?->invoiceNumber() }} · {{ $shipment->consignee_name }} · COD Rs {{ number_format($shipment->cod_amount, 2) }}</span>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Status"><x-ui.badge :variant="$shipment->status->variant()">{{ $shipment->status->label() }}</x-ui.badge></x-ui.table-cell>
                        <x-ui.table-cell label="Cash collected">
                            <x-ui.input wire:model.live.debounce.400ms="lines.{{ $shipment->id }}.cod_collected" type="number" min="0" step="0.01" :disabled="$shipment->status !== \App\Enums\ShipmentStatus::Delivered" aria-label="Cash collected on {{ $shipment->tracking_number }}" />
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Delivery charge">
                            <x-ui.input wire:model.live.debounce.400ms="lines.{{ $shipment->id }}.courier_charge" type="number" min="0" step="0.01" placeholder="0" aria-label="Delivery charge on {{ $shipment->tracking_number }}" />
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Return charge">
                            <x-ui.input wire:model.live.debounce.400ms="lines.{{ $shipment->id }}.return_charge" type="number" min="0" step="0.01" placeholder="0" aria-label="Return charge on {{ $shipment->tracking_number }}" />
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>

            <x-ui.card>
                <div class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">
                    <x-ui.field label="Payout date" name="settlementDate" for="settlementDate" help="The day the money reached you">
                        <x-ui.input wire:model="settlementDate" id="settlementDate" type="date" max="{{ \App\Support\BatchExpiry::today() }}" />
                    </x-ui.field>
                    <x-ui.field label="Statement or payment number" name="reference" for="reference" help="Optional, as the courier prints it">
                        <x-ui.input wire:model="reference" id="reference" maxlength="60" />
                    </x-ui.field>
                    <x-ui.field label="Tax withheld" name="taxWithheld" for="taxWithheld" help="Income tax and sales tax the courier kept back">
                        <x-ui.input wire:model.live.debounce.400ms="taxWithheld" id="taxWithheld" type="number" min="0" step="0.01" placeholder="0" />
                    </x-ui.field>
                    <x-ui.field label="Other deductions" name="otherDeductions" for="otherDeductions" help="Anything else taken off, like packaging or GST on charges">
                        <x-ui.input wire:model.live.debounce.400ms="otherDeductions" id="otherDeductions" type="number" min="0" step="0.01" placeholder="0" />
                    </x-ui.field>
                    <div class="sm:col-span-2" wire:key="paid-into-{{ $paymentMethod }}">
                        <x-ui.field label="Paid into" name="paymentMethod" for="paymentMethod" help="Where the courier sent the money">
                            <x-ui.select wire:model.live="paymentMethod" id="paymentMethod">
                                <option value="">Choose…</option>
                                <option value="cash">Cash</option>
                                @foreach ($paymentAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }} · {{ $account->provider_type }}</option>
                                @endforeach
                                <option value="__create__">+ New account</option>
                            </x-ui.select>
                        </x-ui.field>
                    </div>
                    <x-ui.field label="Note" name="note" for="note" class="sm:col-span-2">
                        <x-ui.input wire:model="note" id="note" maxlength="255" />
                    </x-ui.field>
                </div>

                <dl class="space-y-1 border-t border-line p-5 text-sm" data-payout-totals>
                    <div class="flex justify-between"><dt class="text-ink-faint">Cash collected · {{ $totals['count'] }} {{ \Illuminate\Support\Str::plural('parcel', $totals['count']) }}</dt><dd class="tabular-nums text-ink">Rs {{ number_format($totals['collected'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Delivery and return charges</dt><dd class="tabular-nums text-ink">− Rs {{ number_format($totals['charges'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Tax withheld</dt><dd class="tabular-nums text-ink">− Rs {{ number_format($totals['tax'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Other deductions</dt><dd class="tabular-nums text-ink">− Rs {{ number_format($totals['other'], 2) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2 text-base"><dt class="font-semibold text-ink">Paid to you</dt><dd class="font-bold tabular-nums text-ink" data-payout-net>Rs {{ number_format($totals['net'], 2) }}</dd></div>
                </dl>

                <div class="flex justify-end border-t border-line p-5">
                    <x-ui.button type="submit" wire:loading.attr="disabled" data-save-payout>Record Payout</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    @endif

    <livewire:shop-accounts.quick-create />
</div>
