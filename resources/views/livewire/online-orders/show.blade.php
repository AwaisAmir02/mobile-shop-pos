<?php

use App\Actions\CreateCustomer;
use App\Actions\StoreOrders;
use App\Enums\FulfillmentStatus;
use App\Enums\StoreOrderStatus;
use App\Livewire\Concerns\PicksPaymentMethod;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Online Store Order')] class extends Component
{
    use PicksPaymentMethod, Toasts;

    public StoreOrder $order;

    public string $customerId = '';

    public string $amountPaid = '0';

    public string $rejectReason = '';

    /** @var array<int, int> line ids the shop isn't selling */
    public array $removed = [];

    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('online-orders'), 403);
    }

    public function mount(StoreOrder $order): void
    {
        $this->order = $order->load(['items.product', 'connection', 'customer', 'sale', 'decidedBy']);
        $this->customerId = (string) ($order->customer_id ?? $this->customerMatchingPhone() ?? '');

        if ($order->isDecidable() && $order->isPrepaid()) {
            $this->paymentMethod = (string) ($order->connection?->shop_account_id ?? '');
        }

        $this->amountPaid = $this->suggestedPaid();
    }

    /** Only when exactly one has this number: two customers sharing it would be a guess. */
    protected function customerMatchingPhone(): ?int
    {
        if (blank($this->order->customer_phone)) {
            return null;
        }

        $matches = Customer::where('phone', $this->order->customer_phone)->limit(2)->pluck('id');

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** Prepaid money counts up to the goods total: delivery isn't part of the sale in this version. */
    protected function suggestedPaid(): string
    {
        return $this->order->isPrepaid()
            ? number_format(min((float) $this->order->amount_paid, $this->total()), 2, '.', '')
            : '0';
    }

    protected function authorizeDecision(): void
    {
        abort_unless(Auth::user()->hasAccessTo('online-orders-decide'), 403);
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

    public function createCustomerFromOrder(): void
    {
        $this->authorizeDecision();

        $customer = CreateCustomer::handle(
            $this->order->customer_name ?: ($this->order->customer_phone ?: 'Online customer'),
            $this->order->customer_phone,
            filled($this->order->delivery_address) ? mb_substr(collect([$this->order->delivery_address, $this->order->delivery_area])->filter()->implode(', '), 0, 255) : null,
        );

        $this->customerId = (string) $customer->id;
        $this->toastSuccess("Customer {$customer->name} created.");
    }

    public function toggleLine(int $itemId): void
    {
        $this->authorizeDecision();

        $this->removed = in_array($itemId, $this->removed, true)
            ? array_values(array_diff($this->removed, [$itemId]))
            : [...$this->removed, $itemId];

        $this->amountPaid = $this->suggestedPaid();
    }

    public function mapLine(int $itemId, string $productId): void
    {
        $this->authorizeDecision();

        if (! ctype_digit($productId)) {
            return;
        }

        $item = StoreOrderItem::where('store_order_id', $this->order->id)->findOrFail($itemId);

        StoreOrders::mapItem($item, Product::findOrFail((int) $productId));
        $this->order->load('items.product');
        $this->resetErrorBag();
    }

    public function total(): float
    {
        return round(max(0.0, $this->subtotal() - (float) $this->order->discount_amount), 2);
    }

    public function subtotal(): float
    {
        return round($this->order->items
            ->reject(fn (StoreOrderItem $item) => in_array($item->id, $this->removed, true))
            ->sum(fn (StoreOrderItem $item) => Quantity::money($item->unit_price, $item->quantity)), 2);
    }

    public function accept(): void
    {
        $this->authorizeDecision();

        $this->validate([
            'amountPaid' => ['required', 'numeric', 'min:0'],
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)],
        ]);

        [$paymentMethod, $shopAccountId] = $this->chosenPaymentMethod();

        try {
            $sale = StoreOrders::accept(
                order: $this->order,
                removedItemIds: $this->removed,
                invoiceDiscount: (float) $this->order->discount_amount,
                customerId: $this->customerId !== '' ? (int) $this->customerId : null,
                amountPaid: (float) $this->amountPaid,
                userId: Auth::id(),
                paymentMethod: $paymentMethod,
                shopAccountId: $shopAccountId,
            );
        } catch (ValidationException $e) {
            // CreateSale names the product's line; this screen shows them together.
            throw ValidationException::withMessages(collect($e->errors())
                ->mapWithKeys(fn (array $messages, string $key) => [str_starts_with($key, 'lines.') ? 'lines' : $key => $messages])
                ->all());
        }

        $this->toastSuccess("Order accepted, sale {$sale->invoiceNumber()} created.");
        $this->redirectRoute('sales.show', $sale, navigate: true);
    }

    public function reject(): void
    {
        $this->authorizeDecision();
        $this->validate(['rejectReason' => ['required', 'string', 'max:255']]);

        StoreOrders::reject($this->order, $this->rejectReason, Auth::id());

        $this->toastSuccess('Order rejected. The store will need to refund the customer if they paid.');
        $this->redirectRoute('online-orders.index', navigate: true);
    }

    public function setFulfillment(string $status): void
    {
        $this->authorizeDecision();

        StoreOrders::updateFulfillment($this->order, FulfillmentStatus::from($status));
        $this->order->refresh();
        $this->toastSuccess('Marked as '.strtolower(FulfillmentStatus::from($status)->label()).'.');
    }

    public function clearCancelRequest(): void
    {
        $this->authorizeDecision();

        StoreOrders::clearCancelFlag($this->order);
        $this->order->refresh();
        $this->toastSuccess('Cancel request cleared.');
    }

    public function with(): array
    {
        $decidable = $this->order->isDecidable();

        return [
            'canDecide' => Auth::user()->hasAccessTo('online-orders-decide'),
            'customers' => $decidable ? Customer::query()->orderBy('name')->get() : collect(),
            'products' => $decidable && $this->order->items->contains('product_id', null) ? Product::publishableOnline()->orderBy('name')->limit(1000)->get(['id', 'name']) : collect(),
            'canViewSale' => Auth::user()->hasAccessTo('sales'),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('online-orders.index') }}" back-label="Online Store Orders">Order {{ $order->orderNumber() }}</x-ui.page-header>
    </x-slot>

    @if ($order->cancel_flagged_at)
        <x-ui.alert variant="warning" class="mb-4">
            <p class="font-semibold">The store asked to cancel this order{{ $order->cancel_reason ? ': '.$order->cancel_reason : '.' }}</p>
            <p class="mt-1 text-sm">The sale wasn't changed. If it should be cancelled, cancel it from the sale, which returns the stock to its batches.</p>
            @if ($canDecide)
                <div class="mt-3"><x-ui.button size="sm" variant="secondary" wire:click="clearCancelRequest">I've dealt with this</x-ui.button></div>
            @endif
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Items" :description="$order->isDecidable() ? 'Prices are what the store charged. Stock is only taken when you accept.' : null" :padding="false">
                <div class="divide-y divide-line">
                    @foreach ($order->items as $item)
                        @php($isRemoved = $item->removed || in_array($item->id, $removed, true))
                        <div wire:key="item-{{ $item->id }}" @class(['flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center', 'opacity-50' => $isRemoved])>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-ink">{{ $item->product?->name ?? $item->product_name }}</p>
                                <p class="text-xs text-ink-faint">
                                    {{ $item->quantity }} × Rs {{ number_format((float) $item->unit_price, 2) }}
                                    @if ($item->external_sku) · {{ $item->external_sku }} @endif
                                    @if ($item->product)
                                        · <span @class(['text-danger' => $order->isDecidable() && Quantity::compare($item->product->stock_quantity, $item->quantity) < 0])>{{ $item->product->stockLabel() }} in stock</span>
                                    @elseif (! $item->removed)
                                        · <span class="text-danger">not matched to a product</span>
                                    @endif
                                </p>
                            </div>

                            @if ($order->isDecidable() && $canDecide)
                                <div class="flex items-center gap-2">
                                    @if ($item->product_id === null && ! $isRemoved)
                                        <div class="w-56">
                                            <x-ui.select wire:change="mapLine({{ $item->id }}, $event.target.value)" aria-label="Product for {{ $item->product_name }}">
                                                <option value="">Choose a product</option>
                                                @foreach ($products as $product)
                                                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                                                @endforeach
                                            </x-ui.select>
                                        </div>
                                    @endif
                                    <x-ui.button size="sm" variant="ghost" wire:click="toggleLine({{ $item->id }})">{{ $isRemoved ? 'Restore' : 'Remove' }}</x-ui.button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <x-ui.error :messages="$errors->get('lines')" class="px-5 pb-4" />
            </x-ui.card>

            @if ($order->isDecidable() && $canDecide)
                <x-ui.card title="Reject order">
                    <form wire:submit="reject" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <x-ui.field label="Reason (sent to the store)" name="rejectReason" for="rejectReason" class="flex-1">
                            <x-ui.input wire:model="rejectReason" id="rejectReason" placeholder="e.g. Out of stock" />
                        </x-ui.field>
                        <x-ui.button type="submit" variant="danger" confirm="Reject this order?">Reject</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-6">
            <x-ui.card title="Customer">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Store</dt><dd class="text-right text-ink">{{ $order->connection?->name }}, #{{ $order->external_order_id }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Name</dt><dd class="text-right text-ink">{{ $order->customer_name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Phone</dt><dd class="text-right text-ink">{{ $order->customer_phone ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Deliver to</dt><dd class="text-right text-ink">{{ collect([$order->delivery_address, $order->delivery_area])->filter()->implode(', ') ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Payment</dt><dd class="text-right text-ink">{{ $order->isPrepaid() ? 'Prepaid, Rs '.number_format((float) $order->amount_paid, 2) : 'Cash on delivery' }}</dd></div>
                    @if ((float) $order->shipping_amount > 0)
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">Delivery charge</dt><dd class="text-right text-ink">Rs {{ number_format((float) $order->shipping_amount, 2) }}</dd></div>
                    @endif
                    @if ($order->note)
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">Note</dt><dd class="text-right text-ink">{{ $order->note }}</dd></div>
                    @endif
                </dl>
            </x-ui.card>

            @if ($order->isDecidable() && $canDecide)
                <x-ui.card title="Accept as sale">
                    <form wire:submit="accept" class="space-y-4">
                        <x-ui.field label="Customer" name="customerId" for="customerId" :help="(float) $amountPaid < $this->total() ? 'Required, this order isn\'t fully paid' : 'Optional when fully paid'">
                            <x-ui.select wire:model.live="customerId" id="customerId">
                                <option value="">Walk-in (no customer)</option>
                                <option value="__create__">+ Create new customer</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->phone ? ' · '.$customer->phone : '' }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        @if ($customerId === '')
                            <x-ui.button size="sm" variant="secondary" wire:click="createCustomerFromOrder" class="w-full">Save order's customer</x-ui.button>
                        @endif

                        <x-ui.field label="Amount paid now" name="amountPaid" for="amountPaid" help="Anything unpaid shows as this customer's sale balance.">
                            <x-ui.input wire:model.live.debounce.400ms="amountPaid" id="amountPaid" type="number" step="0.01" min="0" />
                        </x-ui.field>

                        <x-sales.payment-method-picker :accounts="$this->paymentAccounts()" :value="$paymentMethod" amount="amountPaid" />

                        @if ((float) $order->discount_amount > 0)
                            <div class="flex items-center justify-between text-sm text-ink-faint"><span>Store discount</span><span>Rs {{ number_format((float) $order->discount_amount, 2) }}</span></div>
                        @endif

                        <div class="flex items-center justify-between border-t border-line pt-4">
                            <span class="text-sm text-ink-faint">Sale total</span>
                            <span class="text-lg font-semibold text-ink">Rs {{ number_format($this->total(), 2) }}</span>
                        </div>

                        @if ((float) $order->shipping_amount > 0 || ($order->isPrepaid() && (float) $order->amount_paid > $this->total()))
                            <p class="text-xs text-ink-faint">The sale records goods only. The delivery charge, and any prepaid money above the goods total, isn't part of it.</p>
                        @endif

                        <x-ui.error :messages="$errors->get('order')" />

                        <x-ui.button type="submit" class="w-full" wire:loading.attr="disabled">Accept &amp; create sale</x-ui.button>
                    </form>
                </x-ui.card>
            @else
                <x-ui.card title="Decision">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">Status</dt><dd><x-ui.badge :variant="match ($order->status) { StoreOrderStatus::Pending => 'warning', StoreOrderStatus::Accepted => 'success', default => 'danger' }">{{ $order->status->label() }}</x-ui.badge></dd></div>
                        @if ($order->decided_at && $order->decidedBy)
                            <div class="flex justify-between gap-3"><dt class="text-ink-faint">By</dt><dd class="text-ink">{{ $order->decidedBy->name }}, {{ $order->decided_at->format('d M Y, h:i A') }}</dd></div>
                        @endif
                        @if ($order->reject_reason)
                            <div class="flex justify-between gap-3"><dt class="text-ink-faint">Reason</dt><dd class="text-right text-ink">{{ $order->reject_reason }}</dd></div>
                        @endif
                        @if ($order->sale_id && $canViewSale)
                            <div class="flex justify-between gap-3"><dt class="text-ink-faint">Sale</dt><dd><a href="{{ route('sales.show', $order->sale_id) }}" wire:navigate class="font-medium text-link hover:underline">View sale →</a></dd></div>
                        @endif
                        @if ($order->status === StoreOrderStatus::Accepted)
                            <div class="flex justify-between gap-3"><dt class="text-ink-faint">Fulfilment</dt><dd class="text-ink">{{ $order->fulfillment_status?->label() ?? 'Not started' }}</dd></div>
                            @if ($canDecide && $order->fulfillment_status !== FulfillmentStatus::Delivered)
                                <div class="flex flex-wrap gap-2 pt-2">
                                    @foreach (FulfillmentStatus::cases() as $next)
                                        @if ($order->fulfillment_status === null || $next->step() > $order->fulfillment_status->step())
                                            <x-ui.button size="sm" variant="secondary" wire:click="setFulfillment('{{ $next->value }}')">{{ $next->label() }}</x-ui.button>
                                        @endif
                                    @endforeach
                                </div>
                                <x-ui.error :messages="$errors->get('fulfillment')" />
                            @endif
                        @endif
                    </dl>
                </x-ui.card>
            @endif
        </div>
    </div>

    @if ($order->isDecidable() && $canDecide)
        <livewire:customers.quick-create />
        <livewire:shop-accounts.quick-create />
    @endif
</div>
