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
    use Toasts;

    public string $customerId = '';
    public string $mainCategorySlug = 'mobile';
    public string $subCategoryName = '';
    public string $description = '';
    public string $amount = '';
    public string $discount = '0';
    public string $paymentStatus = 'paid';
    public string $amountPaid = '';

    public ?int $lastRepairId = null;

    protected string $mainCategorySlugBeforeCreate = '';

    public function mount(): void
    {
        MainCategory::ensureDefaultsExist();
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
        if ($this->paymentStatus !== 'partial') {
            $this->amountPaid = '';
        }
    }

    public function totalCollected(): float
    {
        return max(0.0, (float) ($this->amount !== '' ? $this->amount : 0) - (float) $this->discount);
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
        $this->validate([
            'mainCategorySlug' => ['required', 'string', Rule::exists('main_categories', 'slug')->where('shop_id', Auth::user()->shop_id)],
            'subCategoryName' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:1000'],
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)],
            'amount' => ['required', 'numeric', 'min:0'],
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

        $repair = Repair::create([
            'user_id' => Auth::id(),
            'customer_id' => $this->customerId !== '' ? $this->customerId : null,
            'category' => $this->mainCategorySlug,
            'sub_category' => $this->subCategoryName !== '' ? $this->subCategoryName : null,
            'description' => $this->description,
            'amount' => $this->amount,
            'discount' => $this->discount,
            'total' => $total,
            'payment_status' => $this->paymentStatus,
            'amount_paid' => $amountPaid,
        ]);

        $this->toastSuccess('Repair recorded.');
        $this->lastRepairId = $repair->id;
        $this->reset(['customerId', 'subCategoryName', 'description', 'amount', 'discount', 'amountPaid']);
        $this->mainCategorySlug = 'mobile';
        $this->paymentStatus = 'paid';
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
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Repairs</h1>
            <a href="{{ route('repairs.history') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                History →
            </a>
        </div>
    </x-slot>

    <div>
        @if ($lastRepair)
            <x-ui.card class="mx-auto max-w-lg">
                <div class="flex flex-col items-center text-center">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </div>

                    <p class="mt-3 text-sm text-slate-500">{{ $lastRepair->receiptNumber() }}</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($lastRepair->total, 2) }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $lastRepair->categoryLabel() }} · {{ $lastRepair->description }}</p>

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
            <x-ui.card title="New Repair">
                <form wire:submit="save" class="space-y-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <x-ui.field label="Main Category" name="mainCategorySlug" for="mainCategorySlug">
                            <x-ui.select wire:model.live="mainCategorySlug" id="mainCategorySlug">
                                <option value="__create__">+ New Main Category</option>
                                @foreach ($mainCategories as $option)
                                    <option value="{{ $option->slug }}">{{ $option->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Sub-Category" name="subCategoryName" for="subCategoryName" help="Optional">
                            <x-ui.image-select
                                wire:key="repair-sub-category-{{ $mainCategorySlug }}"
                                wire-model="subCategoryName"
                                :options="$subCategoryOptions"
                                id="subCategoryName"
                                placeholder="Select a sub-category"
                            />
                        </x-ui.field>

                        <x-ui.field label="Customer" name="customerId" for="customerId" help="Optional — leave blank for a walk-in customer">
                            <x-ui.select wire:model.live="customerId" id="customerId">
                                <option value="">Walk-in (no customer)</option>
                                <option value="__create__">+ New Customer</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>

                    <x-ui.field label="Description" name="description" for="description" help="What is it, and what's the issue? e.g. &quot;iPhone 13, screen cracked&quot;">
                        <textarea
                            wire:model="description"
                            id="description"
                            rows="3"
                            class="block w-full rounded-lg border-slate-300 py-2.5 px-3.5 text-base text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500"
                            placeholder="e.g. iPhone 13, screen cracked"
                        ></textarea>
                    </x-ui.field>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <x-ui.field label="Amount" name="amount" for="amount">
                            <x-ui.input wire:model.live="amount" id="amount" type="number" min="0" step="0.01" class="text-lg" />
                        </x-ui.field>

                        <x-ui.field label="Discount" name="discount" for="discount" help="Optional">
                            <x-ui.input wire:model.live="discount" id="discount" type="number" min="0" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="Payment Status" name="paymentStatus" for="paymentStatus" help="Has the customer paid you?">
                            <x-ui.select wire:model.live="paymentStatus" id="paymentStatus">
                                @foreach ($paymentStatuses as $status)
                                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        @if ($paymentStatus === 'partial')
                            <x-ui.field label="Amount Paid" name="amountPaid" for="amountPaid">
                                <x-ui.input wire:model="amountPaid" id="amountPaid" type="number" min="0" step="0.01" />
                            </x-ui.field>
                        @endif
                    </div>

                    <div class="flex items-center justify-between rounded-lg bg-brand-50 px-4 py-3">
                        <span class="text-sm font-medium text-brand-700">Total</span>
                        <span class="text-lg font-semibold text-brand-900">Rs {{ number_format($totalCollected, 2) }}</span>
                    </div>

                    <x-ui.button type="submit" size="lg" class="w-full justify-center sm:w-auto" wire:loading.attr="disabled" wire:target="save">
                        Save
                    </x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>

    <livewire:customers.quick-create />
    <livewire:main-categories.quick-create />
    <livewire:accessory-categories.quick-create :default-main-category-slug="$mainCategorySlug" />
</div>
