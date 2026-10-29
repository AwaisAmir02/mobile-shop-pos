<?php

use App\Actions\ConsumeStockFifo;
use App\Actions\CreateSale;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Product;
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
    use Toasts;

    const MAX_LINE_PRICE = 9999999.99;

    public string $search = '';
    public array $cart = [];
    public string $invoiceDiscount = '0';
    public string $customerId = '';
    public string $amountPaidNow = '';
    public string $saleDate = '';

    public function mount(): void
    {
        $this->saleDate = now()->toDateString();
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
            'barcodeEnabled' => Auth::user()->canUseBarcodes(),
        ];
    }

    public function addToCart(int $productId): void
    {
        $product = Product::findOrFail($productId);

        if ($product->stock_quantity < 1) {
            $this->toastError("{$product->name} is out of stock.");

            return;
        }

        if (isset($this->cart[$productId])) {
            $newQuantity = $this->cart[$productId]['quantity'] + 1;

            if ($newQuantity > $product->stock_quantity) {
                $this->toastError("Only {$product->stock_quantity} of {$product->name} in stock.");

                return;
            }

            $this->cart[$productId]['quantity'] = $newQuantity;
        } else {
            $this->cart[$productId] = $this->cartLineFor($product);
        }

        $this->search = '';
    }

    protected function cartLineFor(Product $product): array
    {
        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'category' => $product->typeLabel(),
            'list_price' => (string) $product->currentSellingPrice(),
            'cost_price' => Auth::user()->hasAccessTo('sales-view-cost-price') ? $product->currentCostPrice() : null,
            'custom_price' => '',
            'quantity' => 1,
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

        if ($product->stock_quantity < 1) {
            $this->toastError("{$product->name} is out of stock.");

            return;
        }

        $inCart = $this->cart[$product->id]['quantity'] ?? 0;

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

        $allocations = ConsumeStockFifo::preview($product, (int) $item['quantity']);
        $overridePrice = $item['custom_price'] !== '' && $item['custom_price'] !== null ? (float) $item['custom_price'] : null;
        $subLines = SaleCartMath::allocateLines($overridePrice, (float) $item['discount'], $allocations);

        return array_sum(array_column($subLines, 'line_total'));
    }

    public function subtotal(): float
    {
        return array_sum(array_map(fn (array $item) => $this->lineTotal($item), $this->cart));
    }

    public function total(): float
    {
        $subtotal = $this->subtotal();

        return max(0.0, $subtotal - min((float) $this->invoiceDiscount, $subtotal));
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
            $rules["cart.$id.quantity"] = ['required', 'integer', 'min:1'];
            $rules["cart.$id.discount"] = ['required', 'numeric', 'min:0'];
            $rules["cart.$id.custom_price"] = ['nullable', 'numeric', 'min:0', 'max:'.self::MAX_LINE_PRICE];
        }

        $this->validate($rules);

        $lines = [];

        foreach ($this->cart as $productId => $item) {
            $lines[] = [
                'product_id' => (int) $productId,
                'quantity' => (int) $item['quantity'],
                'discount' => (float) $item['discount'],
                'custom_price' => $item['custom_price'] !== '' && $item['custom_price'] !== null ? (float) $item['custom_price'] : null,
            ];
        }

        try {
            $sale = CreateSale::handle(
                lines: $lines,
                invoiceDiscount: (float) $this->invoiceDiscount,
                customerId: $this->customerId !== '' ? (int) $this->customerId : null,
                userId: Auth::id(),
                saleDate: $this->saleDate,
                amountPaid: $paidNow,
            );
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors() as $key => $messages) {
                $errors[preg_replace('/^lines\.(\d+)$/', 'cart.$1.quantity', $key)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        $this->toastSuccess('Sale completed.');
        $this->redirectRoute('sales.show', $sale, navigate: true);
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">New Sale</h1>
            <a href="{{ route('sales.history') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                Sales History →
            </a>
        </div>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.card :padding="false">
                <div class="p-5">
                    <x-ui.input
                        wire:model.live.debounce.250ms="search"
                        wire:keydown.enter.prevent="scanBarcode($event.target.value)"
                        x-ref="scanInput"
                        x-on:focus-scan.window="$refs.scanInput.focus()"
                        type="search"
                        placeholder="{{ $barcodeEnabled ? 'Search by name, or scan a barcode…' : 'Search by product name…' }}"
                        autofocus
                        autocomplete="off"
                        class="text-base"
                    />

                    @if ($search !== '')
                        <div class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200">
                            @forelse ($results as $product)
                                <button
                                    type="button"
                                    wire:click="addToCart({{ $product->id }})"
                                    wire:key="result-{{ $product->id }}"
                                    class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-slate-50"
                                >
                                    <div class="flex items-center gap-3">
                                        <x-ui.thumbnail :src="$product->imageUrl()" :label="$product->name" />
                                        <div>
                                            <p class="text-sm font-medium text-slate-900">{{ $product->name }}</p>
                                            <p class="text-xs text-slate-400">{{ $product->typeLabel() }} · {{ $product->stock_quantity }} in stock</p>
                                        </div>
                                    </div>
                                    <span class="text-sm font-semibold text-slate-700">Rs {{ number_format($product->currentSellingPrice(), 2) }}</span>
                                </button>
                            @empty
                                <p class="px-4 py-3 text-sm text-slate-400">No in-stock products match "{{ $search }}".</p>
                            @endforelse
                        </div>
                    @endif
                </div>

                @if (empty($cart))
                    <div class="border-t border-slate-100">
                        <x-ui.empty-state
                            title="Cart is empty"
                            description="Search for a product above to start a sale."
                        />
                    </div>
                @else
                    <div class="border-t border-slate-100 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="whitespace-nowrap px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Product</th>
                                    <th class="whitespace-nowrap px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Price</th>
                                    <th class="whitespace-nowrap px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Qty</th>
                                    <th class="whitespace-nowrap px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Discount</th>
                                    <th class="whitespace-nowrap px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Total</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($cart as $productId => $item)
                                    <tr wire:key="cart-{{ $productId }}">
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <div class="flex items-center gap-1.5">
                                                <p class="text-sm font-medium text-slate-900">{{ $item['name'] }}</p>
                                                @if ($item['cost_price'] !== null)
                                                    <x-ui.info-tooltip :text="'Cost: Rs '.number_format($item['cost_price'], 2)" />
                                                @endif
                                            </div>
                                            <p class="text-xs text-slate-400">{{ $item['category'] }}</p>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                placeholder="{{ number_format($item['list_price'], 2) }}"
                                                wire:model.live="cart.{{ $productId }}.custom_price"
                                                class="w-24 rounded-lg border-slate-300 py-1.5 text-right text-sm focus:border-brand-500 focus:ring-brand-500"
                                            />
                                            @if ($item['custom_price'] !== '' && (float) $item['custom_price'] !== (float) $item['list_price'])
                                                <p class="mt-1 text-xs font-medium text-accent-600">Price overridden</p>
                                                @if ($item['cost_price'] !== null && (float) $item['custom_price'] < (float) $item['cost_price'])
                                                    <p class="mt-0.5 text-xs text-amber-600">Below cost price</p>
                                                @endif
                                            @endif
                                            @error("cart.$productId.custom_price")
                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <input
                                                type="number"
                                                min="1"
                                                max="{{ $item['max_stock'] }}"
                                                wire:model.live="cart.{{ $productId }}.quantity"
                                                class="w-16 rounded-lg border-slate-300 py-1.5 text-right text-sm focus:border-brand-500 focus:ring-brand-500"
                                            />
                                            @error("cart.$productId.quantity")
                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                wire:model.live="cart.{{ $productId }}.discount"
                                                class="w-20 rounded-lg border-slate-300 py-1.5 text-right text-sm focus:border-brand-500 focus:ring-brand-500"
                                            />
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-slate-900">
                                            Rs {{ number_format($this->lineTotal($item), 2) }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">
                                            <button type="button" wire:click="removeFromCart({{ $productId }})" class="text-slate-400 hover:text-red-600">
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
                @endif
            </x-ui.card>
        </div>

        <div>
            <x-ui.card title="Summary">
                <div class="space-y-3">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-500">Subtotal</span>
                        <span class="font-medium text-slate-900">Rs {{ number_format($this->subtotal(), 2) }}</span>
                    </div>

                    <x-ui.field label="Sale Date" name="saleDate" for="saleDate">
                        <x-ui.input wire:model="saleDate" id="saleDate" type="date" max="{{ now()->toDateString() }}" />
                    </x-ui.field>

                    <x-ui.field label="Invoice Discount" name="invoiceDiscount" for="invoiceDiscount">
                        <x-ui.input wire:model.live="invoiceDiscount" id="invoiceDiscount" type="number" min="0" step="0.01" />
                    </x-ui.field>

                    <x-ui.field label="Amount Paid Now" name="amountPaidNow" for="amountPaidNow" help="Leave blank to record as paid in full">
                        <x-ui.input wire:model.live="amountPaidNow" id="amountPaidNow" type="number" min="0" step="0.01" :placeholder="number_format($this->total(), 2)" />
                    </x-ui.field>

                    <x-ui.field label="Customer" name="customerId" for="customerId" :help="$amountPaidNow !== '' && (float) $amountPaidNow < $this->total() ? 'Required — this sale is not being paid in full' : 'Optional — leave blank for a walk-in sale'">
                        <x-ui.select wire:model.live="customerId" id="customerId">
                            <option value="">Walk-in (no customer)</option>
                            <option value="__create__">+ New Customer</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <div class="border-t border-slate-100 pt-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-slate-500">Total</span>
                            <span class="text-display-sm text-slate-900">Rs {{ number_format($this->total(), 2) }}</span>
                        </div>
                        @if ($amountPaidNow !== '' && (float) $amountPaidNow < $this->total())
                            <div class="mt-1 flex items-center justify-between text-sm">
                                <span class="text-slate-500">Due After Sale</span>
                                <span class="font-medium text-amber-600">Rs {{ number_format($this->total() - (float) $amountPaidNow, 2) }}</span>
                            </div>
                        @endif
                    </div>

                    <x-ui.button
                        type="button"
                        wire:click="completeSale"
                        wire:loading.attr="disabled"
                        wire:target="completeSale"
                        size="lg"
                        class="w-full justify-center sm:w-auto"
                    >
                        Complete Sale
                    </x-ui.button>
                </div>
            </x-ui.card>
        </div>
    </div>

    <livewire:customers.quick-create />
</div>
