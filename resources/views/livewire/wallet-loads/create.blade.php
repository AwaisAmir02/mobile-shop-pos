<?php

use App\Enums\PaymentStatus;
use App\Enums\WalletLoadDirection;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\ShopAccount;
use App\Models\WalletLoad;
use App\Services\WalletLoadReceiptPdfService;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Wallet Load')] class extends Component
{
    use \App\Livewire\Concerns\ChoosesPaymentSource;

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
        $this->shopAccountId = (string) (ShopAccount::query()->usableAt(BranchContext::current()->recordingBranchId())->orderBy('name')->value('id') ?? '');
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
                ShopAccount::query()->usableAt(BranchContext::current()->recordingBranchId())->orderBy('name')->get()->map(fn (ShopAccount $account) => [
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
        $this->notePaymentSource($load, (float) $load->amount_paid);

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
        <x-ui.page-header back="{{ route('wallet-loads.history') }}" back-label="Wallet Load History">Wallet Load</x-ui.page-header>
    </x-slot>

    <div>
        @if ($lastLoad)
            <x-counter.receipt
                :number="$lastLoad->receiptNumber().' · '.$lastLoad->direction->label()"
                :total="$lastLoad->total"
                :lines="[
                    ['label' => $lastLoad->direction->value === 'cash_in' ? 'Amount Loaded' : 'Amount Given', 'value' => 'Rs '.number_format($lastLoad->net_amount, 2)],
                    ['label' => 'Service Charge', 'value' => 'Rs '.number_format($lastLoad->fee, 2)],
                    ['label' => 'Discount', 'value' => '− Rs '.number_format($lastLoad->discount, 2)],
                    ['label' => $lastLoad->direction->value === 'cash_in' ? 'Total Collected' : 'Total Received', 'value' => 'Rs '.number_format($lastLoad->total, 2), 'total' => true],
                    ['label' => 'Payment Status', 'value' => $lastLoad->payment_status->label()],
                ]"
            >
                <x-ui.thumbnail :label="$lastLoad->provider" />
                {{ $lastLoad->provider }}
                @if ($lastLoad->account_name)
                    · {{ $lastLoad->account_name }} · {{ $lastLoad->account_number }}
                @endif
            </x-counter.receipt>
        @else
            @php
                $cashIn = $direction === 'cash_in';
                $exampleAmount = (float) ($amount !== '' ? $amount : 0);
                $exampleFee = (float) $fee;
                $movesVerb = $cashIn ? 'goes to the wallet' : 'is handed to the customer';
                $paysVerb = $cashIn ? 'pays' : 'sends';
            @endphp

            <div class="mb-6 flex max-w-sm gap-1 rounded-full border border-line bg-surface-sunken/60 p-1" role="group" aria-label="Direction">
                @foreach ($directions as $option)
                    <button
                        type="button"
                        wire:click="switchDirection('{{ $option->value }}')"
                        aria-pressed="{{ $direction === $option->value ? 'true' : 'false' }}"
                        @class([
                            'min-w-0 flex-1 rounded-full px-3 py-1.5 text-sm font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-link',
                            'bg-surface text-ink shadow-soft ring-1 ring-line' => $direction === $option->value,
                            'text-ink-faint hover:text-ink' => $direction !== $option->value,
                        ])
                    >{{ $option->label() }}</button>
                @endforeach
            </div>

            <x-counter.form wire:submit="save">
                <x-counter.section title="Account">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field
                            :label="$cashIn ? 'Send From' : 'Received Into'"
                            name="shopAccountId"
                            for="shopAccountId"
                            :help="$cashIn ? 'Which of the shop\'s own accounts is sending this' : 'Which of the shop\'s own accounts received this'"
                            required
                        >
                            <x-ui.image-select
                                wire-model="shopAccountId"
                                :options="$shopAccountOptions"
                                id="shopAccountId"
                                placeholder="Select a shop account"
                            />
                        </x-ui.field>

                        <x-ui.field label="Customer" name="customerId" for="customerId" help="Leave blank for a walk-in">
                            <x-ui.select wire:model.live="customerId" id="customerId">
                                <option value="">Walk-in (no customer)</option>
                                <option value="__create__">+ New Customer</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        @if ($cashIn)
                            <x-ui.field label="Account Name" name="accountName" for="accountName" help="Name on the recipient's wallet account" required>
                                <x-ui.input wire:model.live="accountName" id="accountName" />
                            </x-ui.field>

                            <x-ui.field label="Account / Mobile Number" name="accountNumber" for="accountNumber" required>
                                <x-ui.input wire:model="accountNumber" id="accountNumber" type="tel" placeholder="03xx-xxxxxxx" />
                            </x-ui.field>
                        @endif
                    </div>
                </x-counter.section>

                <x-counter.section title="Amount">
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Amount" name="amount" class="col-span-2 sm:col-span-1" for="amount" :help="$cashIn ? 'Amount being loaded into the recipient\'s account' : 'Amount being handed to the customer as cash'" required>
                            <x-ui.input wire:model.live="amount" id="amount" type="number" min="1" step="0.01" class="text-lg" autofocus />
                        </x-ui.field>

                        <x-ui.field label="Service Charge" name="fee" for="fee" help="Suggested from your commission %">
                            <x-ui.input wire:model.live="fee" id="fee" type="number" min="0" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="Discount" name="discount" for="discount">
                            <x-ui.input wire:model.live="discount" id="discount" type="number" min="0" step="0.01" />
                        </x-ui.field>
                    </div>

                    <div class="mt-4 rounded-xl bg-surface-sunken/60 px-4 py-3">
                        <x-ui.checkbox wire:model.live="feeIncludedInAmount" label="Fee is already included in the amount above" />

                        <p class="mt-2 text-xs text-ink-faint">
                            @if ($feeIncludedInAmount)
                                e.g. Rs {{ number_format(max(0, $exampleAmount - $exampleFee), 2) }} {{ $movesVerb }}, you keep Rs {{ number_format($exampleFee, 2) }}, customer {{ $paysVerb }} Rs {{ number_format($totalCollected, 2) }} total
                            @else
                                e.g. Rs {{ number_format($exampleAmount, 2) }} {{ $movesVerb }}, customer {{ $paysVerb }} Rs {{ number_format($totalCollected, 2) }} total (Rs {{ number_format($exampleAmount, 2) }} + Rs {{ number_format($exampleFee, 2) }} fee)
                            @endif
                        </p>
                    </div>
                </x-counter.section>

                <x-counter.section title="Payment">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field
                            :label="$cashIn ? 'Cash Received' : 'Amount Received in Account'"
                            name="amountReceivedNow"
                            for="amountReceivedNow"
                            :help="$cashIn ? 'How much cash did the customer hand you just now?' : 'How much did the customer just send into this account?'"
                        >
                            <x-ui.input wire:model.live="amountReceivedNow" id="amountReceivedNow" type="number" min="0" step="0.01" placeholder="0" />
                        </x-ui.field>

                        <x-ledger.paid-from :value="$paidFrom" label="Received Into" />
                    </div>
                </x-counter.section>

                <x-slot:summary>
                    <x-counter.summary
                        :total-label="$cashIn ? 'Total to Collect from Customer' : 'Total to Receive from Customer'"
                        :total="$totalCollected"
                        :lines="[
                            ['label' => $cashIn ? 'Goes to the wallet' : 'Handed to the customer', 'value' => 'Rs '.number_format($netAmount, 2)],
                            ['label' => 'Service Charge', 'value' => 'Rs '.number_format((float) $fee, 2)],
                            ['label' => 'Discount', 'value' => '− Rs '.number_format((float) $discount, 2)],
                        ]"
                        :status="$previewPaymentStatus"
                        :owed="$amountReceivedNow !== '' && (float) $amountReceivedNow < $totalCollected ? $totalCollected - (float) $amountReceivedNow : null"
                        :owed-label="$cashIn ? 'Still Owed by Customer' : 'Still Owed to Customer'"
                    />
                </x-slot:summary>
            </x-counter.form>
        @endif
    </div>

    <livewire:shop-accounts.quick-create />
    <livewire:customers.quick-create />
</div>
