<?php

use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Models\Customer;
use App\Models\ShopAccount;
use App\Services\BillPaymentReceiptPdfService;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Bills')] class extends Component
{
    use \App\Livewire\Concerns\ChoosesPaymentSource;

    use Toasts;

    public string $billCategoryId = '';
    public string $region = '';
    public string $billProviderId = '';
    public string $consumerNumber = '';
    public string $consumerName = '';
    public string $customerId = '';
    public string $shopAccountId = '';
    public string $amount = '';
    public string $fee = '0';
    public string $discount = '0';
    public bool $feeTouched = false;
    public string $paymentStatus = 'paid';
    public string $amountPaid = '';

    public ?int $lastPaymentId = null;

    protected string $billCategoryIdBeforeCreate = '';

    public function mount(): void
    {
        BillCategory::ensureDefaultsExist();
        $this->billCategoryId = (string) (BillCategory::query()->orderBy('name')->value('id') ?? '');
        $this->shopAccountId = (string) (ShopAccount::query()->usableAt(BranchContext::current()->recordingBranchId())->orderBy('name')->value('id') ?? '');
    }

    public function updatingBillCategoryId(string $value): void
    {
        if ($value === '__create__') {
            $this->billCategoryIdBeforeCreate = $this->billCategoryId;
        }
    }

    public function updatedBillCategoryId(): void
    {
        if ($this->billCategoryId === '__create__') {
            $this->billCategoryId = $this->billCategoryIdBeforeCreate !== ''
                ? $this->billCategoryIdBeforeCreate
                : (string) (BillCategory::query()->orderBy('name')->value('id') ?? '');
            $this->dispatch('open-modal', name: 'quick-create-bill-category');

            return;
        }

        $this->region = '';
        $this->billProviderId = '';
    }

    #[On('bill-category-created')]
    public function onBillCategoryCreated(int $categoryId): void
    {
        $this->billCategoryId = (string) $categoryId;
        $this->region = '';
        $this->billProviderId = '';
    }

    public function updatedRegion(): void
    {
        if ($this->region === '__create__') {
            $this->region = '';
            $this->dispatch('open-modal', name: 'quick-create-bill-provider');

            return;
        }

        $this->billProviderId = '';
    }

    public function updatedBillProviderId(): void
    {
        if ($this->billProviderId === '__create__') {
            $this->billProviderId = '';
            $this->dispatch('open-modal', name: 'quick-create-bill-provider');
        }
    }

    #[On('bill-provider-created')]
    public function onBillProviderCreated(int $providerId, int $billCategoryId): void
    {
        $this->billCategoryId = (string) $billCategoryId;
        $this->region = '';
        $this->billProviderId = (string) $providerId;
    }

    public function updatedCustomerId(): void
    {
        if ($this->customerId === '__create__') {
            $this->customerId = '';
            $this->dispatch('open-modal', name: 'quick-create-customer');
        }
    }

    #[On('customer-created')]
    public function onCustomerCreated(int $customerId): void
    {
        $this->customerId = (string) $customerId;
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
        $percent = (float) (Auth::user()->shop?->bills_commission_percent ?? 0);

        if ($percent <= 0 || $this->amount === '') {
            return '0';
        }

        return number_format(((float) $this->amount) * $percent / 100, 2, '.', '');
    }

    public function updatedPaymentStatus(): void
    {
        if ($this->paymentStatus !== 'partial') {
            $this->amountPaid = '';
        }
    }

    public function totalCollected(): float
    {
        return max(0.0, (float) ($this->amount !== '' ? $this->amount : 0) + (float) $this->fee - (float) $this->discount);
    }

    public function with(): array
    {
        $providersQuery = fn () => BillProvider::query()
            ->when($this->billCategoryId, fn ($query) => $query->where('bill_category_id', $this->billCategoryId));

        return [
            'billCategories' => BillCategory::query()->orderBy('name')->get(),
            'regions' => $providersQuery()->whereNotNull('region')->distinct()->orderBy('region')->pluck('region'),
            'providers' => $providersQuery()
                ->when($this->region, fn ($query) => $query->where('region', $this->region))
                ->orderBy('name')
                ->get(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'shopAccounts' => ShopAccount::query()->usableAt(BranchContext::current()->recordingBranchId())->orderBy('name')->get(),
            'lastPayment' => $this->lastPaymentId ? BillPayment::find($this->lastPaymentId) : null,
            'totalCollected' => $this->totalCollected(),
            'paymentStatuses' => PaymentStatus::cases(),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'billCategoryId' => ['required', 'integer', Rule::exists('bill_categories', 'id')->where('shop_id', Auth::user()->shop_id)],
            'billProviderId' => ['required', 'integer', Rule::exists('bill_providers', 'id')->where('shop_id', Auth::user()->shop_id)->where('bill_category_id', $this->billCategoryId)],
            'consumerNumber' => ['required', 'string', 'max:100'],
            'consumerName' => ['required', 'string', 'max:255'],
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)],
            'shopAccountId' => ['required', 'integer', Rule::exists('shop_accounts', 'id')->where('shop_id', Auth::user()->shop_id)],
            'amount' => ['required', 'numeric', 'min:0'],
            'fee' => ['required', 'numeric', 'min:0'],
            'discount' => ['required', 'numeric', 'min:0'],
            'paymentStatus' => ['required', Rule::enum(PaymentStatus::class)],
            'amountPaid' => ['nullable', 'numeric', 'min:0', 'required_if:paymentStatus,partial'],
        ]);

        $total = $this->totalCollected();

        $amountPaid = match ($this->paymentStatus) {
            'paid' => $total,
            'partial' => $this->amountPaid !== '' ? $this->amountPaid : 0,
            default => 0,
        };

        $payment = BillPayment::create([
            'user_id' => Auth::id(),
            'customer_id' => $this->customerId !== '' ? $this->customerId : null,
            'bill_category_id' => $this->billCategoryId,
            'bill_provider_id' => $this->billProviderId,
            'shop_account_id' => $this->shopAccountId,
            'consumer_number' => $this->consumerNumber,
            'consumer_name' => $this->consumerName,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'discount' => $this->discount,
            'total' => $total,
            'payment_status' => $this->paymentStatus,
            'amount_paid' => $amountPaid,
        ]);
        $this->notePaymentSource($payment, (float) $payment->amount_paid);

        $this->toastSuccess('Bill payment recorded.');
        $this->lastPaymentId = $payment->id;
        $this->reset(['consumerNumber', 'consumerName', 'customerId', 'amount', 'fee', 'discount', 'feeTouched', 'amountPaid']);
        $this->paymentStatus = 'paid';
    }

    public function logAnother(): void
    {
        $this->lastPaymentId = null;
    }

    public function downloadReceipt(BillPaymentReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(BillPayment::findOrFail($this->lastPaymentId));
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('bills.history') }}" back-label="Bill Payment History">Bills</x-ui.page-header>
    </x-slot>

    <div>
        @if ($lastPayment)
            <x-counter.receipt
                :number="$lastPayment->receiptNumber()"
                :total="$lastPayment->total"
                :lines="[
                    ['label' => 'Bill Amount', 'value' => 'Rs '.number_format($lastPayment->amount, 2)],
                    ['label' => 'Service Charge', 'value' => 'Rs '.number_format($lastPayment->fee, 2)],
                    ['label' => 'Discount', 'value' => '− Rs '.number_format($lastPayment->discount, 2)],
                    ['label' => 'Total Collected', 'value' => 'Rs '.number_format($lastPayment->total, 2), 'total' => true],
                ]"
            >
                {{ $lastPayment->billProvider->name }} · {{ $lastPayment->consumer_number }}
            </x-counter.receipt>
        @else
            <x-counter.form wire:submit="save">
                <x-counter.section title="Bill">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Category" name="billCategoryId" for="billCategoryId" required>
                            <x-ui.select wire:model.live="billCategoryId" id="billCategoryId">
                                <option value="__create__">+ New Category</option>
                                @foreach ($billCategories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Region / Province" name="region" for="region" help="Narrows the provider list">
                            <x-ui.select wire:model.live="region" id="region">
                                <option value="">All Regions</option>
                                <option value="__create__">+ New Provider (with a region)</option>
                                @foreach ($regions as $regionOption)
                                    <option value="{{ $regionOption }}">{{ $regionOption }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Provider / Company" name="billProviderId" for="billProviderId" required>
                            <x-ui.select wire:model.live="billProviderId" id="billProviderId">
                                <option value="">Select a provider</option>
                                <option value="__create__">+ New Provider</option>
                                @foreach ($providers as $provider)
                                    <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Consumer Number" name="consumerNumber" for="consumerNumber" required>
                            <x-ui.input wire:model="consumerNumber" id="consumerNumber" autofocus />
                        </x-ui.field>

                        <x-ui.field label="Consumer Name" name="consumerName" for="consumerName" required>
                            <x-ui.input wire:model="consumerName" id="consumerName" />
                        </x-ui.field>

                        <x-ui.field label="Customer" name="customerId" for="customerId" help="Leave blank for a walk-in payment">
                            <x-ui.select wire:model.live="customerId" id="customerId">
                                <option value="">Walk-in (no customer)</option>
                                <option value="__create__">+ New Customer</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>
                </x-counter.section>

                <x-counter.section title="Amount">
                    <div class="grid grid-cols-2 gap-4">
                        <x-ui.field label="Bill Amount" name="amount" for="amount" required class="col-span-2 sm:col-span-1">
                            <x-ui.input wire:model.live="amount" id="amount" type="number" min="0" step="0.01" class="text-lg" />
                        </x-ui.field>

                        <x-ui.field label="Send From" name="shopAccountId" for="shopAccountId" help="Which of the shop's own accounts is paying this" required class="col-span-2 sm:col-span-1">
                            <x-ui.select wire:model.live="shopAccountId" id="shopAccountId">
                                <option value="">Select a shop account</option>
                                <option value="__create__">+ New Shop Account</option>
                                @foreach ($shopAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Service Charge" name="fee" for="fee" help="Suggested from your commission %">
                            <x-ui.input wire:model.live="fee" id="fee" type="number" min="0" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="Discount" name="discount" for="discount">
                            <x-ui.input wire:model.live="discount" id="discount" type="number" min="0" step="0.01" />
                        </x-ui.field>
                    </div>
                </x-counter.section>

                <x-counter.section title="Payment">
                    <x-counter.payment-status :statuses="$paymentStatuses" :status="$paymentStatus" :paid-from="$paidFrom" />
                </x-counter.section>

                <x-slot:summary>
                    <x-counter.summary
                        total-label="Total Collected"
                        :total="$totalCollected"
                        :lines="[
                            ['label' => 'Bill Amount', 'value' => 'Rs '.number_format((float) $amount, 2)],
                            ['label' => 'Service Charge', 'value' => 'Rs '.number_format((float) $fee, 2)],
                            ['label' => 'Discount', 'value' => '− Rs '.number_format((float) $discount, 2)],
                        ]"
                    />
                </x-slot:summary>
            </x-counter.form>
        @endif
    </div>

    <livewire:customers.quick-create />
    <livewire:shop-accounts.quick-create />
    <livewire:bill-categories.quick-create />
    <livewire:bill-providers.quick-create :default-bill-category-id="$billCategoryId" />
</div>
