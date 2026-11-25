<?php

use App\Actions\SalesOrders;
use App\Actions\Schemes;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\BatchExpiry;
use App\Support\BranchContext;
use App\Support\CreditControl;
use App\Support\PriceTiers;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Book an order (W7), or change one still booked. Prices start at the
 * customer's own price level (PriceTiers) and can be changed; quantities are
 * checked against stock that isn't already held for other orders.
 */
new #[Layout('layouts.app')] #[Title('Book Order')] class extends Component
{
    use Toasts;

    public ?SalesOrder $order = null;

    public string $customerId = '';
    public string $bookerId = '';
    public string $deliveryDate = '';
    public string $note = '';
    public string $search = '';

    /** @var array<int, array{product_id: int, name: string, quantity: string, by_pack: bool, pack_name: ?string, pack_size: ?int, unit_price: string, price_tier: ?string, discount: string, unit: string}> */
    public array $lines = [];

    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(?SalesOrder $order = null): void
    {
        abort_unless(Auth::user()->shop?->usesTool('orders'), 404);
        $this->deliveryDate = now()->parse(BatchExpiry::today())->addDay()->toDateString();
        $this->bookerId = (string) Auth::id();

        if ($order?->exists) {
            abort_unless($order->isOpen(), 404);
            $this->order = $order;
            $this->customerId = (string) $order->customer_id;
            $this->bookerId = (string) ($order->booker_id ?? '');
            $this->deliveryDate = $order->delivery_date?->toDateString() ?? '';
            $this->note = (string) $order->note;

            foreach ($order->lines as $line) {
                $byPack = $line->pack_size > 1 && fmod((float) $line->quantity, $line->pack_size) == 0.0;
                $this->lines[] = [
                    'product_id' => $line->product_id,
                    'name' => $line->product_name,
                    'quantity' => (string) ($byPack ? (float) $line->quantity / $line->pack_size : $line->quantity),
                    'by_pack' => $byPack,
                    'pack_name' => $line->product?->pack_name,
                    'pack_size' => $line->product?->pack_size,
                    'unit_price' => (string) ($byPack ? round((float) $line->unit_price * $line->pack_size, 2) : $line->unit_price),
                    'price_tier' => $line->price_tier,
                    'discount' => (string) $line->discount,
                    'unit' => $line->product?->unit->value ?? 'piece',
                ];
            }
        } elseif (($customerId = request()->query('customer')) !== null) {
            $this->customerId = (string) $customerId;
            $this->bookerId = (string) (Customer::find($customerId)?->route?->booker_id ?? Auth::id());
        }
    }

    protected function customer(): ?Customer
    {
        return ctype_digit($this->customerId) ? Customer::find((int) $this->customerId) : null;
    }

    public function updatedCustomerId(): void
    {
        if (($route = $this->customer()?->route) && $route->booker_id) {
            $this->bookerId = (string) $route->booker_id;
        }

        foreach ($this->lines as $i => $line) {
            if (($product = Product::find($line['product_id'])) !== null) {
                [$price, $tier] = $this->priceFor($product, $line['by_pack']);
                $this->lines[$i]['unit_price'] = (string) $price;
                $this->lines[$i]['price_tier'] = $tier;
            }
        }
    }

    /** @return array{0: float, 1: ?string} the price for one piece (or one pack) and its level */
    protected function priceFor(Product $product, bool $byPack): array
    {
        $tier = PriceTiers::forCustomer($this->customer());
        $piece = PriceTiers::piecePrice($product, $tier);

        if ($byPack && $product->hasPack()) {
            $pack = PriceTiers::packPrice($product, $tier, (int) $product->pack_size, $product->packSellingPrice());

            return [$pack ?? $product->packSellingPrice(), $pack !== null ? $tier : null];
        }

        return [$piece ?? $product->currentSellingPrice(), $piece !== null ? $tier : null];
    }

    public function add(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $byPack = $product->hasPack();
        [$price, $tier] = $this->priceFor($product, $byPack);

        $this->lines[] = [
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => '1',
            'by_pack' => $byPack,
            'pack_name' => $product->pack_name,
            'pack_size' => $product->pack_size,
            'unit_price' => (string) $price,
            'price_tier' => $tier,
            'discount' => '0',
            'unit' => $product->unit->value,
        ];
        $this->search = '';
    }

    public function togglePack(int $i): void
    {
        $line = $this->lines[$i] ?? null;
        $product = $line ? Product::find($line['product_id']) : null;

        if ($product === null || ! $product->hasPack()) {
            return;
        }

        $byPack = ! $line['by_pack'];
        [$price, $tier] = $this->priceFor($product, $byPack);
        $this->lines[$i] = array_merge($line, ['by_pack' => $byPack, 'unit_price' => (string) $price, 'price_tier' => $tier]);
    }

    public function remove(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    /** The lines as SalesOrders::save takes them: quantity in pieces, price per piece. */
    protected function orderLines(): array
    {
        return array_map(function (array $line) {
            $size = $line['by_pack'] && $line['pack_size'] > 1 ? (int) $line['pack_size'] : 1;
            $quantity = is_numeric($line['quantity']) ? (float) $line['quantity'] * $size : 0;

            return [
                'product_id' => $line['product_id'],
                'quantity' => $quantity,
                'unit_price' => is_numeric($line['unit_price']) ? round((float) $line['unit_price'] / $size, 2) : 0,
                'price_tier' => $line['price_tier'],
                'discount' => is_numeric($line['discount']) ? (float) $line['discount'] : 0,
                'pack_name' => $size > 1 ? $line['pack_name'] : null,
                'pack_size' => $size > 1 ? $size : null,
            ];
        }, $this->lines);
    }

    public function save(): void
    {
        $this->validate([
            'customerId' => ['required', 'integer', \Illuminate\Validation\Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)],
            'bookerId' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('shop_id', Auth::user()->shop_id)],
            'deliveryDate' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ], [], ['customerId' => 'customer', 'bookerId' => 'booker', 'deliveryDate' => 'delivery date']);

        $order = SalesOrders::save($this->order, (int) $this->customerId, $this->orderLines(), $this->bookerId !== '' ? (int) $this->bookerId : null, $this->deliveryDate, $this->note, Auth::id());

        $this->toastSuccess($order->number().' booked.');
        $this->redirectRoute('sales-orders.show', $order, navigate: true);
    }

    public function with(): array
    {
        $customer = $this->customer();
        $total = 0.0;

        foreach ($this->orderLines() as $line) {
            $total += max(0.0, Quantity::money($line['unit_price'], $line['quantity']) - (float) $line['discount']);
        }

        $productIds = array_column($this->lines, 'product_id');
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $held = SalesOrders::reserved($productIds, $this->order?->id);
        $today = BatchExpiry::today();

        return [
            'customers' => Customer::query()->orderBy('name')->get(),
            'users' => User::query()->where('shop_id', Auth::user()->shop_id)->orderBy('name')->get(),
            'results' => $this->search !== '' ? Product::query()->whereNull('archived_at')->where('name', 'like', "%{$this->search}%")->orderBy('name')->limit(8)->get() : collect(),
            'free' => collect($productIds)->mapWithKeys(fn ($id) => [$id => isset($products[$id]) ? max(0, (float) $products[$id]->stock_quantity - (float) ($held[$id] ?? 0)) : 0])->all(),
            'schemes' => collect($productIds)->mapWithKeys(fn ($id) => [$id => Schemes::runningFor($id, $today)])->filter()->all(),
            'total' => round($total, 2),
            'credit' => $customer ? CreditControl::check($customer, $total) : null,
            'tierLabel' => PriceTiers::label(Auth::user()->shop),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('sales-orders.index') }}" back-label="Orders" :subtitle="$order ? 'Changing '.$order->number() : 'Stock is held for the order until it is delivered'">{{ $order ? 'Change Order' : 'Book Order' }}</x-ui.page-header>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Items">
                <x-ui.field label="Add a product" for="orderSearch">
                    <x-ui.input wire:model.live.debounce.300ms="search" id="orderSearch" type="search" placeholder="Search products…" />
                </x-ui.field>
                @if ($results->isNotEmpty())
                    <ul class="mt-2 divide-y divide-line rounded-xl border border-line">
                        @foreach ($results as $result)
                            <li><button type="button" wire:click="add({{ $result->id }})" class="flex w-full justify-between px-3 py-2 text-left text-sm hover:bg-surface-sunken"><span>{{ $result->name }}</span><span class="text-ink-faint">{{ $result->stockLabel() }}</span></button></li>
                        @endforeach
                    </ul>
                @endif

                @error('lines')<p class="mt-3 text-sm text-danger">{{ $message }}</p>@enderror

                @if ($lines !== [])
                    <ul class="mt-5 divide-y divide-line" data-order-lines>
                        @foreach ($lines as $i => $line)
                            @php($scheme = $schemes[$line['product_id']] ?? null)
                            <li wire:key="line-{{ $i }}-{{ $line['product_id'] }}" class="py-3">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-ink">{{ $line['name'] }}</p>
                                        <p class="text-xs text-ink-faint">
                                            {{ \App\Support\Quantity::grouped($free[$line['product_id']] ?? 0) }} free to book
                                            @if ($line['price_tier'])
                                                · {{ $tierLabel }}
                                            @endif
                                            @if ($scheme)
                                                · scheme {{ $scheme->offerLabel() }} (added on delivery)
                                            @endif
                                        </p>
                                        @if ($line['pack_size'] > 1 && $line['pack_name'])
                                            <button type="button" wire:click="togglePack({{ $i }})" class="mt-1 text-xs font-semibold text-link hover:underline">{{ $line['by_pack'] ? 'In '.mb_strtolower($line['pack_name']).'s of '.$line['pack_size'].' · switch to pieces' : 'In pieces · switch to '.mb_strtolower($line['pack_name']).'s' }}</button>
                                        @endif
                                        @error('lines.'.$i.'.quantity')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                                    </div>
                                    <div class="flex items-end gap-2">
                                        <label class="text-xs text-ink-faint">Qty
                                            <input type="number" min="0" step="any" wire:model.live.debounce.400ms="lines.{{ $i }}.quantity" class="mt-1 block w-20 rounded-lg border-line-strong bg-surface py-1.5 text-right text-sm text-ink" />
                                        </label>
                                        <label class="text-xs text-ink-faint">{{ $line['by_pack'] ? 'Price per '.mb_strtolower($line['pack_name']) : 'Price each' }}
                                            <input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="lines.{{ $i }}.unit_price" class="mt-1 block w-28 rounded-lg border-line-strong bg-surface py-1.5 text-right text-sm text-ink" />
                                        </label>
                                        <label class="text-xs text-ink-faint">Discount
                                            <input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="lines.{{ $i }}.discount" class="mt-1 block w-24 rounded-lg border-line-strong bg-surface py-1.5 text-right text-sm text-ink" />
                                        </label>
                                        <x-ui.button size="sm" variant="danger-outline" wire:click="remove({{ $i }})" aria-label="Remove {{ $line['name'] }}">✕</x-ui.button>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-5 text-sm text-ink-faint">No items yet.</p>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card title="Order">
            <div class="space-y-4">
                <x-ui.field label="Customer" name="customerId" for="orderCustomer">
                    <x-ui.select wire:model.live="customerId" id="orderCustomer">
                        <option value="">Choose…</option>
                        @foreach ($customers as $item)
                            <option value="{{ $item->id }}">{{ $item->displayName() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Booked by" name="bookerId" for="orderBooker">
                    <div wire:key="booker-{{ $bookerId }}">
                        <x-ui.select wire:model="bookerId" id="orderBooker">
                            <option value="">Nobody</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}">{{ $user->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </x-ui.field>
                <x-ui.field label="Deliver on" name="deliveryDate" for="orderDelivery">
                    <x-ui.input wire:model="deliveryDate" id="orderDelivery" type="date" />
                </x-ui.field>
                <x-ui.field label="Note" name="note" for="orderNote">
                    <x-ui.input wire:model="note" id="orderNote" maxlength="255" />
                </x-ui.field>
                <div class="rounded-xl bg-surface-sunken px-3 py-2 text-sm">
                    <div class="flex justify-between"><span class="text-ink-faint">Order value</span><span class="font-bold tabular-nums text-ink" data-order-total>Rs {{ number_format($total, 2) }}</span></div>
                    @if ($credit)
                        <p @class(['mt-1 text-xs', 'font-semibold text-danger' => $credit['over'], 'text-ink-faint' => ! $credit['over']])>
                            {{ $credit['over'] ? 'Over the credit limit: they would owe Rs '.number_format($credit['after'], 2).' against Rs '.number_format($credit['limit'], 2).' if none of it is paid on delivery.' : 'Rs '.number_format($credit['available'], 2).' of credit left.' }}
                        </p>
                    @endif
                </div>
                <x-ui.button type="button" wire:click="save" class="w-full justify-center" data-save-order>{{ $order ? 'Save Changes' : 'Book Order' }}</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
