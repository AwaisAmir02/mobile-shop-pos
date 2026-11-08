<?php

use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\AccessoryCategoryOption;
use App\Models\Customer;
use App\Models\MainCategory;
use App\Models\Repair;
use App\Services\RepairReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Repairs')] class extends Component
{
    use \App\Livewire\Concerns\ChoosesPaymentSource;

    use Toasts;

    public string $customerId = '';
    public string $repairDate = '';
    public string $mainCategorySlug = 'mobile';
    public string $subCategoryName = '';
    public string $description = '';
    public string $serviceCharges = '';
    public string $productsCharges = '0';
    public string $discount = '0';
    public string $paymentStatus = 'paid';
    public string $amountPaid = '';

    public ?int $lastRepairId = null;

    protected string $mainCategorySlugBeforeCreate = '';

    public function mount(): void
    {
        MainCategory::ensureDefaultsExist();
        $this->amountPaid = '0';
        $this->repairDate = \Carbon\Carbon::now('Asia/Karachi')->toDateString();
    }

    public function updatingMainCategorySlug(string $value): void
    {
        if ($value === '__create__') {
            $this->mainCategorySlugBeforeCreate = $this->mainCategorySlug;
        }
    }

    public function updatedMainCategorySlug(): void
    {
        if ($this->mainCategorySlug === '__create__') {
            $this->mainCategorySlug = $this->mainCategorySlugBeforeCreate !== '' ? $this->mainCategorySlugBeforeCreate : 'mobile';
            $this->dispatch('open-modal', name: 'quick-create-main-category');

            return;
        }

        $this->subCategoryName = '';
    }

    #[On('main-category-created')]
    public function onMainCategoryCreated(string $slug): void
    {
        $this->mainCategorySlug = $slug;
        $this->subCategoryName = '';
    }

    public function updatedSubCategoryName(): void
    {
        if ($this->subCategoryName === '__create__') {
            $this->subCategoryName = '';
            $this->dispatch('open-modal', name: 'quick-create-accessory-category');
        }
    }

    #[On('accessory-category-created')]
    public function onAccessoryCategoryCreated(string $categoryName): void
    {
        $this->subCategoryName = $categoryName;
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
        $this->amountPaid = match ($this->paymentStatus) {
            'paid' => (string) $this->totalCollected(),
            'unpaid' => '0',
            default => '',
        };
    }

    public function updatedAmountPaid(): void
    {
        $amount = $this->amountPaid !== '' ? (float) $this->amountPaid : 0.0;
        $total = $this->totalCollected();

        $this->paymentStatus = match (true) {
            $amount <= 0 => 'unpaid',
            $total <= 0 || $amount >= $total => 'paid',
            default => 'partial',
        };
    }

    protected function refreshAmountPaidForTotalChange(): void
    {
        if ($this->paymentStatus === 'paid') {
            $this->amountPaid = (string) $this->totalCollected();
        }
    }

    public function updatedServiceCharges(): void
    {
        $this->refreshAmountPaidForTotalChange();
    }

    public function updatedProductsCharges(): void
    {
        $this->refreshAmountPaidForTotalChange();
    }

    public function updatedDiscount(): void
    {
        $this->refreshAmountPaidForTotalChange();
    }

    public function combinedAmount(): float
    {
        return (float) ($this->serviceCharges !== '' ? $this->serviceCharges : 0) + (float) ($this->productsCharges !== '' ? $this->productsCharges : 0);
    }

    public function totalCollected(): float
    {
        return max(0.0, $this->combinedAmount() - (float) $this->discount);
    }

    public function with(): array
    {
        $mainCategories = MainCategory::query()->orderBy('name')->get();
        $currentMainCategory = $mainCategories->firstWhere('slug', $this->mainCategorySlug);

        return [
            'mainCategories' => $mainCategories,
            'subCategoryOptions' => collect([
                ['value' => '__create__', 'label' => 'New Sub-Category', 'image' => null, 'special' => true, 'modal' => 'quick-create-accessory-category'],
            ])->concat(
                AccessoryCategoryOption::query()
                    ->when($currentMainCategory, fn ($query) => $query->where('main_category_id', $currentMainCategory->id))
                    ->orderBy('name')
                    ->get()
                    ->map(fn (AccessoryCategoryOption $option) => [
                        'value' => $option->name,
                        'label' => $option->name,
                        'image' => $option->imageUrl(),
                    ])
            )->all(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'lastRepair' => $this->lastRepairId ? Repair::find($this->lastRepairId) : null,
            'totalCollected' => $this->totalCollected(),
            'paymentStatuses' => PaymentStatus::cases(),
        ];
    }

    public function save(): void
    {
        $todayKarachi = \Carbon\Carbon::now('Asia/Karachi')->toDateString();

        $this->validate([
            'repairDate' => ['nullable', 'date', 'before_or_equal:'.$todayKarachi],
            'mainCategorySlug' => ['required', 'string', Rule::exists('main_categories', 'slug')->where('shop_id', Auth::user()->shop_id)],
            'subCategoryName' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:1000'],
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)],
            'serviceCharges' => ['required', 'numeric', 'min:0'],
            'productsCharges' => ['required', 'numeric', 'min:0'],
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

        $dateStr = $this->repairDate !== '' ? $this->repairDate : $todayKarachi;
        $karachiNow = \Carbon\Carbon::now('Asia/Karachi');
        $utcDatetime = \Carbon\Carbon::parse($dateStr, 'Asia/Karachi')->setTimeFrom($karachiNow)->utc();

        $repair = new Repair([
            'user_id' => Auth::id(),
            'customer_id' => $this->customerId !== '' ? $this->customerId : null,
            'category' => $this->mainCategorySlug,
            'sub_category' => $this->subCategoryName !== '' ? $this->subCategoryName : null,
            'description' => $this->description,
            'amount' => $this->combinedAmount(),
            'service_charges' => $this->serviceCharges,
            'products_charges' => $this->productsCharges,
            'discount' => $this->discount,
            'total' => $total,
            'payment_status' => $this->paymentStatus,
            'amount_paid' => $amountPaid,
        ]);
        $repair->created_at = $utcDatetime;
        $repair->save();
        $this->notePaymentSource($repair, (float) $repair->amount_paid);

        $this->toastSuccess('Repair recorded.');
        $this->lastRepairId = $repair->id;
        $this->reset(['customerId', 'subCategoryName', 'description', 'serviceCharges', 'productsCharges', 'discount', 'amountPaid']);
        $this->repairDate = \Carbon\Carbon::now('Asia/Karachi')->toDateString();
        $this->mainCategorySlug = 'mobile';
        $this->paymentStatus = 'paid';
        $this->amountPaid = '0';
    }

    public function logAnother(): void
    {
        $this->lastRepairId = null;
    }

    public function downloadReceipt(RepairReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(Repair::findOrFail($this->lastRepairId));
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('repairs.history') }}" back-label="Repair History">Repairs</x-ui.page-header>
    </x-slot>

    <div>
        @if ($lastRepair)
            <x-counter.receipt :number="$lastRepair->receiptNumber()" :total="$lastRepair->total">
                {{ $lastRepair->categoryLabel() }} · {{ $lastRepair->description }}
            </x-counter.receipt>
        @else
            <x-counter.form wire:submit="save">
                <x-counter.section title="Repair">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Main Category" name="mainCategorySlug" for="mainCategorySlug" required>
                            <x-ui.select wire:model.live="mainCategorySlug" id="mainCategorySlug">
                                <option value="__create__">+ New Main Category</option>
                                @foreach ($mainCategories as $option)
                                    <option value="{{ $option->slug }}">{{ $option->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Sub-Category" name="subCategoryName" for="subCategoryName">
                            <x-ui.image-select
                                wire:key="repair-sub-category-{{ $mainCategorySlug }}"
                                wire-model="subCategoryName"
                                :options="$subCategoryOptions"
                                id="subCategoryName"
                                placeholder="Select a sub-category"
                            />
                        </x-ui.field>
                    </div>

                    <x-ui.field label="Description" name="description" for="description" help="What is it, and what's the issue? e.g. “iPhone 13, screen cracked”" required class="mt-4">
                        <textarea
                            wire:model="description"
                            id="description"
                            rows="3"
                            class="block w-full rounded-lg border-line-strong bg-surface px-3 py-2 text-base text-ink shadow-soft placeholder:text-ink-faint focus:border-link focus:ring-link sm:text-sm"
                            placeholder="e.g. iPhone 13, screen cracked"
                        ></textarea>
                    </x-ui.field>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Customer" name="customerId" for="customerId" help="Leave blank for a walk-in customer">
                            <x-ui.select wire:model.live="customerId" id="customerId">
                                <option value="">Walk-in (no customer)</option>
                                <option value="__create__">+ New Customer</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Date" name="repairDate" for="repairDate">
                            <x-ui.input wire:model="repairDate" id="repairDate" type="date" max="{{ \Carbon\Carbon::now('Asia/Karachi')->toDateString() }}" />
                        </x-ui.field>
                    </div>
                </x-counter.section>

                <x-counter.section title="Charges">
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Service Charges" name="serviceCharges" class="col-span-2 sm:col-span-1" for="serviceCharges" help="Labour or service fee" required>
                            <x-ui.input wire:model.live="serviceCharges" id="serviceCharges" type="number" min="0" step="0.01" class="text-lg" />
                        </x-ui.field>

                        <x-ui.field label="Products Charges" name="productsCharges" for="productsCharges" help="Cost of any part used">
                            <x-ui.input wire:model.live="productsCharges" id="productsCharges" type="number" min="0" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="Discount" name="discount" for="discount">
                            <x-ui.input wire:model.live="discount" id="discount" type="number" min="0" step="0.01" />
                        </x-ui.field>
                    </div>
                </x-counter.section>

                <x-counter.section title="Payment">
                    <x-counter.payment-status
                        :statuses="$paymentStatuses"
                        :status="$paymentStatus"
                        :paid-from="$paidFrom"
                        amount-always
                        amount-help="How much the customer actually paid"
                    />
                </x-counter.section>

                <x-slot:summary>
                    <x-counter.summary
                        :total="$totalCollected"
                        :lines="[
                            ['label' => 'Service Charges', 'value' => 'Rs '.number_format((float) $serviceCharges, 2)],
                            ['label' => 'Products Charges', 'value' => 'Rs '.number_format((float) $productsCharges, 2)],
                            ['label' => 'Discount', 'value' => '− Rs '.number_format((float) $discount, 2)],
                        ]"
                    />
                </x-slot:summary>
            </x-counter.form>
        @endif
    </div>

    <livewire:customers.quick-create />
    <livewire:main-categories.quick-create />
    <livewire:accessory-categories.quick-create :default-main-category-slug="$mainCategorySlug" />
</div>
