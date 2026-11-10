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
                    ['label' => $lastLoad->direction->value === 'cash_in' ? 'Amount Loaded' : 'Cash Handed Over', 'value' => 'Rs '.number_format($lastLoad->net_amount, 2)],
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

            {{-- What each direction moves, so it reads the way the counter works:
                 Cash In = cash comes in, the account goes down; Cash Out = the reverse. --}}
            <div class="mb-6 grid max-w-2xl grid-cols-1 gap-3 sm:grid-cols-2" role="group" aria-label="Direction">
                @foreach ($directions as $option)
                    @php($isIn = $option->value === 'cash_in')
                    <button
                        type="button"
                        wire:click="switchDirection('{{ $option->value }}')"
                        aria-pressed="{{ $direction === $option->value ? 'true' : 'false' }}"
                        data-direction="{{ $option->value }}"
                        @class([
                            'rounded-xl border p-3.5 text-left transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-link',
                            'border-primary bg-primary-soft ring-1 ring-primary' => $direction === $option->value,
                            'border-line bg-surface hover:border-line-strong' => $direction !== $option->value,
                        ])
                    >
                        <span class="block text-sm font-bold text-ink">{{ $option->label() }}</span>
                        <span class="mt-0.5 block text-xs text-ink-faint">{{ $isIn ? 'Customer gives you cash, you send it from your account to theirs.' : 'Customer sends money to your account, you hand them cash.' }}</span>
                        <span class="mt-2 flex flex-wrap gap-1.5 text-xs font-semibold">
                            <span class="rounded-full px-2 py-0.5 {{ $isIn ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger' }}">Cash {{ $isIn ? 'in' : 'out' }}</span>
                            <span class="rounded-full px-2 py-0.5 {{ $isIn ? 'bg-danger-soft text-danger' : 'bg-success-soft text-success' }}">Account {{ $isIn ? 'out' : 'in' }}</span>
                        </span>
                    </button>
                @endforeach
            </div>

            <x-counter.form wire:submit="save">
                <x-counter.section title="Account">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field
                            :label="$cashIn ? 'Send From (your account)' : 'Customer Sends To (your account)'"
                            name="shopAccountId"
                            for="shopAccountId"
                            :help="$cashIn ? 'The shop\'s account the money leaves from' : 'The shop\'s account the customer\'s money arrives in'"
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
                        <x-ui.field label="Amount" name="amount" class="col-span-2 sm:col-span-1" for="amount" :help="$cashIn ? 'What goes into the customer\'s account' : 'The cash you hand the customer'" required>
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

                        <p class="mt-2 text-xs text-ink-faint" data-fee-example>
                            @php($kept = $feeIncludedInAmount ? max(0, $exampleAmount - $exampleFee) : $exampleAmount)
                            @if ($cashIn)
                                e.g. Rs {{ number_format($kept, 2) }} goes to their account{{ $feeIncludedInAmount ? ', you keep Rs '.number_format($exampleFee, 2) : '' }}; the customer pays you Rs {{ number_format($totalCollected, 2) }} in cash{{ $feeIncludedInAmount ? '' : ' (Rs '.number_format($exampleAmount, 2).' + Rs '.number_format($exampleFee, 2).' fee)' }}.
                            @else
                                e.g. You hand over Rs {{ number_format($kept, 2) }} in cash{{ $feeIncludedInAmount ? ' and keep Rs '.number_format($exampleFee, 2) : '' }}; the customer sends Rs {{ number_format($totalCollected, 2) }} to your account{{ $feeIncludedInAmount ? '' : ' (Rs '.number_format($exampleAmount, 2).' + Rs '.number_format($exampleFee, 2).' fee)' }}.
                            @endif
                        </p>
                    </div>
                </x-counter.section>

                <x-counter.section title="Payment">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field
                            :label="$cashIn ? 'Cash Received from Customer' : 'Received in Your Account'"
                            name="amountReceivedNow"
                            for="amountReceivedNow"
                            :help="$cashIn ? 'How much cash the customer has handed you' : 'How much the customer has sent to your account so far'"
                        >
                            <x-ui.input wire:model.live="amountReceivedNow" id="amountReceivedNow" type="number" min="0" step="0.01" placeholder="0" />
                        </x-ui.field>
                    </div>
                    <p class="mt-2 text-xs text-ink-faint">Anything the customer hasn't paid yet is their due, on the Udhaar screen.</p>
                </x-counter.section>

                <x-slot:summary>
                    <x-counter.summary
                        :total-label="$cashIn ? 'Customer Pays You (cash)' : 'Customer Sends You (to your account)'"
                        :total="$totalCollected"
                        :lines="[
                            ['label' => $cashIn ? 'Goes to their account' : 'Cash you hand over', 'value' => 'Rs '.number_format($netAmount, 2)],
                            ['label' => 'Service Charge', 'value' => 'Rs '.number_format((float) $fee, 2)],
                            ['label' => 'Discount', 'value' => '− Rs '.number_format((float) $discount, 2)],
                        ]"
                        :status="$previewPaymentStatus"
                        :owed="$amountReceivedNow !== '' && (float) $amountReceivedNow < $totalCollected ? $totalCollected - (float) $amountReceivedNow : null"
                        :owed-label="'Still Owed by Customer'"
                    >
                        @php($accountName = collect($shopAccountOptions)->firstWhere('value', (string) $shopAccountId)['label'] ?? 'Your account')
                        <div class="mt-3 rounded-xl bg-surface-sunken/60 p-3 text-xs" data-what-moves>
                            <p class="font-semibold text-ink">What moves, once fully paid</p>
                            <dl class="mt-1.5 space-y-1">
                                <div class="flex justify-between gap-3"><dt class="text-ink-faint">Cash in hand</dt><dd class="font-semibold tabular-nums {{ $cashIn ? 'text-success' : 'text-danger' }}">{{ $cashIn ? '+' : '−' }} Rs {{ number_format($cashIn ? $totalCollected : $netAmount, 2) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="min-w-0 truncate text-ink-faint">{{ $accountName }}</dt><dd class="font-semibold tabular-nums {{ $cashIn ? 'text-danger' : 'text-success' }}">{{ $cashIn ? '−' : '+' }} Rs {{ number_format($cashIn ? $netAmount : $totalCollected, 2) }}</dd></div>
                            </dl>
                        </div>
                    </x-counter.summary>
                </x-slot:summary>
            </x-counter.form>
        @endif
    </div>

    <livewire:shop-accounts.quick-create />
    <livewire:customers.quick-create />
</div>
