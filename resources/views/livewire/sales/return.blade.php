<?php

use App\Actions\SaleReturns;
use App\Livewire\Concerns\ChoosesPaymentSource;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Sale;
use Livewire\Attributes\On;
use App\Models\SaleReturnItem;
use App\Support\BatchExpiry;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Return Items')] class extends Component
{
    use ChoosesPaymentSource;
    use Toasts;

    public Sale $sale;

    /** @var array<string, string> by line key */
    public array $quantities = [];

    /** @var array<string, string> by line key: restock or damaged */
    public array $dispositions = [];

    public string $returnDate = '';
    public string $reason = '';
    public string $lateReason = '';

    /** 'cash' or 'credit': where what isn't owed goes. */
    public string $refundTo = 'cash';

    /** For store credit on a walk-in sale: the customer it goes to. */
    public string $creditCustomerId = '';

    public function mount(Sale $sale): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales-returns'), 403);
        abort_if($sale->isCancelled(), 403, 'A cancelled sale can\'t have returns.');

        $this->sale = $sale;
        $this->returnDate = BatchExpiry::today();
        $this->refundTo = Auth::user()->shop->cash_refunds_allowed ? SaleReturns::REFUND_CASH : SaleReturns::REFUND_CREDIT;

        foreach (SaleReturns::groups($sale) as $key => $group) {
            $this->quantities[$key] = '';
            $this->dispositions[$key] = SaleReturnItem::RESTOCK;
        }
    }

    /** Takes back everything still left on the sale. */
    public function returnAll(): void
    {
        foreach (SaleReturns::groups($this->sale) as $key => $group) {
            $this->quantities[$key] = Quantity::compare($group['returnable'], 0) > 0 ? Quantity::plain($group['returnable']) : '';
        }
    }

    public function updatedCreditCustomerId(): void
    {
        if ($this->creditCustomerId === '__create__') {
            $this->creditCustomerId = '';
            $this->dispatch('open-modal', name: 'quick-create-customer');
        }
    }

    #[On('customer-created')]
    public function onCustomerCreated(int $customerId): void
    {
        $this->creditCustomerId = (string) $customerId;
    }

    protected function lines(): array
    {
        return collect($this->quantities)->map(fn ($quantity, $key) => [
            'quantity' => $quantity,
            'disposition' => $this->dispositions[$key] ?? SaleReturnItem::RESTOCK,
        ])->all();
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales-returns'), 403);

        $this->validate([
            'returnDate' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'lateReason' => ['nullable', 'string', 'max:'.SaleReturns::MAX_LATE_REASON],
        ]);

        [$method, $accountId] = $this->paidFromParts();

        $return = SaleReturns::create(
            $this->sale,
            $this->lines(),
            $method,
            $accountId,
            $this->reason,
            Auth::id(),
            $this->returnDate,
            $this->lateReason,
            byOwner: (bool) Auth::user()->is_owner,
            refundTo: Auth::user()->shop->cash_refunds_allowed ? $this->refundTo : SaleReturns::REFUND_CREDIT,
            creditCustomerId: ctype_digit($this->creditCustomerId) ? (int) $this->creditCustomerId : null,
        );

        $message = $return->number().' recorded.';

        if ((float) $return->refund_cash > 0) {
            $message .= ' Hand back Rs '.number_format((float) $return->refund_cash, 2).'.';
        }

        if ((float) $return->refund_credit > 0) {
            $message .= ' Rs '.number_format((float) $return->refund_credit, 2).' added to '.($return->customer?->name ?? 'the customer').'\'s store credit.';
        }

        session()->flash('status', $message);
        $this->redirectRoute('sales.show', $this->sale, navigate: true);
    }

    public function with(): array
    {
        $shop = Auth::user()->shop;
        $groups = SaleReturns::groups($this->sale);
        $value = SaleReturns::previewValue($this->sale, $this->lines());
        $owed = $this->sale->amountDue();
        $againstDue = round(min($value, $owed), 2);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->returnDate) ? $this->returnDate : BatchExpiry::today();

        return [
            'groups' => $groups,
            'value' => $value,
            'owed' => $owed,
            'againstDue' => $againstDue,
            'refund' => round($value - $againstDue, 2),
            'cashRefunds' => (bool) $shop->cash_refunds_allowed,
            'daysLate' => SaleReturns::daysLate($this->sale, $shop, $date),
            'window' => (int) $shop->return_window_days,
            'isOwner' => (bool) Auth::user()->is_owner,
            'tradeIn' => SaleReturns::hadTradeIn($this->sale),
            'saleDate' => SaleReturns::saleDate($this->sale),
            'nothingLeft' => collect($groups)->every(fn (array $group) => Quantity::compare($group['returnable'], 0) <= 0),
            'toCredit' => ! $shop->cash_refunds_allowed || $this->refundTo === SaleReturns::REFUND_CREDIT,
            'customers' => $this->sale->customer_id === null ? Customer::query()->orderBy('name')->get(['id', 'name', 'phone']) : collect(),
            'creditBalance' => $this->sale->customer_id !== null ? \App\Actions\StoreCredit::balance($this->sale->customer_id) : null,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('sales.show', $sale) }}" :back-label="$sale->invoiceNumber()" subtitle="Take back items from this sale">Return Items</x-ui.page-header>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-5">
        @if ($tradeIn)
            <x-ui.alert variant="warning" data-trade-in-warning>
                A traded-in phone paid for part of this sale. It stays with the shop: what's handed back on this return is paid in money, so in effect the shop has bought that phone at the agreed price. To give the phone back instead, cancel the whole sale.
            </x-ui.alert>
        @endif

        @if ($daysLate !== null)
            <x-ui.alert :variant="$isOwner ? 'warning' : 'danger'" data-return-late>
                This sale was {{ $daysLate }} days ago, past this shop's {{ $window }}-day limit for returns.
                {{ $isOwner ? 'As the owner you can still take it back; say why below.' : 'Only the owner can take it back now.' }}
            </x-ui.alert>
        @endif

        <x-ui.card :padding="false">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3 sm:px-5">
                <div>
                    <h3 class="text-base font-bold tracking-tight text-ink">What's coming back?</h3>
                    <p class="text-sm text-ink-faint">Sold on {{ \Carbon\Carbon::parse($saleDate)->format('d M Y') }}{{ $sale->customer ? ' to '.$sale->customer->name : '' }}</p>
                </div>
                @unless ($nothingLeft)
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="returnAll">Return everything</x-ui.button>
                @endunless
            </div>

            @error('lines')
                <p class="border-b border-line bg-danger-soft px-4 py-2.5 text-sm text-danger sm:px-5">{{ $message }}</p>
            @enderror

            <ul class="divide-y divide-line">
                @foreach ($groups as $key => $group)
                    @php($left = \App\Support\Quantity::compare($group['returnable'], 0) > 0)
                    <li wire:key="return-line-{{ $key }}" class="grid grid-cols-1 gap-3 px-4 py-3.5 sm:grid-cols-[minmax(0,1fr)_7rem_10rem] sm:items-start sm:px-5">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-ink">{{ $group['name'] }}</p>
                            <p class="text-xs text-ink-faint">
                                Sold {{ $group['unit']->amount($group['sold']) }}@if ($group['pack'] && is_int($group['sold']) && $group['sold'] % $group['pack']['size'] === 0) ({{ \App\Models\Product::countLabel($group['pack']['name'], intdiv($group['sold'], $group['pack']['size'])) }})@endif · Rs {{ number_format($group['unit_value'], 2) }}{{ $group['unit']->perSuffix() }} after discounts
                                @if (\App\Support\Quantity::compare($group['returned'], 0) > 0)
                                    · {{ $group['unit']->amount($group['returned']) }} already returned
                                @endif
                            </p>
                            @if ($group['serial'])
                                <p class="text-xs text-ink-faint">IMEI {{ $group['serial'] }}</p>
                            @endif
                        </div>
                        @if ($left)
                            <div>
                                <label for="qty-{{ $key }}" class="sr-only">How much of {{ $group['name'] }} is coming back</label>
                                <x-ui.input wire:model.live.debounce.300ms="quantities.{{ $key }}" id="qty-{{ $key }}" type="number" min="0" :max="\App\Support\Quantity::plain($group['returnable'])" :step="$group['unit']->inputStep()" placeholder="0" />
                                <p class="mt-1 text-xs text-ink-faint">of {{ $group['unit']->amount($group['returnable']) }}@if ($group['pack']) · 1 {{ mb_strtolower($group['pack']['name']) }} = {{ $group['pack']['size'] }}@endif</p>
                                @error("lines.{$key}.quantity") <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="disposition-{{ $key }}" class="sr-only">Condition of {{ $group['name'] }}</label>
                                <div wire:key="disposition-{{ $key }}-{{ $dispositions[$key] ?? 'restock' }}">
                                    <x-ui.select wire:model="dispositions.{{ $key }}" id="disposition-{{ $key }}">
                                        <option value="restock">Back to stock</option>
                                        <option value="damaged">Damaged</option>
                                    </x-ui.select>
                                </div>
                            </div>
                        @else
                            <p class="text-sm text-ink-faint sm:col-span-2 sm:text-right">All returned</p>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="border-t border-line px-4 py-2.5 text-xs text-ink-faint sm:px-5">Back to stock puts it back on the batch it was sold from. Damaged puts it back and writes it off at once, so it shows under Write-offs.</p>
        </x-ui.card>

        <x-ui.card title="Refund">
            <div class="space-y-2 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-ink-faint">Value of what's coming back</span>
                    <span class="font-semibold tabular-nums text-ink" data-return-value>Rs {{ number_format($value, 2) }}</span>
                </div>
                @if ($owed > 0)
                    <div class="flex items-center justify-between">
                        <span class="text-ink-faint">Taken off what's still owed (Rs {{ number_format($owed, 2) }})</span>
                        <span class="font-semibold tabular-nums text-ink">Rs {{ number_format($againstDue, 2) }}</span>
                    </div>
                @endif
                <div class="flex items-center justify-between border-t border-line pt-2">
                    <span class="font-semibold text-ink">To hand back</span>
                    <span class="text-lg font-extrabold tabular-nums text-ink" data-return-refund>Rs {{ number_format($refund, 2) }}</span>
                </div>
            </div>

            @if ($refund > 0)
                <div class="mt-4" data-refund-to>
                    @if ($cashRefunds)
                        <div class="inline-flex gap-1 rounded-full border border-line bg-surface-sunken/60 p-1 text-sm font-semibold" role="group" aria-label="Refund as">
                            @foreach (['cash' => 'Hand back money', 'credit' => 'Store credit'] as $value => $label)
                                <label @class(['cursor-pointer rounded-full px-3 py-1.5 transition-colors', 'bg-surface text-ink shadow-soft ring-1 ring-line' => $refundTo === $value, 'text-ink-faint hover:text-ink' => $refundTo !== $value])>
                                    <input type="radio" wire:model.live="refundTo" value="{{ $value }}" class="sr-only"> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    @else
                        <p class="rounded-xl bg-info-soft px-3 py-2 text-sm text-info" data-no-cash-refunds>This shop doesn't hand back money on returns, so the Rs {{ number_format($refund, 2) }} goes on the customer's store credit.</p>
                    @endif

                    @if ($toCredit)
                        @if ($sale->customer)
                            <p class="mt-2 text-sm text-ink-muted" data-credit-to>Goes on {{ $sale->customer->name }}'s store credit{{ $creditBalance > 0 ? ' (Rs '.number_format($creditBalance, 2).' now)' : '' }}, to spend on a later sale.</p>
                        @else
                            <div class="mt-3 sm:max-w-sm">
                                <x-ui.field label="Customer" name="creditCustomerId" for="creditCustomerId" help="Store credit is kept against a customer" required>
                                    <div wire:key="credit-customer-{{ $creditCustomerId }}">
                                        <x-ui.select wire:model.live="creditCustomerId" id="creditCustomerId">
                                            <option value="">Choose a customer</option>
                                            <option value="__create__">+ New Customer</option>
                                            @foreach ($customers as $customer)
                                                <option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->phone ? ' · '.$customer->phone : '' }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    </div>
                                </x-ui.field>
                            </div>
                        @endif
                    @endif
                </div>
            @endif
            @error('refund') <p class="mt-2 text-sm text-danger">{{ $message }}</p> @enderror

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                @if ($refund > 0 && ! $toCredit)
                    <x-ledger.paid-from :value="$paidFrom" label="Refund From" help="Where the money handed back comes from" />
                @endif
                <x-ui.field label="Return Date" name="returnDate" for="returnDate">
                    <x-ui.input wire:model.live="returnDate" id="returnDate" type="date" :min="$saleDate" :max="\App\Support\BatchExpiry::today()" />
                </x-ui.field>
                <x-ui.field label="Reason" name="reason" for="reason" help="Optional, e.g. wrong size">
                    <x-ui.input wire:model="reason" id="reason" maxlength="255" />
                </x-ui.field>
                @if ($daysLate !== null && $isOwner)
                    <x-ui.field label="Why it's taken back late" name="lateReason" for="lateReason" required>
                        <x-ui.input wire:model="lateReason" id="lateReason" maxlength="255" />
                    </x-ui.field>
                @endif
            </div>

            <div class="mt-5">
                <x-ui.button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" :disabled="$nothingLeft || ($daysLate !== null && ! $isOwner)">Record Return</x-ui.button>
            </div>
        </x-ui.card>
    </div>

    @if (! $sale->customer_id)
        <livewire:customers.quick-create />
    @endif
</div>
