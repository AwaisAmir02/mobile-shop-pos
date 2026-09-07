<?php

use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\AccessoryCategoryOption;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\StockIn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Stock In')] class extends Component
{
    use Toasts;

    public string $categoryFilter = '';
    public string $subCategoryFilter = '';
    public string $productId = '';
    public string $quantity = '';
    public string $paymentStatus = 'paid';
    public string $amountPaid = '';
    public string $stock_date = '';
    public string $note = '';

    protected string $categoryFilterBeforeCreate = '';

    public function mount(): void
    {
        MainCategory::ensureDefaultsExist();
        $this->stock_date = now()->toDateString();
    }

    public function updatingCategoryFilter(string $value): void
    {
        if ($value === '__create__') {
            $this->categoryFilterBeforeCreate = $this->categoryFilter;
        }
    }

    public function updatedCategoryFilter(): void
    {
        if ($this->categoryFilter === '__create__') {
            $this->categoryFilter = $this->categoryFilterBeforeCreate;
            $this->dispatch('open-modal', name: 'quick-create-main-category');

            return;
        }

        $this->subCategoryFilter = '';
        $this->productId = '';
    }

    #[On('main-category-created')]
    public function onMainCategoryCreated(string $slug): void
    {
        $this->categoryFilter = $slug;
        $this->subCategoryFilter = '';
        $this->productId = '';
    }

    public function updatedSubCategoryFilter(): void
    {
        if ($this->subCategoryFilter === '__create__') {
            $this->subCategoryFilter = '';
            $this->dispatch('open-modal', name: 'quick-create-accessory-category');

            return;
        }

        $this->productId = '';
    }

    #[On('accessory-category-created')]
    public function onAccessoryCategoryCreated(string $categoryName): void
    {
        $this->subCategoryFilter = $categoryName;
        $this->productId = '';
    }

    public function updatedProductId(): void
    {
        if ($this->productId === '__create__') {
            $this->productId = '';
            $this->dispatch('open-modal', name: 'quick-create-product');
        }
    }

    #[On('product-created')]
    public function onProductCreated(int $productId, string $mainCategorySlug, string $subCategoryName = ''): void
    {
        $this->categoryFilter = $mainCategorySlug;
        $this->subCategoryFilter = $subCategoryName;
        $this->productId = (string) $productId;
    }

    public function updatedPaymentStatus(): void
    {
        if ($this->paymentStatus !== 'partial') {
            $this->amountPaid = '';
        }
    }

    public function with(): array
    {
        $currentMainCategory = MainCategory::where('slug', $this->categoryFilter)->first();

        return [
            'categories' => MainCategory::query()->orderBy('name')->get(),
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
            'products' => Product::query()
                ->when($this->categoryFilter, fn ($query) => $query->where('type', $this->categoryFilter))
                ->when($this->subCategoryFilter, fn ($query) => $query->where('details->category', $this->subCategoryFilter))
                ->orderBy('name')
                ->get(),
            'paymentStatuses' => PaymentStatus::cases(),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'productId' => ['required', 'integer', Rule::exists('products', 'id')->where('shop_id', auth()->user()->shop_id)],
            'quantity' => ['required', 'integer', 'min:1'],
            'paymentStatus' => ['required', Rule::enum(PaymentStatus::class)],
            'amountPaid' => ['nullable', 'numeric', 'min:0', 'required_if:paymentStatus,partial'],
            'stock_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () {
            $product = Product::whereKey($this->productId)->lockForUpdate()->firstOrFail();

            $product->increment('stock_quantity', (int) $this->quantity);

            $totalCost = $product->cost_price !== null ? $product->cost_price * (int) $this->quantity : null;

            $amountPaid = match ($this->paymentStatus) {
                'paid' => $totalCost ?? 0,
                'partial' => $this->amountPaid !== '' ? $this->amountPaid : 0,
                default => 0,
            };

            StockIn::create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'user_id' => Auth::id(),
                'quantity' => $this->quantity,
                'total_cost' => $totalCost,
                'payment_status' => $this->paymentStatus,
                'amount_paid' => $amountPaid,
                'stock_date' => $this->stock_date,
                'note' => $this->note !== '' ? $this->note : null,
            ]);
        });

        $this->toastSuccess('Stock added.');
        $this->reset(['productId', 'quantity', 'amountPaid', 'note']);
        $this->paymentStatus = 'paid';
        $this->stock_date = now()->toDateString();
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Stock In</h1>
            <a href="{{ route('stock-ins.history') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                History →
            </a>
        </div>
    </x-slot>

    <div>
        <x-ui.card title="Log Incoming Stock">
            <form wire:submit="save" class="space-y-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-ui.field label="Category" name="categoryFilter" for="categoryFilter" help="Optional — narrows the product list below">
                        <x-ui.select wire:model.live="categoryFilter" id="categoryFilter">
                            <option value="">All Categories</option>
                            <option value="__create__">+ New Main Category</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->slug }}">{{ $category->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Sub-Category" name="subCategoryFilter" for="subCategoryFilter" help="Optional — narrows the product list further">
                        <x-ui.image-select
                            wire:key="stock-in-sub-category-{{ $categoryFilter }}"
                            wire-model="subCategoryFilter"
                            :options="$subCategoryOptions"
                            id="subCategoryFilter"
                            placeholder="All Sub-Categories"
                        />
                    </x-ui.field>

                    <x-ui.field label="Product" name="productId" for="productId">
                        <x-ui.select wire:model="productId" id="productId">
                            <option value="">Select a product</option>
                            <option value="__create__">+ New Product</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }} (current stock: {{ $product->stock_quantity }})</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>

                <x-ui.field label="Quantity Purchase" name="quantity" for="quantity">
                    <x-ui.input wire:model="quantity" id="quantity" type="number" min="1" step="1" class="text-lg" />
                </x-ui.field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.field label="Payment Status" name="paymentStatus" for="paymentStatus" help="Have you paid your supplier for this stock?">
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

                    <x-ui.field label="Date Received" name="stock_date" for="stock_date">
                        <x-ui.input wire:model="stock_date" id="stock_date" type="date" />
                    </x-ui.field>

                    <x-ui.field label="Note" name="note" for="note" help="Optional — e.g. supplier name or reference">
                        <x-ui.input wire:model="note" id="note" />
                    </x-ui.field>
                </div>

                <x-ui.button type="submit" size="lg" class="w-full justify-center sm:w-auto" wire:loading.attr="disabled" wire:target="save">
                    Add Stock
                </x-ui.button>
            </form>
        </x-ui.card>
    </div>

    <livewire:main-categories.quick-create />
    <livewire:accessory-categories.quick-create :default-main-category-slug="$categoryFilter" />
    <livewire:products.quick-create :default-main-category-slug="$categoryFilter" />
</div>
