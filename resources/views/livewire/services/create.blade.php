<?php

use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\ServicePayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Services')] class extends Component
{
    use \App\Livewire\Concerns\ChoosesPaymentSource;

    use Toasts;

    public string $serviceCategoryId = '';
    public string $serviceItemId = '';
    public string $customerId = '';
    public string $amount = '';
    public string $paymentStatus = 'paid';
    public string $amountPaid = '';

    public ?int $lastPaymentId = null;

    protected string $serviceCategoryIdBeforeCreate = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->services_enabled, 403);

        ServiceCategory::ensureDefaultsExist();
        $this->serviceCategoryId = (string) (ServiceCategory::query()->orderBy('name')->value('id') ?? '');
    }

    public function updatingServiceCategoryId(string $value): void
    {
        if ($value === '__create__') {
            $this->serviceCategoryIdBeforeCreate = $this->serviceCategoryId;
        }
    }

    public function updatedServiceCategoryId(): void
    {
        if ($this->serviceCategoryId === '__create__') {
            $this->serviceCategoryId = $this->serviceCategoryIdBeforeCreate !== ''
                ? $this->serviceCategoryIdBeforeCreate
                : (string) (ServiceCategory::query()->orderBy('name')->value('id') ?? '');
            $this->dispatch('open-modal', name: 'quick-create-service-category');

            return;
        }

        $this->serviceItemId = '';
    }

    #[On('service-category-created')]
    public function onServiceCategoryCreated(int $categoryId): void
    {
        $this->serviceCategoryId = (string) $categoryId;
        $this->serviceItemId = '';
    }

    public function updatedServiceItemId(): void
    {
        if ($this->serviceItemId === '__create__') {
            $this->serviceItemId = '';
            $this->dispatch('open-modal', name: 'quick-create-service-item');
        }
    }

    #[On('service-item-created')]
    public function onServiceItemCreated(int $itemId, int $serviceCategoryId): void
    {
        $this->serviceCategoryId = (string) $serviceCategoryId;
        $this->serviceItemId = (string) $itemId;
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

    public function updatedPaymentStatus(): void
    {
        if ($this->paymentStatus !== 'partial') {
            $this->amountPaid = '';
        }
    }

    public function with(): array
    {
        return [
            'serviceCategories' => ServiceCategory::query()->orderBy('name')->get(),
            'serviceItems' => ServiceItem::query()
                ->when($this->serviceCategoryId, fn ($query) => $query->where('service_category_id', $this->serviceCategoryId))
                ->orderBy('name')
                ->get(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'lastPayment' => $this->lastPaymentId ? ServicePayment::find($this->lastPaymentId) : null,
            'paymentStatuses' => PaymentStatus::cases(),
        ];
    }

    public function save(): void
    {
        abort_unless(Auth::user()->shop?->services_enabled, 403);

        $this->validate([
            'serviceCategoryId' => ['required', 'integer', Rule::exists('service_categories', 'id')->where('shop_id', Auth::user()->shop_id)],
            'serviceItemId' => ['required', 'integer', Rule::exists('service_items', 'id')->where('shop_id', Auth::user()->shop_id)->where('service_category_id', $this->serviceCategoryId)],
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)],
            'amount' => ['required', 'numeric', 'min:0'],
            'paymentStatus' => ['required', Rule::enum(PaymentStatus::class)],
            'amountPaid' => ['nullable', 'numeric', 'min:0', 'required_if:paymentStatus,partial'],
        ]);

        $amountPaid = match ($this->paymentStatus) {
            'paid' => $this->amount,
            'partial' => $this->amountPaid !== '' ? $this->amountPaid : 0,
            default => 0,
        };

        $payment = ServicePayment::create([
            'user_id' => Auth::id(),
            'customer_id' => $this->customerId !== '' ? $this->customerId : null,
            'service_category_id' => $this->serviceCategoryId,
            'service_item_id' => $this->serviceItemId,
            'amount' => $this->amount,
            'payment_status' => $this->paymentStatus,
            'amount_paid' => $amountPaid,
        ]);
        $this->notePaymentSource($payment, (float) $payment->amount_paid);

        $this->toastSuccess('Service recorded.');
        $this->lastPaymentId = $payment->id;
        $this->reset(['serviceItemId', 'customerId', 'amount', 'amountPaid']);
        $this->paymentStatus = 'paid';
    }

    public function logAnother(): void
    {
        $this->lastPaymentId = null;
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('services.history') }}" back-label="Service History">Services</x-ui.page-header>
    </x-slot>

    <div>
        @if ($lastPayment)
            <x-counter.receipt :number="$lastPayment->receiptNumber()" :total="$lastPayment->amount" :download="false">
                {{ $lastPayment->serviceCategory->name }} · {{ $lastPayment->serviceItem->name }}
            </x-counter.receipt>
        @else
            <x-counter.form wire:submit="save">
                <x-counter.section title="Service">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Category" name="serviceCategoryId" for="serviceCategoryId" required>
                            <x-ui.select wire:model.live="serviceCategoryId" id="serviceCategoryId">
                                <option value="__create__">+ New Category</option>
                                @foreach ($serviceCategories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Item" name="serviceItemId" for="serviceItemId" required>
                            <x-ui.select wire:model.live="serviceItemId" id="serviceItemId">
                                <option value="">Select an item</option>
                                <option value="__create__">+ New Item</option>
                                @foreach ($serviceItems as $item)
                                    <option value="{{ $item->id }}">{{ $item->name }}</option>
                                @endforeach
                            </x-ui.select>
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
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Amount" name="amount" for="amount" required>
                            <x-ui.input wire:model="amount" id="amount" type="number" min="0" step="0.01" class="text-lg" autofocus />
                        </x-ui.field>
                    </div>
                </x-counter.section>

                <x-counter.section title="Payment">
                    <x-counter.payment-status :statuses="$paymentStatuses" :status="$paymentStatus" :paid-from="$paidFrom" />
                </x-counter.section>

                <x-slot:summary>
                    {{-- Amount is sent on save, not on each keystroke, so the total follows it in the browser. --}}
                    <x-counter.summary
                        :total="(float) $amount"
                        total-expression="'Rs ' + (parseFloat($wire.amount) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"
                    />
                </x-slot:summary>
            </x-counter.form>
        @endif
    </div>

    <livewire:customers.quick-create />
    <livewire:service-categories.quick-create />
    <livewire:service-items.quick-create :default-service-category-id="$serviceCategoryId" />
</div>
