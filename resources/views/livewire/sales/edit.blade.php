<?php

use App\Actions\ConsumeStockFifo;
use App\Actions\UpdateSale;
use App\Enums\QuantityUnit;
use App\Livewire\Concerns\PromptsForMeasuredQuantity;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
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

new #[Layout('layouts.app')] #[Title('Edit Sale')] class extends Component
{
    use PromptsForMeasuredQuantity, Toasts;

    const MAX_LINE_PRICE = 9999999.99;

    public Sale $sale;

    public string $search = '';
    public array $cart = [];
    public array $legacyItems = [];
    public string $invoiceDiscount = '0';
    public string $customerId = '';
    public string $saleDate = '';

    public function mount(Sale $sale): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales-edit-cancel'), 403);
        abort_if($sale->isCancelled(), 403, 'A cancelled sale cannot be edited.');

        $sale->loadMissing(['items', 'payments']);

        $this->sale = $sale;
        $this->invoiceDiscount = (string) $sale->discount_amount;
        $this->customerId = $sale->customer_id !== null ? (string) $sale->customer_id : '';
        $this->saleDate = $sale->created_at->toDateString();

        foreach ($sale->items->groupBy('product_id') as $productId => $itemsForProduct) {
            $product = $productId !== '' ? Product::find($productId) : null;

            $canViewCostPrice = Auth::user()->hasAccessTo('sales-view-cost-price');

            if ($product === null) {
                foreach ($itemsForProduct as $item) {
                    $this->legacyItems[] = [
                        'product_name' => $item->product_name,
                        'product_type' => $item->product_type,
                        'quantity' => $item->quantity,
                        'unit' => $item->unit->value,
                        'unit_price' => (float) $item->unit_price,
                        'discount_amount' => (float) $item->discount_amount,
                        'line_total' => (float) $item->line_total,
                        'cost_price' => $canViewCostPrice && $item->cost_price !== null ? (float) $item->cost_price : null,
                    ];
                }

                continue;
            }

            $firstItem = $itemsForProduct->first();
            $listPrice = $product->currentSellingPrice();
            $wasOverridden = $firstItem->list_price !== null && (float) $firstItem->list_price !== (float) $firstItem->unit_price;
            $totalQuantity = Quantity::sum($itemsForProduct->pluck('quantity'));
            $frozenCostPrice = $firstItem->cost_price !== null ? (float) $firstItem->cost_price : $product->currentCostPrice();

            $this->cart[$product->id] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'category' => $product->typeLabel(),
                'type' => $product->type,
                'list_price' => (string) $listPrice,
                'cost_price' => $canViewCostPrice ? $frozenCostPrice : null,
                'custom_price' => $wasOverridden ? (string) $firstItem->unit_price : '',
                'quantity' => $totalQuantity,
                'unit' => $product->unit->value,
                'discount' => (string) $itemsForProduct->sum('discount_amount'),
                'max_stock' => Quantity::add($product->stock_quantity, $totalQuantity),
            ];
        }
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

    public function with(): array
    {
        return [
            'results' => $this->search !== ''
                ? Product::query()
                    ->where('stock_quantity', '>', 0)
                    ->where('name', 'like', "%{$this->search}%")
                    ->orderBy('name')
                    ->limit(8)
                    ->get()
                : collect(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'promptProduct' => ($promptProduct = $this->quantityPromptId !== null ? Product::find($this->quantityPromptId) : null),
            'promptLimit' => $promptProduct ? $this->quantityLimitFor($promptProduct) : null,
        ];
    }

    public function addToCart(int $productId): void
    {
        $product = Product::findOrFail($productId);

        $this->search = '';

        if ($product->unit->allowsDecimals()) {
            $this->promptForQuantity($product);

            return;
        }

        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['quantity'] = Quantity::add($this->cart[$productId]['quantity'], 1);
        } else {
            $this->cart[$productId] = $this->cartLineFor($product);
        }
    }

    /** Stock on hand, plus whatever this sale already holds (saving puts that back first). */
    protected function quantityLimitFor(Product $product): int|float
    {
        return $this->cart[$product->id]['max_stock'] ?? $product->stock_quantity;
    }

    protected function cartLineFor(Product $product, int|float $quantity = 1): array
    {
        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'category' => $product->typeLabel(),
            'type' => $product->type,
            'list_price' => (string) $product->currentSellingPrice(),
            'cost_price' => Auth::user()->hasAccessTo('sales-view-cost-price') ? $product->currentCostPrice() : null,
            'custom_price' => '',
            'quantity' => $quantity,
            'unit' => $product->unit->value,
            'discount' => '0',
            'max_stock' => $product->stock_quantity,
        ];
    }

    public function removeFromCart(int $productId): void
    {
        unset($this->cart[$productId]);
    }

    public function removeLegacyItem(int $index): void
    {
        unset($this->legacyItems[$index]);
        $this->legacyItems = array_values($this->legacyItems);
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

        $allocations = $this->previewAllocations($product, $item);
        $overridePrice = $item['custom_price'] !== '' && $item['custom_price'] !== null ? (float) $item['custom_price'] : null;
        $subLines = SaleCartMath::allocateLines($overridePrice, (float) $item['discount'], $allocations);

        return array_sum(array_column($subLines, 'line_total'));
    }

    protected function previewAllocations(Product $product, array $item): array
    {
        $originalHoldings = $this->sale->items
            ->where('product_id', $item['product_id'])
            ->whereNotNull('stock_in_id')
            ->groupBy('stock_in_id')
            ->map(fn ($group) => Quantity::sum($group->pluck('quantity')))
            ->all();

        return ConsumeStockFifo::previewForEdit($product, Quantity::normalize($item['quantity']), $originalHoldings);
    }

    /** @return array{expired: int|float, soonest: mixed}|null */
    public function expiryNote(array $item): ?array
    {
        $product = Product::find($item['product_id']);

        return $product ? BatchExpiry::noteFor($product, $this->previewAllocations($product, $item)) : null;
    }

    public function legacySubtotal(): float
    {
        return collect($this->legacyItems)->sum('line_total');
    }

    public function subtotal(): float
    {
        return round(array_sum(array_map(fn (array $item) => $this->lineTotal($item), $this->cart)) + $this->legacySubtotal(), 2);
    }

    public function total(): float
    {
        return round(max(0.0, $this->subtotal() - min((float) $this->invoiceDiscount, $this->subtotal())), 2);
    }

    public function alreadyPaid(): float
    {
        return (float) $this->sale->payments->sum('amount');
    }

    public function saveChanges(): void
    {
        if (empty($this->cart) && empty($this->legacyItems)) {
            $this->toastError('A sale must have at least one item.');

            return;
        }

        $total = $this->total();

        $rules = [
            'saleDate' => ['required', 'date', 'before_or_equal:today'],
            'invoiceDiscount' => ['required', 'numeric', 'min:0'],
            'customerId' => [
                $this->alreadyPaid() >= $total ? 'nullable' : 'required',
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

        $lines = collect($this->cart)->map(fn (array $item) => [
            'product_id' => $item['product_id'],
            'product_name' => $item['name'],
            'product_type' => $item['type'],
            'custom_price' => $item['custom_price'] !== '' && $item['custom_price'] !== null ? (float) $item['custom_price'] : null,
            'quantity' => $item['quantity'],
            'discount' => (float) $item['discount'],
        ])->values()->all();

        foreach ($this->legacyItems as $legacy) {
            $lines[] = [
                'product_id' => null,
                'product_name' => $legacy['product_name'],
                'product_type' => $legacy['product_type'],
                'custom_price' => $legacy['unit_price'],
                'quantity' => $legacy['quantity'],
                'unit' => $legacy['unit'] ?? 'piece',
                'discount' => $legacy['discount_amount'],
            ];
        }

        try {
            UpdateSale::handle(
                $this->sale,
                $lines,
                (float) $this->invoiceDiscount,
                $this->customerId !== '' ? (int) $this->customerId : null,
                Auth::id(),
                $this->saleDate,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess('Sale updated.');
        $this->redirectRoute('sales.show', $this->sale, navigate: true);
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('sales.show', $sale) }}" back-label="{{ $sale->invoiceNumber() }}">Edit {{ $sale->invoiceNumber() }}</x-ui.page-header>
    </x-slot>

    @if ($this->alreadyPaid() > 0)
        <x-ui.alert variant="warning" class="mb-4">
            Rs {{ number_format($this->alreadyPaid(), 2) }} has already been paid against this sale. The new total cannot be less than that.
        </x-ui.alert>
    @endif

    @foreach (['total', 'lines', 'customerId'] as $field)
        @error($field)
            <x-ui.alert variant="danger" class="mb-4">{{ $message }}</x-ui.alert>
        @enderror
    @endforeach

    @php($lineCount = count($cart) + count($legacyItems))

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
                            type="search"
                            placeholder="Search by product name to add a line…"
                            aria-label="Search products"
                            class="h-12 rounded-xl pl-12 text-base"
                        />
                    </div>

                    @if ($search !== '')
                        <div class="mt-3 divide-y divide-line overflow-hidden rounded-xl border border-line">
                            @forelse ($results as $product)
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
                            @empty
                                <p class="px-4 py-3 text-sm text-ink-faint">No in-stock products match "{{ $search }}".</p>
                            @endforelse
                        </div>
                    @endif
                </div>

                @if (empty($cart) && empty($legacyItems))
                    <div class="border-t border-line px-6 py-8 text-center">
                        <p class="text-sm font-semibold text-ink">No items</p>
                        <p class="mt-1 text-sm text-ink-faint">Search for a product above to add a line.</p>
                    </div>
                @else
                    <div class="flex items-center justify-between border-t border-line bg-surface-sunken/60 px-4 py-2 sm:px-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">Lines · {{ $lineCount }}</p>
                        <p class="text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">Total</p>
                    </div>
                    <ul class="divide-y divide-line">
                        @foreach ($legacyItems as $index => $item)
                            <li wire:key="legacy-{{ $index }}" class="px-4 py-3.5 sm:px-5">
                                <div class="flex items-start gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-1.5">
                                            <p class="truncate text-sm font-semibold text-ink">{{ $item['product_name'] }}</p>
                                            @if ($item['cost_price'] !== null)
                                                <x-ui.info-tooltip :text="'Cost: Rs '.number_format($item['cost_price'], 2)" />
                                            @endif
                                        </div>
                                        <p class="text-xs text-ink-faint">
                                            {{ \App\Enums\QuantityUnit::fromValue($item['unit'] ?? 'piece')->amount($item['quantity']) }} × Rs {{ number_format($item['unit_price'], 2) }}
                                        </p>
                                        <p class="mt-0.5 text-xs font-semibold text-warning">Product no longer exists — read-only</p>
                                    </div>
                                    <p class="w-28 shrink-0 text-right text-sm font-bold tabular-nums text-ink">Rs {{ number_format($item['line_total'], 2) }}</p>
                                    <button type="button" wire:click="removeLegacyItem({{ $index }})" aria-label="Remove {{ $item['product_name'] }}" class="rounded-lg p-1 text-ink-faint hover:bg-danger-soft hover:text-danger">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </li>
                        @endforeach
                        @foreach ($cart as $productId => $item)
                            <x-sales.cart-line :product-id="$productId" :item="$item" :line-total="$this->lineTotal($item)" :expiry-note="$this->expiryNote($item)" />
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <div class="lg:sticky lg:top-24 lg:self-start">
            <x-ui.card :padding="false">
                <div class="p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">New Total</p>
                    <p class="mt-1 whitespace-nowrap text-4xl font-extrabold tracking-tight tabular-nums text-ink">Rs {{ number_format($this->total(), 2) }}</p>
                    <div class="mt-3 space-y-1 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-ink-faint">Subtotal</span>
                            <span class="font-medium tabular-nums text-ink">Rs {{ number_format($this->subtotal(), 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-ink-faint">Already Paid</span>
                            <span class="font-medium tabular-nums text-ink">Rs {{ number_format($this->alreadyPaid(), 2) }}</span>
                        </div>
                    </div>
                    @if ($this->total() > $this->alreadyPaid())
                        <div class="mt-3 flex items-center justify-between rounded-xl bg-warning-soft px-3 py-2 text-sm">
                            <span class="font-medium text-warning">Due After Save</span>
                            <span class="font-bold tabular-nums text-warning">Rs {{ number_format($this->total() - $this->alreadyPaid(), 2) }}</span>
                        </div>
                    @endif
                </div>

                <div class="space-y-4 border-t border-line p-5">
                    <x-ui.field label="Sale Date" name="saleDate" for="saleDate">
                        <x-ui.input wire:model="saleDate" id="saleDate" type="date" max="{{ now()->toDateString() }}" />
                    </x-ui.field>

                    <x-ui.field label="Invoice Discount" name="invoiceDiscount" for="invoiceDiscount">
                        <x-ui.input wire:model.live.debounce.300ms="invoiceDiscount" id="invoiceDiscount" type="number" min="0" step="0.01" />
                    </x-ui.field>

                    <x-ui.field label="Customer" name="customerId" for="customerId" :help="$this->alreadyPaid() < $this->total() ? 'Required — this sale still has a due balance' : 'Optional — leave blank for a walk-in sale'">
                        <x-ui.select wire:model.live="customerId" id="customerId">
                            <option value="">Walk-in (no customer)</option>
                            <option value="__create__">+ New Customer</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>

                <div class="border-t border-line p-5">
                    <x-ui.button
                        type="button"
                        wire:click="saveChanges"
                        wire:loading.attr="disabled"
                        wire:target="saveChanges"
                        size="lg"
                        class="w-full justify-center py-3"
                    >
                        <span wire:loading.remove wire:target="saveChanges">Save Changes</span>
                        <span wire:loading wire:target="saveChanges">Saving…</span>
                    </x-ui.button>
                </div>
            </x-ui.card>
        </div>
    </div>

    <x-sales.quantity-prompt :product="$promptProduct" :limit="$promptLimit" />

    <livewire:customers.quick-create />
</div>
