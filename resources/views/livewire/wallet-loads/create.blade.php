<?php

use App\Enums\PaymentStatus;
use App\Enums\WalletLoadDirection;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\ShopAccount;
use App\Models\WalletLoad;
use App\Services\WalletLoadReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Wallet Load')] class extends Component
{
    use Toasts;

    public string $direction = 'cash_in';
    public string $shopAccountId = '';
    public string $customerId = '';
    public string $accountName = '';
    public string $accountNumber = '';
    public string $amount = '';
    public string $fee = '0';
    public string $discount = '0';
    public bool $feeTouched = false;
    public bool $feeIncludedInAmount = false;
    public string $amountReceivedNow = '';
    public bool $accountNameManuallyEdited = false;

    public ?int $lastLoadId = null;

    public function mount(): void
    {
        $this->shopAccountId = (string) (ShopAccount::query()->orderBy('name')->value('id') ?? '');
    }

    public function switchDirection(string $direction): void
    {
        if (! in_array($direction, ['cash_in', 'cash_out'], true) || $direction === $this->direction) {
            return;
        }

        $this->direction = $direction;
        $this->accountName = '';
        $this->accountNumber = '';
        $this->customerId = '';
        $this->amount = '';
        $this->fee = '0';
        $this->discount = '0';
        $this->feeTouched = false;
        $this->feeIncludedInAmount = false;
        $this->amountReceivedNow = '';
        $this->accountNameManuallyEdited = false;
        $this->resetErrorBag();
    }

    public function updatedShopAccountId(): void
    {
        if ($this->shopAccountId === '__create__') {
            $this->shopAccountId = '';
            $this->dispatch('open-modal', name: 'quick-create-shop-account');
        }
    }

    #[On('shop-account-created')]
    public function onShopAccountCreated(int $shopAccountId): void
    {
        $this->shopAccountId = (string) $shopAccountId;
    }

    public function updatedCustomerId(): void
    {
        if ($this->customerId === '__create__') {
            $this->customerId = '';
            $this->dispatch('open-modal', name: 'quick-create-customer');

            return;
        }

        if ($this->customerId !== '' && ! $this->accountNameManuallyEdited) {
            $this->accountName = Customer::find($this->customerId)?->name ?? $this->accountName;
        }
    }

    #[On('customer-created')]
    public function onCustomerCreated(int $customerId, string $customerName): void
    {
        $this->customerId = (string) $customerId;

        if (! $this->accountNameManuallyEdited) {
            $this->accountName = $customerName;
        }
    }

    public function updatedAccountName(): void
    {
        $this->accountNameManuallyEdited = true;
    }

    public function updatedAmount(): void
    {
        if (! $this->feeTouched) {
            $this->fee = $this->suggestedFee();
        }
    }

    public function updatedFee(): void
    {
        $this->feeTouched = true;
    }

    protected function suggestedFee(): string
    {
        $percent = (float) (Auth::user()->shop?->wallet_load_commission_percent ?? 0);

        if ($percent <= 0 || $this->amount === '') {
            return '0';
        }

        return number_format(((float) $this->amount) * $percent / 100, 2, '.', '');
    }

    public function netAmount(): float
    {
        $amount = (float) ($this->amount !== '' ? $this->amount : 0);
        $fee = (float) $this->fee;

        return $this->feeIncludedInAmount ? max(0.0, $amount - $fee) : $amount;
    }

    public function totalCollected(): float
    {
        $amount = (float) ($this->amount !== '' ? $this->amount : 0);
        $fee = (float) $this->fee;
        $discount = (float) $this->discount;

        return $this->feeIncludedInAmount
            ? max(0.0, $amount - $discount)
            : max(0.0, $amount + $fee - $discount);
    }

    protected function derivePaymentStatus(float $received, float $total): PaymentStatus
    {
        if ($received <= 0) {
            return PaymentStatus::Unpaid;
        }

        return $received >= $total ? PaymentStatus::Paid : PaymentStatus::Partial;
    }

    public function previewPaymentStatus(): PaymentStatus
    {
        $received = $this->amountReceivedNow !== '' ? (float) $this->amountReceivedNow : 0.0;

        return $this->derivePaymentStatus($received, $this->totalCollected());
    }

    public function with(): array
    {
        return [
            'directions' => WalletLoadDirection::cases(),
            'shopAccountOptions' => collect([
                ['value' => '__create__', 'label' => 'New Shop Account', 'image' => null, 'special' => true, 'modal' => 'quick-create-shop-account'],
            ])->concat(
                ShopAccount::query()->orderBy('name')->get()->map(fn (ShopAccount $account) => [
                    'value' => (string) $account->id,
                    'label' => $account->name,
                    'image' => null,
                ])
            )->all(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'lastLoad' => $this->lastLoadId ? WalletLoad::find($this->lastLoadId) : null,
            'totalCollected' => $this->totalCollected(),
            'netAmount' => $this->netAmount(),
            'previewPaymentStatus' => $this->previewPaymentStatus(),
        ];
    }

    public function save(): void
    {
        $total = $this->totalCollected();

        $rules = [
            'shopAccountId' => ['required', 'integer', Rule::exists('shop_accounts', 'id')->where('shop_id', Auth::user()->shop_id)],
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)],
            'amount' => ['required', 'numeric', 'min:1'],
            'fee' => ['required', 'numeric', 'min:0'],
            'discount' => ['required', 'numeric', 'min:0'],
            'amountReceivedNow' => ['nullable', 'numeric', 'min:0', 'max:'.$total],
        ];

        if ($this->direction === WalletLoadDirection::CashIn->value) {
            $rules['accountName'] = ['required', 'string', 'max:255'];
            $rules['accountNumber'] = ['required', 'string', 'max:20'];
        }

        $this->validate($rules);

        $received = min($this->amountReceivedNow !== '' ? (float) $this->amountReceivedNow : 0.0, $total);
        $isCashIn = $this->direction === WalletLoadDirection::CashIn->value;
        $shopAccount = ShopAccount::findOrFail($this->shopAccountId);

        $load = WalletLoad::create([
            'user_id' => Auth::id(),
            'customer_id' => $this->customerId !== '' ? $this->customerId : null,
            'direction' => $this->direction,
            'provider' => $shopAccount->provider_type,
            'account_name' => $isCashIn ? $this->accountName : null,
            'account_number' => $isCashIn ? $this->accountNumber : null,
            'shop_account_id' => $this->shopAccountId,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'discount' => $this->discount,
            'fee_included_in_amount' => $this->feeIncludedInAmount,
            'net_amount' => $this->netAmount(),
            'total' => $total,
            'payment_status' => $this->derivePaymentStatus($received, $total),
            'amount_paid' => $received,
        ]);

        $this->toastSuccess('Wallet load saved.');
        $this->lastLoadId = $load->id;
        $this->accountName = '';
        $this->accountNumber = '';
        $this->customerId = '';
        $this->amount = '';
        $this->fee = '0';
        $this->discount = '0';
        $this->feeTouched = false;
        $this->feeIncludedInAmount = false;
        $this->amountReceivedNow = '';
        $this->accountNameManuallyEdited = false;
    }

    public function logAnother(): void
    {
        $this->lastLoadId = null;
    }

    public function downloadReceipt(WalletLoadReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(WalletLoad::findOrFail($this->lastLoadId));
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Wallet Load</h1>
            <a href="{{ route('wallet-loads.history') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                History →
            </a>
        </div>
    </x-slot>

    <div>
        @if ($lastLoad)
            <x-ui.card class="mx-auto max-w-lg">
                <div class="flex flex-col items-center text-center">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </div>

                    <p class="mt-3 text-sm text-slate-500">{{ $lastLoad->receiptNumber() }} · {{ $lastLoad->direction->label() }}</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($lastLoad->total, 2) }}</p>
                    <p class="mt-1 flex items-center gap-1.5 text-sm text-slate-500">
                        <x-ui.thumbnail :label="$lastLoad->provider" />
                        {{ $lastLoad->provider }}
                        @if ($lastLoad->account_name)
                            · {{ $lastLoad->account_name }} · {{ $lastLoad->account_number }}
                        @endif
                    </p>

                    <div class="mt-4 w-full space-y-1.5 rounded-lg border border-slate-200 p-4 text-left text-sm">
                        <div class="flex justify-between">
                            <span class="text-slate-500">{{ $lastLoad->direction->value === 'cash_in' ? 'Amount Loaded' : 'Amount Given' }}</span>
                            <span class="font-medium text-slate-900">Rs {{ number_format($lastLoad->net_amount, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Service Charge</span>
                            <span class="font-medium text-slate-900">Rs {{ number_format($lastLoad->fee, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Discount</span>
                            <span class="font-medium text-slate-900">− Rs {{ number_format($lastLoad->discount, 2) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-1.5 font-semibold text-slate-900">
                            <span>{{ $lastLoad->direction->value === 'cash_in' ? 'Total Collected' : 'Total Received' }}</span>
                            <span>Rs {{ number_format($lastLoad->total, 2) }}</span>
                        </div>
                        <div class="flex justify-between pt-1.5">
                            <span class="text-slate-500">Payment Status</span>
                            <span class="font-medium text-slate-900">{{ $lastLoad->payment_status->label() }}</span>
                        </div>
                    </div>

                    <div class="mt-6 flex w-full gap-3">
                        <x-ui.button type="button" variant="secondary" wire:click="downloadReceipt" class="flex-1 justify-center">
                            Download Receipt
                        </x-ui.button>

                        <x-ui.button type="button" wire:click="logAnother" class="flex-1 justify-center">
                            Log Another
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        @else
            <div class="mb-4 border-b border-slate-200">
                <nav class="-mb-px flex gap-6">
                    @foreach ($directions as $option)
                        <button
                            type="button"
                            wire:click="switchDirection('{{ $option->value }}')"
                            @class([
                                'whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition',
                                'border-brand-600 text-brand-700' => $direction === $option->value,
                                'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $direction !== $option->value,
                            ])
                        >
                            {{ $option->label() }}
                        </button>
                    @endforeach
                </nav>
            </div>

            <x-ui.card :title="$direction === 'cash_in' ? 'New Cash In' : 'New Cash Out'">
                <form wire:submit="save" class="space-y-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field
                            :label="$direction === 'cash_in' ? 'Send From' : 'Received Into'"
                            name="shopAccountId"
                            for="shopAccountId"
                            :help="$direction === 'cash_in' ? 'Which of the shop\'s own accounts is sending this' : 'Which of the shop\'s own accounts received this'"
                        >
                            <x-ui.image-select
                                wire-model="shopAccountId"
                                :options="$shopAccountOptions"
                                id="shopAccountId"
                                placeholder="Select a shop account"
                            />
                        </x-ui.field>

                        <x-ui.field label="Customer" name="customerId" for="customerId" help="Optional — leave blank for a walk-in">
                            <x-ui.select wire:model.live="customerId" id="customerId">
                                <option value="">Walk-in (no customer)</option>
                                <option value="__create__">+ New Customer</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>

                    @if ($direction === 'cash_in')
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-ui.field label="Account Name" name="accountName" for="accountName" help="Name on the recipient's wallet account">
                                <x-ui.input wire:model.live="accountName" id="accountName" />
                            </x-ui.field>

                            <x-ui.field label="Account / Mobile Number" name="accountNumber" for="accountNumber">
                                <x-ui.input wire:model="accountNumber" id="accountNumber" type="tel" placeholder="03xx-xxxxxxx" />
                            </x-ui.field>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <x-ui.field label="Amount" name="amount" for="amount" :help="$direction === 'cash_in' ? 'Amount being loaded into the recipient\'s account' : 'Amount being handed to the customer as cash'">
                            <x-ui.input wire:model.live="amount" id="amount" type="number" min="1" step="0.01" class="text-lg" autofocus />
                        </x-ui.field>

                        <x-ui.field label="Service Charge" name="fee" for="fee" help="Auto-suggested from your commission % — edit freely">
                            <x-ui.input wire:model.live="fee" id="fee" type="number" min="0" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="Discount" name="discount" for="discount" help="Optional">
                            <x-ui.input wire:model.live="discount" id="discount" type="number" min="0" step="0.01" />
                        </x-ui.field>
                    </div>

                    <div>
                        <x-ui.checkbox wire:model.live="feeIncludedInAmount" label="Fee is already included in the amount above" />

                        <p class="mt-2 text-xs text-slate-500">
                            @php
                                $exampleAmount = (float) ($amount !== '' ? $amount : 0);
                                $exampleFee = (float) $fee;
                                $movesVerb = $direction === 'cash_in' ? 'goes to the wallet' : 'is handed to the customer';
                                $paysVerb = $direction === 'cash_in' ? 'pays' : 'sends';
                            @endphp
                            @if ($feeIncludedInAmount)
                                e.g. Rs {{ number_format(max(0, $exampleAmount - $exampleFee), 2) }} {{ $movesVerb }}, you keep Rs {{ number_format($exampleFee, 2) }}, customer {{ $paysVerb }} Rs {{ number_format($totalCollected, 2) }} total
                            @else
                                e.g. Rs {{ number_format($exampleAmount, 2) }} {{ $movesVerb }}, customer {{ $paysVerb }} Rs {{ number_format($totalCollected, 2) }} total (Rs {{ number_format($exampleAmount, 2) }} + Rs {{ number_format($exampleFee, 2) }} fee)
                            @endif
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <x-ui.field
                            :label="$direction === 'cash_in' ? 'Cash Received' : 'Amount Received in Account'"
                            name="amountReceivedNow"
                            for="amountReceivedNow"
                            :help="$direction === 'cash_in' ? 'How much cash did the customer hand you just now?' : 'How much did the customer just send into this account?'"
                        >
                            <x-ui.input wire:model.live="amountReceivedNow" id="amountReceivedNow" type="number" min="0" step="0.01" placeholder="0" />
                        </x-ui.field>
                    </div>

                    <div class="rounded-lg bg-brand-50 px-4 py-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-brand-700">{{ $direction === 'cash_in' ? 'Total to Collect from Customer' : 'Total to Receive from Customer' }}</span>
                            <span class="text-lg font-semibold text-brand-900">Rs {{ number_format($totalCollected, 2) }}</span>
                        </div>
                        <div class="mt-1 flex items-center justify-between">
                            <span class="text-sm text-brand-700">Will be recorded as</span>
                            <x-ui.badge :variant="match($previewPaymentStatus->value) { 'paid' => 'success', 'partial' => 'warning', default => 'danger' }">
                                {{ $previewPaymentStatus->label() }}
                            </x-ui.badge>
                        </div>
                        @if ($amountReceivedNow !== '' && (float) $amountReceivedNow < $totalCollected)
                            <div class="mt-1 flex items-center justify-between text-sm">
                                <span class="text-slate-500">{{ $direction === 'cash_in' ? 'Still Owed by Customer' : 'Still Owed to Customer' }}</span>
                                <span class="font-medium text-amber-600">Rs {{ number_format($totalCollected - (float) $amountReceivedNow, 2) }}</span>
                            </div>
                        @endif
                    </div>

                    <x-ui.button type="submit" size="lg" class="w-full justify-center sm:w-auto" wire:loading.attr="disabled" wire:target="save">
                        Save
                    </x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>

    <livewire:shop-accounts.quick-create />
    <livewire:customers.quick-create />
</div>
