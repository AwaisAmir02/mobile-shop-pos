<?php

use App\Actions\ConsumeStockFifo;
use App\Actions\CreateSale;
use App\Enums\QuantityUnit;
use App\Enums\SaleStatus;
use App\Livewire\Concerns\PicksPaymentMethod;
use App\Livewire\Concerns\PromptsForMeasuredQuantity;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SaleItem;
use App\Support\BatchExpiry;
use App\Support\Quantity;
use App\Support\SaleCartMath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('New Sale')] class extends Component
{
    use PicksPaymentMethod, PromptsForMeasuredQuantity, Toasts;

    const MAX_LINE_PRICE = 9999999.99;

    public string $search = '';
    public array $cart = [];
    public string $invoiceDiscount = '0';
    public string $customerId = '';
    public string $amountPaidNow = '';
    public string $saleDate = '';
    public ?int $pickerGroupId = null;

    public function mount(): void
    {
        $this->saleDate = now()->toDateString();
        $this->paymentMethod = 'cash';
    }

    public function updatedSearch(): void
    {
        $this->pickerGroupId = null;
    }

    public function openVariantPicker(int $groupId): void
    {
        $this->pickerGroupId = ProductGroup::findOrFail($groupId)->id;
    }

    public function closeVariantPicker(): void
    {
        $this->pickerGroupId = null;
    }

    protected function searchMatches()
    {
        return Product::query()
            ->where('stock_quantity', '>', 0)
            ->where('name', 'like', "%{$this->search}%");
    }

    /**
     * What each collapsed group shows. A group with one matching variant is
     * left out: that variant is listed directly, under its full name.
     *
     * @return array<int, array{name: string, count: int, stock: int|float, unit: QuantityUnit, fromPrice: float}> keyed by group id
     */
    protected function groupEntries($results): array
    {
        $groupIds = $results->pluck('product_group_id')->filter()->unique()->values();

        if ($groupIds->isEmpty()) {
            return [];
        }

        $matched = $this->searchMatches()->whereIn('product_group_id', $groupIds)->selectRaw('product_group_id, COUNT(*) as matched')->groupBy('product_group_id')->pluck('matched', 'product_group_id');
        $collapsing = $matched->filter(fn ($count) => (int) $count > 1)->keys();
        $inStock = Product::query()->whereIn('product_group_id', $collapsing)->where('stock_quantity', '>', 0)->get();
        $prices = Product::sellingPricesFor($inStock);
        $names = ProductGroup::whereIn('id', $collapsing)->pluck('name', 'id');

        return $inStock->groupBy('product_group_id')->map(fn ($variants, $groupId) => [
            'name' => $names[$groupId],
            'count' => $variants->count(),
            'stock' => Quantity::sum($variants->pluck('stock_quantity')),
            'unit' => $variants->first()->unit,
            'fromPrice' => min(array_intersect_key($prices, $variants->keyBy('id')->all())),
        ])->all();
    }

    /** Every variant of the open group, so the cashier can see which sizes have run out. */
    protected function pickerVariants()
    {
        if ($this->pickerGroupId === null) {
            return null;
        }

        $group = ProductGroup::with('variants')->find($this->pickerGroupId);

        if ($group === null) {
            return null;
        }

        $variants = $group->variants->whereNull('archived_at')->values();
        $prices = Product::sellingPricesFor($variants);

        return [
            'name' => $group->name,
            'rows' => $variants->map(fn (Product $variant) => ['variant' => $variant, 'price' => $prices[$variant->id]]),
        ];
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

    /**
     * Quick-pick tiles for an empty cart: this shop's best sellers over the last
     * 30 days that are in stock now. Read-only; tapping a tile calls addToCart
     * like a search result does. Sale and SaleItem are shop- and branch-scoped.
     */
    protected function quickPicks()
    {
        $soldIds = SaleItem::query()
            ->whereNotNull('product_id')
            ->whereHas('sale', fn ($query) => $query
                ->where('status', '!=', SaleStatus::Cancelled->value)
                ->where('created_at', '>=', now()->subDays(30)))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as sold')
            ->orderByDesc('sold')
            ->limit(24)
            ->pluck('product_id')
            ->values();

        if ($soldIds->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $soldIds)
            ->where('stock_quantity', '>', 0)
            ->whereNull('archived_at')
            ->get()
            ->sortBy(fn (Product $product) => $soldIds->search($product->id))
            ->take(8)
            ->values();
    }

    public function with(): array
    {
        $results = $this->search !== ''
            ? $this->searchMatches()
                ->oneRowPerGroup($this->searchMatches())
                ->orderBy('name')
                ->limit(8)
                ->get()
            : collect();

        return [
            'results' => $results,
            'groupEntries' => $this->groupEntries($results),
            'picker' => $this->pickerVariants(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'paymentAccounts' => $this->paymentAccounts(),
            'barcodeEnabled' => Auth::user()->canUseBarcodes(),
            'quickPicks' => empty($this->cart) && $this->search === '' ? $this->quickPicks() : collect(),
            'promptProduct' => $this->quantityPromptId !== null ? Product::find($this->quantityPromptId) : null,
        ];
    }

    public function addToCart(int $productId): void
    {
        $product = Product::findOrFail($productId);

        if ($product->stock_quantity <= 0) {
            $this->toastError("{$product->name} is out of stock.");

            return;
        }

        if ($product->unit->allowsDecimals()) {
            $this->search = '';
            $this->pickerGroupId = null;
            $this->promptForQuantity($product);

            return;
        }

        if (isset($this->cart[$productId])) {
            $newQuantity = Quantity::add($this->cart[$productId]['quantity'], 1);

            if ($newQuantity > $product->stock_quantity) {
                $this->toastError("Only {$product->stock_quantity} of {$product->name} in stock.");

                return;
            }

            $this->cart[$productId]['quantity'] = $newQuantity;
        } else {
            $this->cart[$productId] = $this->cartLineFor($product);
        }

        $this->search = '';
        $this->pickerGroupId = null;
    }

    protected function quantityLimitFor(Product $product): int|float
    {
        return $product->stock_quantity;
    }

    protected function cartLineFor(Product $product, int|float $quantity = 1): array
    {
        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'category' => $product->typeLabel(),
            'list_price' => (string) $product->currentSellingPrice(),
            'cost_price' => Auth::user()->hasAccessTo('sales-view-cost-price') ? $product->currentCostPrice() : null,
            'custom_price' => '',
            'quantity' => $quantity,
            'unit' => $product->unit->value,
            'discount' => '0',
            'max_stock' => $product->stock_quantity,
        ];
    }

    public function scanBarcode(string $raw): void
    {
        if (! Auth::user()->canUseBarcodes()) {
            return;
        }

        $code = trim($raw);

        if ($code === '') {
            $this->search = '';

            return;
        }

        $product = Product::where('barcode', $code)->first();

        if ($product === null) {
            if (Product::where('name', 'like', "%{$code}%")->exists()) {
                return;
            }

            $this->toastError("No product found for barcode {$code}.");
            $this->search = '';
            $this->dispatch('focus-scan');

            return;
        }

        $this->search = '';
        $this->dispatch('focus-scan');

        if ($product->stock_quantity <= 0) {
            $this->toastError("{$product->name} is out of stock.");

            return;
        }

        if ($product->unit->allowsDecimals()) {
            $this->promptForQuantity($product);

            return;
        }

        $inCart = Quantity::normalize($this->cart[$product->id]['quantity'] ?? 0);

        if ($inCart + 1 > $product->stock_quantity) {
            $this->toastError("Only {$product->stock_quantity} in stock — already in cart.");

            return;
        }

        if (isset($this->cart[$product->id])) {
            $this->cart[$product->id]['quantity'] = $inCart + 1;
        } else {
            $this->cart[$product->id] = $this->cartLineFor($product);
        }

        $this->toastSuccess("{$product->name} — now {$this->cart[$product->id]['quantity']} in cart.");
    }

    public function removeFromCart(int $productId): void
    {
        unset($this->cart[$productId]);
    }

    public function effectivePrice(array $item): float
    {
        return $item['custom_price'] !== '' && $item['custom_price'] !== null
            ? (float) $item['custom_price']
            : (float) $item['list_price'];
    }

    public function lineTotal(array $item): float
    {
        $product = Product::find($item['product_id']);

        if ($product === null) {
            return 0.0;
        }

        $allocations = ConsumeStockFifo::preview($product, Quantity::normalize($item['quantity']));
        $overridePrice = $item['custom_price'] !== '' && $item['custom_price'] !== null ? (float) $item['custom_price'] : null;
        $subLines = SaleCartMath::allocateLines($overridePrice, (float) $item['discount'], $allocations);

        return array_sum(array_column($subLines, 'line_total'));
    }

    /** @return array{expired: int|float, soonest: mixed}|null */
    public function expiryNote(array $item): ?array
    {
        $product = Product::find($item['product_id']);

        return $product ? BatchExpiry::noteFor($product, ConsumeStockFifo::preview($product, Quantity::normalize($item['quantity']))) : null;
    }

    public function subtotal(): float
    {
        return round(array_sum(array_map(fn (array $item) => $this->lineTotal($item), $this->cart)), 2);
    }

    public function total(): float
    {
        $subtotal = $this->subtotal();

        return round(max(0.0, $subtotal - min((float) $this->invoiceDiscount, $subtotal)), 2);
    }

    public function completeSale(): void
    {
        if (empty($this->cart)) {
            $this->toastError('Add at least one product to the cart.');

            return;
        }

        $total = $this->total();
        $paidNow = $this->amountPaidNow !== '' ? (float) $this->amountPaidNow : $total;
        $isFullyPaid = $paidNow >= $total;

        $rules = [
            'saleDate' => ['required', 'date', 'before_or_equal:today'],
            'invoiceDiscount' => ['required', 'numeric', 'min:0'],
            'amountPaidNow' => ['nullable', 'numeric', 'min:0', 'max:'.$total],
            'customerId' => [
                $isFullyPaid ? 'nullable' : 'required',
                'integer',
                Rule::exists('customers', 'id')->where('shop_id', auth()->user()->shop_id),
            ],
        ];

        foreach ($this->cart as $id => $item) {
            $rules["cart.$id.quantity"] = QuantityUnit::fromValue($item['unit'] ?? 'piece')->quantityRules();
            $rules["cart.$id.discount"] = ['required', 'numeric', 'min:0'];
            $rules["cart.$id.custom_price"] = ['nullable', 'numeric', 'min:0', 'max:'.self::MAX_LINE_PRICE];
        }

        $this->validate($rules);

        $lines = [];

        foreach ($this->cart as $productId => $item) {
            $lines[] = [
                'product_id' => (int) $productId,
                'quantity' => $item['quantity'],
                'discount' => (float) $item['discount'],
                'custom_price' => $item['custom_price'] !== '' && $item['custom_price'] !== null ? (float) $item['custom_price'] : null,
            ];
        }

        [$paymentMethod, $shopAccountId] = $this->chosenPaymentMethod();

        try {
            $sale = CreateSale::handle(
                lines: $lines,
                invoiceDiscount: (float) $this->invoiceDiscount,
                customerId: $this->customerId !== '' ? (int) $this->customerId : null,
                userId: Auth::id(),
                saleDate: $this->saleDate,
                amountPaid: $paidNow,
                paymentMethod: $paymentMethod,
                shopAccountId: $shopAccountId,
            );
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors() as $key => $messages) {
                $errors[preg_replace('/^lines\.(\d+)$/', 'cart.$1.quantity', $key)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        $this->toastSuccess('Sale completed.');
        // Lets the receipt open as a "sale complete" confirmation, once.
        session()->flash('sale-completed', $sale->id);
        $this->redirectRoute('sales.show', $sale, navigate: true);
    }
}; ?>

<div
    x-on:keydown.window.f2.prevent="$refs.scanInput.focus()"
    x-on:keydown.window.f9.prevent="$wire.completeSale()"
    @class(['pb-24 lg:pb-0' => ! empty($cart)])
>
    <x-slot name="header">
        <x-ui.page-header>New Sale</x-ui.page-header>
    </x-slot>

    <x-ui.module-tabs module="sales" label="Sales sections" />

    <x-billing.limit-notice limit="sales_per_month" informational />

    @php($itemCount = count($cart))

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="min-w-0 lg:col-span-2">
            <x-ui.card :padding="false">
                <div class="p-4 sm:p-5">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-faint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                        <x-ui.input
                            wire:model.live.debounce.250ms="search"
                            wire:keydown.enter.prevent="scanBarcode($event.target.value)"
                            x-ref="scanInput"
                            x-on:focus-scan.window="$refs.scanInput.focus()"
                            x-on:keydown.escape="$wire.$set('search', '')"
                            type="search"
                            placeholder="{{ $barcodeEnabled ? 'Search by name, or scan a barcode…' : 'Search by product name…' }}"
                            autofocus
                            autocomplete="off"
                            aria-label="Search products"
                            class="h-12 rounded-xl pl-12 pr-14 text-base"
                        />
                        <kbd class="pointer-events-none absolute right-3 top-1/2 hidden -translate-y-1/2 rounded-md bg-surface-sunken px-1.5 py-0.5 font-sans text-xs font-semibold text-ink-faint ring-1 ring-line sm:block">F2</kbd>
                    </div>

                    @if ($search !== '' && $picker)
                        <div class="mt-3 overflow-hidden rounded-xl border border-line">
                            <div class="flex items-center justify-between gap-3 border-b border-line bg-surface-sunken/60 px-4 py-2.5">
                                <p class="text-sm font-semibold text-ink">{{ $picker['name'] }}: choose a variant</p>
                                <button type="button" wire:click="closeVariantPicker" class="text-sm font-semibold text-link hover:underline">Back to results</button>
                            </div>
                            <div class="max-h-80 divide-y divide-line overflow-y-auto">
                                @foreach ($picker['rows'] as $row)
                                    <button
                                        type="button"
                                        wire:click="addToCart({{ $row['variant']->id }})"
                                        wire:key="picker-{{ $row['variant']->id }}"
                                        @disabled($row['variant']->stock_quantity <= 0)
                                        class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-surface-sunken disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <div>
                                            <p class="text-sm font-semibold text-ink">{{ $row['variant']->variant_label }}</p>
                                            <p class="text-xs text-ink-faint">{{ $row['variant']->stock_quantity > 0 ? $row['variant']->stockLabel().' in stock' : 'Out of stock' }}</p>
                                        </div>
                                        <span class="text-sm font-bold tabular-nums text-ink">Rs {{ number_format($row['price'], 2) }}{{ $row['variant']->unit->perSuffix() }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @elseif ($search !== '')
                        <div class="mt-3 divide-y divide-line overflow-hidden rounded-xl border border-line">
                            @forelse ($results as $product)
                                @if (isset($groupEntries[$product->product_group_id]))
                                    @php($entry = $groupEntries[$product->product_group_id])
                                    <button
                                        type="button"
                                        wire:click="openVariantPicker({{ $product->product_group_id }})"
                                        wire:key="result-group-{{ $product->product_group_id }}"
                                        class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-surface-sunken"
                                    >
                                        <div class="flex items-center gap-3">
                                            <x-ui.thumbnail :src="$product->imageUrl()" :label="$entry['name']" />
                                            <div>
                                                <p class="text-sm font-semibold text-ink">{{ $entry['name'] }}</p>
                                                <p class="text-xs text-ink-faint">{{ $product->typeLabel() }} · {{ $entry['count'] }} variants · {{ $entry['unit']->amount($entry['stock']) }} in stock</p>
                                            </div>
                                        </div>
                                        <span class="text-sm font-bold tabular-nums text-ink">From Rs {{ number_format($entry['fromPrice'], 2) }}{{ $entry['unit']->perSuffix() }}</span>
                                    </button>
                                @else
                                {{-- @else rather than a loop continue: skipping past @endif leaves Livewire's block markers unbalanced and the list stops updating. --}}
                                <button
                                    type="button"
                                    wire:click="addToCart({{ $product->id }})"
                                    wire:key="result-{{ $product->id }}"
                                    class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-surface-sunken"
                                >
                                    <div class="flex items-center gap-3">
                                        <x-ui.thumbnail :src="$product->imageUrl()" :label="$product->name" />
                                        <div>
                                            <p class="text-sm font-semibold text-ink">{{ $product->name }}</p>
                                            <p class="text-xs text-ink-faint">{{ $product->typeLabel() }} · {{ $product->stockLabel() }} in stock</p>
                                        </div>
                                    </div>
                                    <span class="text-sm font-bold tabular-nums text-ink">Rs {{ number_format($product->currentSellingPrice(), 2) }}{{ $product->unit->perSuffix() }}</span>
                                </button>
                                @endif
                            @empty
                                <p class="px-4 py-3 text-sm text-ink-faint">No in-stock products match "{{ $search }}".</p>
                            @endforelse
                        </div>
                    @endif
                </div>

                @if (empty($cart))
                    @if ($quickPicks->isNotEmpty())
                        <div class="border-t border-line p-4 sm:p-5" data-quick-picks>
                            <h2 class="mb-3 text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">Frequent products</h2>
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                                @foreach ($quickPicks as $product)
                                    <button
                                        type="button"
                                        wire:click="addToCart({{ $product->id }})"
                                        wire:key="pick-{{ $product->id }}"
                                        data-quick-pick="{{ $product->id }}"
                                        class="group flex min-w-0 flex-col items-start gap-2 rounded-xl border border-line bg-surface p-3 text-left transition hover:border-primary/30 hover:shadow-float focus-visible:ring-2 focus-visible:ring-link"
                                    >
                                        <x-ui.thumbnail :src="$product->imageUrl()" :label="$product->name" class="!h-10 !w-10 !rounded-xl" />
                                        <span class="line-clamp-2 text-sm font-semibold leading-snug text-ink">{{ $product->name }}</span>
                                        <span class="mt-auto text-sm font-bold tabular-nums text-ink">Rs {{ number_format($product->currentSellingPrice(), 2) }}{{ $product->unit->perSuffix() }}</span>
                                        <span class="text-xs text-ink-faint">{{ $product->stockLabel() }} in stock</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <div class="border-t border-line px-6 py-8 text-center">
                        <p class="text-sm font-semibold text-ink">Cart is empty</p>
                        <p class="mt-1 text-sm text-ink-faint">{{ $quickPicks->isNotEmpty() ? 'Tap a product above, or search to start a sale.' : 'Search for a product above to start a sale.' }}</p>
                    </div>
                @else
                    <div class="flex items-center justify-between border-t border-line bg-surface-sunken/60 px-4 py-2 sm:px-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">Cart · {{ $itemCount }} {{ \Illuminate\Support\Str::plural('line', $itemCount) }}</p>
                        <p class="text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">Total</p>
                    </div>
                    <ul class="divide-y divide-line">
                        @foreach ($cart as $productId => $item)
                            <x-sales.cart-line :product-id="$productId" :item="$item" :line-total="$this->lineTotal($item)" :expiry-note="$this->expiryNote($item)" />
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <div id="checkout" class="lg:sticky lg:top-24 lg:self-start">
            <x-ui.card :padding="false">
                <div class="p-5">
                    <div class="flex items-baseline justify-between">
                        <p class="text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">Total</p>
                        <p class="text-xs text-ink-faint">{{ $itemCount }} {{ \Illuminate\Support\Str::plural('line', $itemCount) }}</p>
                    </div>
                    <p class="mt-1 whitespace-nowrap text-4xl font-extrabold tracking-tight tabular-nums text-ink" data-register-total>Rs {{ number_format($this->total(), 2) }}</p>
                    <div class="mt-3 space-y-1 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-ink-faint">Subtotal</span>
                            <span class="font-medium tabular-nums text-ink">Rs {{ number_format($this->subtotal(), 2) }}</span>
                        </div>
                        @if ((float) $invoiceDiscount > 0)
                            <div class="flex items-center justify-between">
                                <span class="text-ink-faint">Invoice discount</span>
                                <span class="font-medium tabular-nums text-ink">− Rs {{ number_format(min((float) $invoiceDiscount, $this->subtotal()), 2) }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                <div
                    class="space-y-4 border-t border-line p-5"
                    x-data="{
                        mode: $wire.amountPaidNow === '' || $wire.amountPaidNow === null ? 'cash' : (parseFloat($wire.amountPaidNow) === 0 ? 'udhaar' : 'partial'),
                        pick(mode) {
                            this.mode = mode;
                            if (mode === 'cash') $wire.$set('amountPaidNow', '');
                            if (mode === 'udhaar') $wire.$set('amountPaidNow', '0');
                            if (mode === 'partial') {
                                if (parseFloat($wire.amountPaidNow) === 0) $wire.$set('amountPaidNow', '');
                                this.$nextTick(() => this.$refs.paid?.focus());
                            }
                        },
                    }"
                >
                    {{-- amountPaidNow: blank = paid in full, 0 = all on udhaar. --}}
                    <div>
                        <p class="mb-1.5 text-sm font-medium text-ink-muted">Payment</p>
                        <div class="grid grid-cols-3 gap-1 rounded-full border border-line bg-surface-sunken/60 p-1" role="group" aria-label="Payment">
                            @foreach (['cash' => 'Cash', 'partial' => 'Partial', 'udhaar' => 'Udhaar'] as $value => $label)
                                <button
                                    type="button"
                                    x-on:click="pick('{{ $value }}')"
                                    :aria-pressed="(mode === '{{ $value }}').toString()"
                                    data-payment-mode="{{ $value }}"
                                    class="rounded-full px-3 py-1.5 text-sm font-semibold transition-colors"
                                    :class="mode === '{{ $value }}' ? 'bg-surface text-ink shadow-soft ring-1 ring-line' : 'text-ink-faint hover:text-ink'"
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div x-show="mode === 'partial'" x-cloak>
                        <x-ui.field label="Amount Paid Now" name="amountPaidNow" for="amountPaidNow" help="Leave blank to record as paid in full">
                            <x-ui.input wire:model.live="amountPaidNow" x-ref="paid" id="amountPaidNow" type="number" min="0" step="0.01" :placeholder="number_format($this->total(), 2)" />
                        </x-ui.field>
                    </div>

                    <x-sales.payment-method-picker :accounts="$paymentAccounts" :value="$paymentMethod" amount="amountPaidNow" />

                    <x-ui.field label="Customer" name="customerId" for="customerId" :help="$amountPaidNow !== '' && (float) $amountPaidNow < $this->total() ? 'Required — this sale is not being paid in full' : 'Optional — leave blank for a walk-in sale'">
                        <x-ui.select wire:model.live="customerId" id="customerId">
                            <option value="">Walk-in (no customer)</option>
                            <option value="__create__">+ New Customer</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    @if ($amountPaidNow !== '' && (float) $amountPaidNow < $this->total())
                        <div class="flex items-center justify-between rounded-xl bg-warning-soft px-3 py-2 text-sm">
                            <span class="font-medium text-warning">Due After Sale</span>
                            <span class="font-bold tabular-nums text-warning">Rs {{ number_format($this->total() - (float) $amountPaidNow, 2) }}</span>
                        </div>
                    @endif

                    <div class="space-y-2 text-sm">
                        <div x-data="{ editing: @js($saleDate !== now()->toDateString() || $errors->has('saleDate')) }">
                            <div x-show="! editing" class="flex items-center justify-between">
                                <span class="text-ink-faint">Sale date</span>
                                <button type="button" x-on:click="editing = true; $nextTick(() => $refs.date.focus())" class="inline-flex items-center gap-1 font-semibold text-ink hover:text-link">
                                    Today
                                    <svg class="h-4 w-4 text-ink-faint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                </button>
                            </div>
                            <div x-show="editing" x-cloak>
                                <x-ui.field label="Sale Date" name="saleDate" for="saleDate">
                                    <x-ui.input wire:model="saleDate" x-ref="date" id="saleDate" type="date" max="{{ now()->toDateString() }}" />
                                </x-ui.field>
                            </div>
                        </div>

                        <div x-data="{ adding: @js((float) $invoiceDiscount > 0 || $errors->has('invoiceDiscount')) }">
                            <button type="button" x-show="! adding" x-on:click="adding = true; $nextTick(() => $refs.discount.focus())" class="font-semibold text-link hover:underline">
                                + Add discount
                            </button>
                            <div x-show="adding" x-cloak>
                                <x-ui.field label="Invoice Discount" name="invoiceDiscount" for="invoiceDiscount">
                                    <x-ui.input wire:model.live.debounce.300ms="invoiceDiscount" x-ref="discount" id="invoiceDiscount" type="number" min="0" step="0.01" />
                                </x-ui.field>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-t border-line p-5">
                    <x-ui.button
                        type="button"
                        wire:click="completeSale"
                        wire:loading.attr="disabled"
                        wire:target="completeSale"
                        variant="accent"
                        size="lg"
                        data-complete-sale
                        class="w-full justify-between gap-3 whitespace-nowrap px-5 py-3 text-base"
                    >
                        <span wire:loading.remove wire:target="completeSale">Complete Sale</span>
                        <span wire:loading.remove wire:target="completeSale" class="tabular-nums">Rs {{ number_format($this->total(), 2) }}</span>
                        <span wire:loading wire:target="completeSale">Completing…</span>
                    </x-ui.button>
                    <p class="mt-2 hidden text-center text-xs text-ink-faint lg:block">
                        <kbd class="rounded bg-surface-sunken px-1 font-sans font-semibold ring-1 ring-line">F2</kbd> search ·
                        <kbd class="rounded bg-surface-sunken px-1 font-sans font-semibold ring-1 ring-line">F9</kbd> complete ·
                        <kbd class="rounded bg-surface-sunken px-1 font-sans font-semibold ring-1 ring-line">Esc</kbd> clear search
                    </p>
                </div>
            </x-ui.card>
        </div>
    </div>

    @if (! empty($cart))
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 px-4 pt-3 backdrop-blur lg:hidden" style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom))" data-checkout-bar>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs text-ink-faint">{{ $itemCount }} {{ \Illuminate\Support\Str::plural('line', $itemCount) }}</p>
                    <p class="text-xl font-extrabold tabular-nums text-ink">Rs {{ number_format($this->total(), 2) }}</p>
                </div>
                <x-ui.button variant="accent" x-on:click="document.getElementById('checkout').scrollIntoView({ behavior: 'smooth' })">Checkout</x-ui.button>
            </div>
        </div>
    @endif

    <x-sales.quantity-prompt :product="$promptProduct" :limit="$promptProduct?->stock_quantity" />

    <livewire:customers.quick-create />
    <livewire:shop-accounts.quick-create />
</div>
