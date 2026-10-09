<?php

use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\AccessoryCategoryOption;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\StockIn;
use App\Models\Vendor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    public string $costPrice = '';
    public string $sellingPrice = '';

    public array $cart = [];

    public string $vendorId = '';
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

            return;
        }

        if ($this->productId !== '') {
            $product = Product::find($this->productId);
            $this->costPrice = $product?->cost_price !== null ? (string) $product->cost_price : '';
            $this->sellingPrice = $product !== null ? (string) $product->price : '';
        }
    }

    #[On('product-created')]
    public function onProductCreated(int $productId, string $mainCategorySlug, string $subCategoryName = ''): void
    {
        $this->categoryFilter = $mainCategorySlug;
        $this->subCategoryFilter = $subCategoryName;
        $this->productId = (string) $productId;
        $this->updatedProductId();
    }

    public function updatedVendorId(): void
    {
        if ($this->vendorId === '__create__') {
            $this->vendorId = '';
            $this->dispatch('open-modal', name: 'quick-create-vendor');
        }
    }

    #[On('vendor-created')]
    public function onVendorCreated(int $vendorId): void
    {
        $this->vendorId = (string) $vendorId;
    }

    public function updatedPaymentStatus(): void
    {
        if ($this->paymentStatus !== 'partial') {
            $this->amountPaid = '';
        }
    }

    public function addLine(): void
    {
        $this->validate([
            'productId' => ['required', 'integer', Rule::exists('products', 'id')->where('shop_id', Auth::user()->shop_id)],
            'quantity' => ['required', 'integer', 'min:1'],
            'costPrice' => ['nullable', 'numeric', 'min:0'],
            'sellingPrice' => ['required', 'numeric', 'min:0'],
        ]);

        $product = Product::findOrFail($this->productId);
        $quantity = (int) $this->quantity;

        if (isset($this->cart[$product->id])) {
            $this->cart[$product->id]['quantity'] += $quantity;
        } else {
            $this->cart[$product->id] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'category' => $product->typeLabel(),
                'quantity' => $quantity,
                'cost_price' => $this->costPrice !== '' ? (float) $this->costPrice : null,
                'selling_price' => (float) $this->sellingPrice,
            ];
        }

        $this->reset(['productId', 'quantity', 'costPrice', 'sellingPrice']);
    }

    public function removeLine(int $productId): void
    {
        unset($this->cart[$productId]);
    }

    public function lineTotalCost(array $line): float
    {
        return $line['cost_price'] !== null ? $line['cost_price'] * $line['quantity'] : 0.0;
    }

    public function grandTotalCost(): float
    {
        return array_sum(array_map(fn (array $line) => $this->lineTotalCost($line), $this->cart));
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
            'vendors' => Auth::user()->hasAccessTo('vendors') ? Vendor::query()->orderBy('name')->get() : collect(),
        ];
    }

    public function save(): void
    {
        $canUseVendors = Auth::user()->hasAccessTo('vendors');

        if (empty($this->cart)) {
            $this->toastError('Add at least one product before saving.');

            return;
        }

        $this->validate([
            'vendorId' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('shop_id', Auth::user()->shop_id)],
            'paymentStatus' => ['required', Rule::enum(PaymentStatus::class)],
            'amountPaid' => ['nullable', 'numeric', 'min:0', 'required_if:paymentStatus,partial'],
            'stock_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $grandTotal = $this->grandTotalCost();

        $headerAmountPaid = match ($this->paymentStatus) {
            'paid' => $grandTotal,
            'partial' => $this->amountPaid !== '' ? (float) $this->amountPaid : 0.0,
            default => 0.0,
        };

        $vendorId = $canUseVendors && $this->vendorId !== '' ? (int) $this->vendorId : null;
        $lines = array_values($this->cart);
        $count = count($lines);

        DB::transaction(function () use ($lines, $count, $grandTotal, $headerAmountPaid, $vendorId) {
            $amountAllocated = 0.0;

            foreach ($lines as $index => $line) {
                $product = Product::whereKey($line['product_id'])->lockForUpdate()->firstOrFail();
                $product->increment('stock_quantity', $line['quantity']);

                $lineTotalCost = $this->lineTotalCost($line);

                $isLast = $index === $count - 1;
                $lineAmountPaid = $grandTotal > 0
                    ? ($isLast ? round($headerAmountPaid - $amountAllocated, 2) : round($headerAmountPaid * ($lineTotalCost / $grandTotal), 2))
                    : 0.0;
                $amountAllocated += $lineAmountPaid;

                $linePaymentStatus = match (true) {
                    $lineTotalCost > 0 && $lineAmountPaid >= $lineTotalCost => PaymentStatus::Paid,
                    $lineAmountPaid > 0 => PaymentStatus::Partial,
                    default => PaymentStatus::Unpaid,
                };

                StockIn::create([
                    'product_id' => $product->id,
                    'vendor_id' => $vendorId,
                    'product_name' => $product->name,
                    'user_id' => Auth::id(),
                    'quantity' => $line['quantity'],
                    'cost_price' => $line['cost_price'],
                    'selling_price' => $line['selling_price'],
                    'quantity_remaining' => $line['quantity'],
                    'total_cost' => $line['cost_price'] !== null ? $lineTotalCost : null,
                    'payment_status' => $linePaymentStatus,
                    'amount_paid' => $lineAmountPaid,
                    'stock_date' => $this->stock_date,
                    'note' => $this->note !== '' ? $this->note : null,
                ]);
            }
        });

        $this->toastSuccess(count($lines) > 1 ? 'Stock added for '.count($lines).' products.' : 'Stock added.');
        $this->reset(['cart', 'vendorId', 'note', 'amountPaid']);
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

    <div class="space-y-6">
        <x-ui.card title="Add a Product">
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
                    <x-ui.select wire:model.live="productId" id="productId">
                        <option value="">Select a product</option>
                        <option value="__create__">+ New Product</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }} (current stock: {{ $product->stock_quantity }})</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-ui.field label="Quantity" name="quantity" for="quantity">
                    <x-ui.input wire:model="quantity" id="quantity" type="number" min="1" step="1" />
                </x-ui.field>

                <x-ui.field label="Cost Price (per unit)" name="costPrice" for="costPrice" help="Optional">
                    <x-ui.input wire:model="costPrice" id="costPrice" type="number" min="0" step="0.01" />
                </x-ui.field>

                <x-ui.field label="Selling Price (per unit)" name="sellingPrice" for="sellingPrice">
                    <x-ui.input wire:model="sellingPrice" id="sellingPrice" type="number" min="0" step="0.01" />
                </x-ui.field>
            </div>

            <div class="mt-4">
                <x-ui.button type="button" wire:click="addLine" wire:loading.attr="disabled" wire:target="addLine">
                    Add to Receipt
                </x-ui.button>
            </div>
        </x-ui.card>

        @if (! empty($cart))
            <x-ui.card title="This Receipt" :padding="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="whitespace-nowrap px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Product</th>
                                <th class="whitespace-nowrap px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Quantity</th>
                                <th class="whitespace-nowrap px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Cost Price</th>
                                <th class="whitespace-nowrap px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Selling Price</th>
                                <th class="whitespace-nowrap px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Total Cost</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($cart as $productId => $line)
                                <tr wire:key="stock-in-cart-{{ $productId }}">
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <p class="text-sm font-medium text-slate-900">{{ $line['name'] }}</p>
                                        <p class="text-xs text-slate-400">{{ $line['category'] }}</p>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-slate-700">{{ number_format($line['quantity']) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-slate-700">
                                        {{ $line['cost_price'] !== null ? 'Rs '.number_format($line['cost_price'], 2) : '—' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-slate-700">Rs {{ number_format($line['selling_price'], 2) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-slate-900">
                                        Rs {{ number_format($this->lineTotalCost($line), 2) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <button type="button" wire:click="removeLine({{ $productId }})" class="text-slate-400 hover:text-red-600">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-slate-100 p-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <x-ui.field label="Payment Status" name="paymentStatus" for="paymentStatus" help="Have you paid your supplier for this receipt?">
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
                            <x-ui.input wire:model="stock_date" id="stock_date" type="date" max="{{ now()->toDateString() }}" />
                        </x-ui.field>

                        @if (auth()->user()->hasAccessTo('vendors'))
                            <x-ui.field label="Vendor" name="vendorId" for="vendorId" help="Optional">
                                <x-ui.select wire:model="vendorId" id="vendorId">
                                    <option value="">No vendor</option>
                                    <option value="__create__">+ New Vendor</option>
                                    @foreach ($vendors as $vendor)
                                        <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>
                        @endif

                        <x-ui.field label="Note" name="note" for="note" help="Optional — e.g. supplier name or reference">
                            <x-ui.input wire:model="note" id="note" />
                        </x-ui.field>
                    </div>

                    <div class="mt-4 flex items-center justify-between rounded-lg bg-brand-50 px-4 py-3">
                        <span class="text-sm font-medium text-brand-700">Total Cost</span>
                        <span class="text-lg font-semibold text-brand-900">Rs {{ number_format($this->grandTotalCost(), 2) }}</span>
                    </div>

                    <div class="mt-4">
                        <x-ui.button type="button" wire:click="save" size="lg" class="w-full justify-center sm:w-auto" wire:loading.attr="disabled" wire:target="save">
                            Save Receipt
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        @endif
    </div>

    <livewire:main-categories.quick-create />
    <livewire:accessory-categories.quick-create :default-main-category-slug="$categoryFilter" />
    <livewire:brands.quick-create />
    <livewire:products.quick-create :default-main-category-slug="$categoryFilter" />
    @if (auth()->user()->hasAccessTo('vendors'))
        <livewire:vendors.quick-create />
    @endif
</div>
