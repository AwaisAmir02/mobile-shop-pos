<?php

use App\Actions\CreateStockIn;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\QuantityUnit;
use App\Livewire\Concerns\Toasts;
use App\Models\AccessoryCategoryOption;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Stock In')] class extends Component
{
    use \App\Livewire\Concerns\ChoosesPaymentSource;

    use Toasts;

    public string $categoryFilter = '';
    public string $subCategoryFilter = '';
    public string $productId = '';
    public string $quantity = '1';
    public string $costPrice = '';
    public string $sellingPrice = '';
    public string $expiryDate = '';

    // 'piece', or 'pack' when the product has one: quantity and cost are then per pack.
    public string $buyIn = 'piece';

    /** @var array<int|string, array<string, mixed>> keyed by line, since one product can have several batches on a receipt */
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
        $this->amountPaid = '0';
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
            $this->buyIn = $product?->hasPack() ? 'pack' : 'piece';
            $this->costPrice = $product?->cost_price !== null ? (string) ($this->buyIn === 'pack' ? round((float) $product->cost_price * $product->pack_size, 2) : $product->cost_price) : '';
            $this->sellingPrice = $product !== null ? (string) $product->price : '';
            $this->expiryDate = $this->expiryEnabled() && $product?->shelf_life_days
                ? Carbon::parse($this->stock_date)->addDays($product->shelf_life_days)->toDateString()
                : '';
        }
    }

    public function updatedBuyIn(): void
    {
        $product = is_numeric($this->productId) ? Product::find($this->productId) : null;

        if (! $product?->hasPack()) {
            $this->buyIn = 'piece';

            return;
        }

        if ($this->costPrice !== '') {
            $this->costPrice = (string) ($this->buyIn === 'pack'
                ? round((float) $this->costPrice * $product->pack_size, 2)
                : round((float) $this->costPrice / $product->pack_size, 2));
        }
    }

    protected function buyingPacks(?Product $product): bool
    {
        return $this->buyIn === 'pack' && (bool) $product?->hasPack();
    }

    protected function expiryEnabled(): bool
    {
        return (bool) Auth::user()->shop?->expiry_tracking_enabled;
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
        $this->amountPaid = match ($this->paymentStatus) {
            'paid' => (string) $this->grandTotalCost(),
            'unpaid' => '0',
            default => '',
        };
    }

    public function updatedAmountPaid(): void
    {
        $amount = $this->amountPaid !== '' ? (float) $this->amountPaid : 0.0;
        $total = $this->grandTotalCost();

        $this->paymentStatus = match (true) {
            $amount <= 0 => 'unpaid',
            $total <= 0 || $amount >= $total => 'paid',
            default => 'partial',
        };
    }

    protected function refreshAmountPaidForTotalChange(): void
    {
        if ($this->paymentStatus === 'paid') {
            $this->amountPaid = (string) $this->grandTotalCost();
        }
    }

    public function addLine(): void
    {
        $this->validate([
            'productId' => ['required', 'integer', Rule::exists('products', 'id')->where('shop_id', Auth::user()->shop_id)],
            'quantity' => $this->selectedUnit()->quantityRules(),
            'costPrice' => ['nullable', 'numeric', 'min:0'],
            'sellingPrice' => ['required', 'numeric', 'min:0'],
            'expiryDate' => ['nullable', 'date'],
        ]);

        $product = Product::findOrFail($this->productId);
        $quantity = Quantity::normalize($this->quantity);

        $costPrice = $this->costPrice !== '' ? (float) $this->costPrice : null;
        $sellingPrice = (float) $this->sellingPrice;
        $expiryDate = $this->expiryEnabled() && $this->expiryDate !== '' ? $this->expiryDate : null;

        if ($this->buyingPacks($product)) {
            // Stock is kept in pieces. The cost per piece is rounded; the amount owed is the exact pack total.
            $packs = (int) $quantity;
            $key = $this->lineKeyFor($product->id, -1.0, $sellingPrice, $expiryDate);
            $this->cart[$key] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'category' => $product->typeLabel(),
                'quantity' => $packs * $product->pack_size,
                'unit' => $product->unit->value,
                'cost_price' => $costPrice !== null ? round($costPrice / $product->pack_size, 2) : null,
                'total_cost' => $costPrice !== null ? round($costPrice * $packs, 2) : null,
                'selling_price' => $sellingPrice,
                'expiry_date' => $expiryDate,
                'pack_name' => $product->pack_name,
                'pack_size' => $product->pack_size,
                'packs' => $packs,
                'pack_label' => $product->packLabel($packs).' of '.$product->pack_size,
                'pack_cost' => $costPrice,
            ];

            $this->reset(['productId', 'quantity', 'costPrice', 'sellingPrice', 'expiryDate']);
            $this->buyIn = 'piece';
            $this->refreshAmountPaidForTotalChange();

            return;
        }

        $key = $this->lineKeyFor($product->id, $costPrice, $sellingPrice, $expiryDate);

        if (isset($this->cart[$key])) {
            $this->cart[$key]['quantity'] = Quantity::add($this->cart[$key]['quantity'], $quantity);
        } else {
            $this->cart[$key] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'category' => $product->typeLabel(),
                'quantity' => $quantity,
                'unit' => $product->unit->value,
                'cost_price' => $costPrice,
                'selling_price' => $sellingPrice,
                'expiry_date' => $expiryDate,
            ];
        }

        $this->reset(['productId', 'quantity', 'costPrice', 'sellingPrice', 'expiryDate']);
        $this->refreshAmountPaidForTotalChange();
    }

    /**
     * Each line becomes its own batch, so only a truly identical line adds to
     * an existing one. A different price or expiry date stays a separate line
     * rather than silently taking on the first line's figures.
     */
    protected function lineKeyFor(int $productId, ?float $costPrice, float $sellingPrice, ?string $expiryDate): int|string
    {
        foreach ($this->cart as $key => $line) {
            // Loose on purpose: a float like 250.0 comes back from the browser as the integer 250.
            if (empty($line['packs']) && $line['product_id'] === $productId && $line['cost_price'] == $costPrice && ($line['cost_price'] === null) === ($costPrice === null) && $line['selling_price'] == $sellingPrice && ($line['expiry_date'] ?? null) === $expiryDate) {
                return $key;
            }
        }

        if (! isset($this->cart[$productId])) {
            return $productId;
        }

        for ($n = 2; isset($this->cart["{$productId}-{$n}"]); $n++);

        return "{$productId}-{$n}";
    }

    public function removeLine(int|string $key): void
    {
        unset($this->cart[$key]);
        $this->refreshAmountPaidForTotalChange();
    }

    public function lineTotalCost(array $line): float
    {
        return CreateStockIn::lineTotalCost($line);
    }

    public function grandTotalCost(): float
    {
        return CreateStockIn::grandTotalCost($this->cart);
    }

    protected function selectedUnit(): QuantityUnit
    {
        return is_numeric($this->productId) ? (Product::find($this->productId)?->unit ?? QuantityUnit::Piece) : QuantityUnit::Piece;
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
            'selectedUnit' => $this->selectedUnit(),
            'selectedProduct' => $selectedProduct = (is_numeric($this->productId) ? Product::find($this->productId) : null),
            'packMode' => $this->buyingPacks($selectedProduct),
            'expiryEnabled' => $this->expiryEnabled(),
            'openOrders' => Auth::user()->shop?->purchase_orders_enabled && Auth::user()->hasAccessTo('receive-goods')
                ? PurchaseOrder::query()->whereIn('status', [PurchaseOrderStatus::Open->value, PurchaseOrderStatus::PartiallyReceived->value])->count()
                : 0,
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
            'cart.*.expiry_date' => ['nullable', 'date'],
        ]);

        $vendorId = $canUseVendors && $this->vendorId !== '' ? (int) $this->vendorId : null;
        $lines = array_values($this->cart);

        CreateStockIn::handle(
            $lines,
            $vendorId,
            $this->paymentStatus,
            $this->amountPaid !== '' ? (float) $this->amountPaid : null,
            $this->stock_date,
            $this->note,
            Auth::id(),
            paymentMethod: $this->paidFromParts()[0],
            shopAccountId: $this->paidFromParts()[1],
        );

        $this->toastSuccess(count($lines) > 1 ? 'Stock added for '.count($lines).' products.' : 'Stock added.');
        $this->reset(['cart', 'vendorId', 'note', 'amountPaid']);
        $this->paymentStatus = 'paid';
        $this->amountPaid = '0';
        $this->stock_date = now()->toDateString();
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('stock-ins.history') }}" back-label="Stock In History" subtitle="Goods that have arrived without a purchase order">Stock In</x-ui.page-header>
    </x-slot>

    @if ($openOrders > 0)
        <div class="mb-4 rounded-xl border border-info/20 bg-info-soft px-4 py-3 text-sm text-info" data-open-po-notice>
            {{ $openOrders }} purchase {{ \Illuminate\Support\Str::plural('order', $openOrders) }} {{ $openOrders === 1 ? 'is' : 'are' }} waiting for delivery. If these goods are on one of them,
            <a href="{{ route('purchase-orders.index') }}" wire:navigate class="font-semibold underline">receive them on the order</a> instead, so the order is matched and the stock isn't added twice.
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            <x-ui.card :title="empty($cart) ? 'Add a Product' : 'Add Another Product'">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-ui.field label="Category" name="categoryFilter" for="categoryFilter" help="Narrows the product list">
                        <x-ui.select wire:model.live="categoryFilter" id="categoryFilter">
                            <option value="">All Categories</option>
                            <option value="__create__">+ New Main Category</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->slug }}">{{ $category->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Sub-Category" name="subCategoryFilter" for="subCategoryFilter" help="Narrows it further">
                        <x-ui.image-select
                            wire:key="stock-in-sub-category-{{ $categoryFilter }}"
                            wire-model="subCategoryFilter"
                            :options="$subCategoryOptions"
                            id="subCategoryFilter"
                            placeholder="All Sub-Categories"
                        />
                    </x-ui.field>

                    <x-ui.field label="Product" name="productId" for="productId" required>
                        <x-ui.select wire:model.live="productId" id="productId">
                            <option value="">Select a product</option>
                            <option value="__create__">+ New Product</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }} (current stock: {{ $product->stockLabel() }})</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>

                @if ($selectedProduct?->hasPack())
                    <div class="mt-4 inline-flex gap-1 rounded-full border border-line bg-surface-sunken/60 p-1 text-sm font-semibold" role="group" aria-label="Bought as" data-buy-in>
                        @foreach (['pack' => 'By the '.mb_strtolower($selectedProduct->pack_name).' ('.$selectedProduct->pack_size.')', 'piece' => 'Loose pieces'] as $value => $label)
                            <label @class(['cursor-pointer rounded-full px-3 py-1.5 transition-colors', 'bg-surface text-ink shadow-soft ring-1 ring-line' => $buyIn === $value, 'text-ink-faint hover:text-ink' => $buyIn !== $value])>
                                <input type="radio" wire:model.live="buyIn" value="{{ $value }}" class="sr-only"> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                @endif

                <div @class(['mt-4 grid grid-cols-1 gap-4', 'sm:grid-cols-3' => ! $expiryEnabled, 'sm:grid-cols-2 lg:grid-cols-4' => $expiryEnabled])>
                    <x-ui.field :label="$packMode ? 'How many '.\Illuminate\Support\Str::plural(mb_strtolower($selectedProduct->pack_name)) : ($selectedUnit->allowsDecimals() ? 'Quantity ('.$selectedUnit->symbol().')' : 'Quantity')" name="quantity" for="quantity" required :help="$packMode ? 'Each is '.$selectedProduct->pack_size.' pieces' : null">
                        <x-ui.input wire:model="quantity" id="quantity" type="number" :min="$selectedUnit->inputMin()" :step="$selectedUnit->inputStep()" />
                    </x-ui.field>

                    <x-ui.field :label="$packMode ? 'Cost Price (per '.mb_strtolower($selectedProduct->pack_name).')' : 'Cost Price (per '.($selectedUnit->allowsDecimals() ? $selectedUnit->symbol() : 'unit').')'" name="costPrice" for="costPrice">
                        <x-ui.input wire:model="costPrice" id="costPrice" type="number" min="0" step="0.01" />
                    </x-ui.field>

                    <x-ui.field :label="'Selling Price (per '.($selectedUnit->allowsDecimals() ? $selectedUnit->symbol() : 'unit').')'" name="sellingPrice" for="sellingPrice" required>
                        <x-ui.input wire:model="sellingPrice" id="sellingPrice" type="number" min="0" step="0.01" />
                    </x-ui.field>

                    @if ($expiryEnabled)
                        <x-ui.field label="Expiry Date" name="expiryDate" for="expiryDate" help="The last day it can be sold">
                            <x-ui.input wire:model="expiryDate" id="expiryDate" type="date" />
                        </x-ui.field>
                    @endif
                </div>

                <div class="mt-5">
                    <x-ui.button type="button" wire:click="addLine" wire:loading.attr="disabled" wire:target="addLine">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        {{ empty($cart) ? 'Add to Receipt' : 'Add More' }}
                    </x-ui.button>
                </div>
            </x-ui.card>

            <x-ui.card :padding="false">
                <div class="flex items-center justify-between border-b border-line bg-surface-sunken/60 px-4 py-2.5 sm:px-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">Receipt · {{ count($cart) }} {{ \Illuminate\Support\Str::plural('line', count($cart)) }}</p>
                    <p class="text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">Cost</p>
                </div>
                @if (empty($cart))
                    <p class="px-5 py-8 text-center text-sm text-ink-faint">Nothing on this receipt yet. Add the products that arrived above.</p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($cart as $productId => $line)
                            <li wire:key="stock-in-cart-{{ $productId }}" class="flex items-start justify-between gap-4 px-4 py-3.5 sm:px-5">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-on-primary-soft">
                                        {{ $loop->iteration }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-ink">{{ $line['name'] }}</p>
                                        <p class="text-xs text-ink-faint">
                                            @if (! empty($line['packs']))
                                                {{ $line['category'] }} · {{ $line['pack_label'] }} = {{ number_format($line['quantity']) }} pieces
                                                · Cost {{ $line['pack_cost'] !== null ? 'Rs '.number_format($line['pack_cost'], 2).' each' : '—' }}
                                            @else
                                                {{ $line['category'] }} · Qty {{ QuantityUnit::fromValue($line['unit'] ?? 'piece')->groupedAmount($line['quantity']) }}
                                                · Cost {{ $line['cost_price'] !== null ? 'Rs '.number_format($line['cost_price'], 2) : '—' }}
                                            @endif
                                            · Sell Rs {{ number_format($line['selling_price'], 2) }}
                                            @if (! empty($line['expiry_date']))
                                                · Expires {{ Carbon::parse($line['expiry_date'])->format('d M Y') }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <span class="whitespace-nowrap text-sm font-bold tabular-nums text-ink">Rs {{ number_format($this->lineTotalCost($line), 2) }}</span>
                                    <button type="button" wire:click="removeLine(@js($productId))" aria-label="Remove {{ $line['name'] }}" class="rounded-lg p-1 text-ink-faint hover:bg-danger-soft hover:text-danger">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-6 lg:sticky lg:top-24 lg:self-start">
            <x-ui.card title="Vendor & Payment" description="Applies to every product on this receipt.">
                <div class="space-y-4">
                    @if (auth()->user()->hasAccessTo('vendors'))
                        <x-ui.field label="Vendor" name="vendorId" for="vendorId">
                            <x-ui.select wire:model="vendorId" id="vendorId">
                                <option value="">No vendor</option>
                                <option value="__create__">+ New Vendor</option>
                                @foreach ($vendors as $vendor)
                                    <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    @endif

                    <x-ui.field label="Payment Status" name="paymentStatus" for="paymentStatus" help="Have you paid your supplier for this receipt?">
                        <x-ui.select wire:model.live="paymentStatus" id="paymentStatus">
                            @foreach ($paymentStatuses as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Amount Paid" name="amountPaid" for="amountPaid" help="How much you actually paid the vendor">
                        <x-ui.input wire:model.live="amountPaid" id="amountPaid" type="number" min="0" step="0.01" />
                    </x-ui.field>
                    <x-ledger.paid-from :value="$paidFrom" />

                    <x-ui.field label="Date Received" name="stock_date" for="stock_date" required>
                        <x-ui.input wire:model="stock_date" id="stock_date" type="date" max="{{ now()->toDateString() }}" />
                    </x-ui.field>

                    <x-ui.field label="Note" name="note" for="note" help="e.g. the supplier's bill number">
                        <x-ui.input wire:model="note" id="note" />
                    </x-ui.field>
                </div>
            </x-ui.card>

            <x-ui.card :padding="false">
                <div class="p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">Total Cost</p>
                    <p class="mt-1 whitespace-nowrap text-3xl font-extrabold tracking-tight tabular-nums text-ink">Rs {{ number_format($this->grandTotalCost(), 2) }}</p>
                </div>
                <div class="border-t border-line p-5">
                    <x-ui.button type="button" wire:click="save" size="lg" class="w-full justify-center py-3" wire:loading.attr="disabled" wire:target="save">
                        Save Receipt
                    </x-ui.button>
                </div>
            </x-ui.card>
        </div>
    </div>

    <livewire:main-categories.quick-create />
    <livewire:accessory-categories.quick-create :default-main-category-slug="$categoryFilter" />
    <livewire:brands.quick-create />
    <livewire:products.quick-create :default-main-category-slug="$categoryFilter" />
    @if (auth()->user()->hasAccessTo('vendors'))
        <livewire:vendors.quick-create />
    @endif
</div>
