<?php

use App\Actions\UpdateSale;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
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
    use Toasts;

    const MAX_LINE_PRICE = 9999999.99;

    public Sale $sale;

    public string $search = '';
    public array $cart = [];
    public array $legacyItems = [];
    public string $invoiceDiscount = '0';
    public string $customerId = '';

    public function mount(Sale $sale): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales-edit-cancel'), 403);
        abort_if($sale->isCancelled(), 403, 'A cancelled sale cannot be edited.');

        $sale->loadMissing(['items', 'payments']);

        $this->sale = $sale;
        $this->invoiceDiscount = (string) $sale->discount_amount;
        $this->customerId = $sale->customer_id !== null ? (string) $sale->customer_id : '';

        foreach ($sale->items as $item) {
            $product = $item->product_id !== null ? Product::find($item->product_id) : null;

            if ($product === null) {
                $this->legacyItems[] = [
                    'product_name' => $item->product_name,
                    'product_type' => $item->product_type,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount_amount' => (float) $item->discount_amount,
                    'line_total' => (float) $item->line_total,
                ];

                continue;
            }

            $listPrice = (float) $product->price;
            $wasOverridden = $item->list_price !== null && (float) $item->list_price !== (float) $item->unit_price;

            $this->cart[$product->id] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'category' => $product->typeLabel(),
                'type' => $product->type,
                'list_price' => (string) $listPrice,
                'cost_price' => $product->cost_price,
                'custom_price' => $wasOverridden ? (string) $item->unit_price : '',
                'quantity' => $item->quantity,
                'discount' => (string) $item->discount_amount,
                'max_stock' => $product->stock_quantity + $item->quantity,
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
        ];
    }

    public function addToCart(int $productId): void
    {
        $product = Product::findOrFail($productId);

        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['quantity'] = $this->cart[$productId]['quantity'] + 1;
        } else {
            $this->cart[$productId] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'category' => $product->typeLabel(),
                'type' => $product->type,
                'list_price' => (string) $product->price,
                'cost_price' => $product->cost_price,
                'custom_price' => '',
                'quantity' => 1,
                'discount' => '0',
                'max_stock' => $product->stock_quantity,
            ];
        }

        $this->search = '';
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
        return SaleCartMath::lineTotal([
            'unit_price' => $this->effectivePrice($item),
            'quantity' => $item['quantity'],
            'discount' => $item['discount'],
        ]);
    }

    protected function normalizedCart(): array
    {
        return collect($this->cart)->map(fn (array $item) => [
            'unit_price' => $this->effectivePrice($item),
            'quantity' => $item['quantity'],
            'discount' => $item['discount'],
        ])->all();
    }

    public function legacySubtotal(): float
    {
        return collect($this->legacyItems)->sum('line_total');
    }

    public function subtotal(): float
    {
        return SaleCartMath::subtotal($this->normalizedCart()) + $this->legacySubtotal();
    }

    public function total(): float
    {
        return max(0.0, $this->subtotal() - min((float) $this->invoiceDiscount, $this->subtotal()));
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
            'invoiceDiscount' => ['required', 'numeric', 'min:0'],
            'customerId' => [
                $this->alreadyPaid() >= $total ? 'nullable' : 'required',
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

        $lines = collect($this->cart)->map(fn (array $item) => [
            'product_id' => $item['product_id'],
            'product_name' => $item['name'],
            'product_type' => $item['type'],
            'unit_price' => $this->effectivePrice($item),
            'list_price' => (float) $item['list_price'],
            'quantity' => (int) $item['quantity'],
            'discount' => (float) $item['discount'],
        ])->values()->all();

        foreach ($this->legacyItems as $legacy) {
            $lines[] = [
                'product_id' => null,
                'product_name' => $legacy['product_name'],
                'product_type' => $legacy['product_type'],
                'unit_price' => $legacy['unit_price'],
                'list_price' => $legacy['unit_price'],
                'quantity' => $legacy['quantity'],
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
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Edit {{ $sale->invoiceNumber() }}</h1>
            <a href="{{ route('sales.show', $sale) }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                ← Back to Sale
            </a>
        </div>
    </x-slot>

    @if ($this->alreadyPaid() > 0)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Rs {{ number_format($this->alreadyPaid(), 2) }} has already been paid against this sale. The new total cannot be less than that.
        </div>
    @endif

    @foreach (['total', 'lines', 'customerId'] as $field)
        @error($field)
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
        @enderror
    @endforeach

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.card :padding="false">
                <div class="p-5">
                    <x-ui.input
                        wire:model.live.debounce.250ms="search"
                        type="search"
                        placeholder="Search by product name to add a line…"
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
                                    <span class="text-sm font-semibold text-slate-700">Rs {{ number_format($product->price, 2) }}</span>
                                </button>
                            @empty
                                <p class="px-4 py-3 text-sm text-slate-400">No in-stock products match "{{ $search }}".</p>
                            @endforelse
                        </div>
                    @endif
                </div>

                @if (empty($cart) && empty($legacyItems))
                    <div class="border-t border-slate-100">
                        <x-ui.empty-state title="No items" description="Search for a product above to add a line." />
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
                                @foreach ($legacyItems as $index => $item)
                                    <tr wire:key="legacy-{{ $index }}">
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <p class="text-sm font-medium text-slate-900">{{ $item['product_name'] }}</p>
                                            <p class="text-xs text-slate-400">Product no longer exists — read-only</p>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-slate-600">Rs {{ number_format($item['unit_price'], 2) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-slate-600">{{ $item['quantity'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-slate-600">—</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-slate-900">Rs {{ number_format($item['line_total'], 2) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">
                                            <button type="button" wire:click="removeLegacyItem({{ $index }})" class="text-slate-400 hover:text-red-600">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                                @foreach ($cart as $productId => $item)
                                    <tr wire:key="cart-{{ $productId }}">
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <p class="text-sm font-medium text-slate-900">{{ $item['name'] }}</p>
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

                    <x-ui.field label="Invoice Discount" name="invoiceDiscount" for="invoiceDiscount">
                        <x-ui.input wire:model.live="invoiceDiscount" id="invoiceDiscount" type="number" min="0" step="0.01" />
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

                    <div class="border-t border-slate-100 pt-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-slate-500">New Total</span>
                            <span class="text-display-sm text-slate-900">Rs {{ number_format($this->total(), 2) }}</span>
                        </div>
                        <div class="mt-1 flex items-center justify-between text-sm">
                            <span class="text-slate-500">Already Paid</span>
                            <span class="font-medium text-slate-900">Rs {{ number_format($this->alreadyPaid(), 2) }}</span>
                        </div>
                        @if ($this->total() > $this->alreadyPaid())
                            <div class="mt-1 flex items-center justify-between text-sm">
                                <span class="text-slate-500">Due After Save</span>
                                <span class="font-medium text-amber-600">Rs {{ number_format($this->total() - $this->alreadyPaid(), 2) }}</span>
                            </div>
                        @endif
                    </div>

                    <x-ui.button
                        type="button"
                        wire:click="saveChanges"
                        wire:loading.attr="disabled"
                        wire:target="saveChanges"
                        size="lg"
                        class="w-full justify-center sm:w-auto"
                    >
                        Save Changes
                    </x-ui.button>
                </div>
            </x-ui.card>
        </div>
    </div>

    <livewire:customers.quick-create />
</div>
