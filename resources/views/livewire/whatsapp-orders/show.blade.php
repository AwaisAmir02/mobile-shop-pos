<?php

use App\Actions\AcceptWhatsAppOrder;
use App\Actions\UpdateWhatsAppOrderFulfillment;
use App\Enums\FulfillmentStatus;
use App\Actions\CreateCustomer;
use App\Actions\RejectWhatsAppOrder;
use App\Enums\WhatsAppOrderStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Product;
use App\Models\WhatsAppOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('WhatsApp Order')] class extends Component
{
    use Toasts;

    const MAX_LINE_PRICE = 9999999.99;

    public WhatsAppOrder $order;

    public array $lines = [];
    public string $customerId = '';
    public string $invoiceDiscount = '0';
    public string $amountPaid = '';
    public string $rejectReason = '';

    public function mount(WhatsAppOrder $order): void
    {
        abort_unless(Auth::user()->shop?->whatsapp_enabled, 403);
        abort_if($order->status === WhatsAppOrderStatus::Collecting, 404);

        $this->order = $order->load(['items.product', 'contact.customer', 'customer', 'sale', 'decidedBy']);
        $this->customerId = (string) ($order->customer_id ?? $order->contact?->customer_id ?? '');

        foreach ($order->items as $item) {
            $this->lines[$item->id] = [
                'product_id' => $item->product_id,
                'name' => $item->product_name,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->quoted_unit_price,
                'quoted_price' => (float) $item->quoted_unit_price,
                'current_price' => $item->product?->currentSellingPrice(),
                'stock' => $item->product?->stock_quantity ?? 0,
                'removed' => false,
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

    public function createCustomerFromContact(): void
    {
        $this->authorizeDecision();

        $contact = $this->order->contact;
        $customer = CreateCustomer::handle($contact->profile_name ?: '+'.$contact->wa_id, '+'.$contact->wa_id, $this->order->delivery_address !== 'Pickup from shop' ? $this->order->delivery_address : null);

        $this->customerId = (string) $customer->id;
        $this->toastSuccess("Customer {$customer->name} created.");
    }

    public function toggleLine(int $itemId): void
    {
        if (isset($this->lines[$itemId])) {
            $this->lines[$itemId]['removed'] = ! $this->lines[$itemId]['removed'];
        }
    }

    public function activeLines(): array
    {
        return array_filter($this->lines, fn (array $line) => ! $line['removed'] && $line['product_id'] !== null);
    }

    public function subtotal(): float
    {
        return array_sum(array_map(
            fn (array $line) => max(0, (float) $line['unit_price']) * max(0, (int) $line['quantity']),
            $this->activeLines()
        ));
    }

    public function total(): float
    {
        $subtotal = $this->subtotal();

        return max(0.0, $subtotal - min(max(0, (float) $this->invoiceDiscount), $subtotal));
    }

    protected function authorizeDecision(): void
    {
        abort_unless(Auth::user()->hasAccessTo('whatsapp-orders-decide'), 403);
    }

    public function accept(): void
    {
        $this->authorizeDecision();

        $rules = [
            'invoiceDiscount' => ['required', 'numeric', 'min:0'],
            'amountPaid' => ['nullable', 'numeric', 'min:0'],
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)],
        ];

        foreach ($this->activeLines() as $id => $line) {
            $rules["lines.$id.quantity"] = ['required', 'integer', 'min:1'];
            $rules["lines.$id.unit_price"] = ['required', 'numeric', 'min:0', 'max:'.self::MAX_LINE_PRICE];
        }

        $this->validate($rules);

        $lines = [];
        $itemIdForProduct = [];

        foreach ($this->activeLines() as $id => $line) {
            $lines[] = [
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) $line['quantity'],
                'discount' => 0.0,
                'custom_price' => (float) $line['unit_price'],
            ];
            $itemIdForProduct[(int) $line['product_id']] = $id;
        }

        $total = $this->total();

        try {
            $sale = AcceptWhatsAppOrder::handle(
                order: $this->order,
                lines: $lines,
                invoiceDiscount: (float) $this->invoiceDiscount,
                customerId: $this->customerId !== '' ? (int) $this->customerId : null,
                amountPaid: $this->amountPaid !== '' ? (float) $this->amountPaid : $total,
                userId: Auth::id(),
            );
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors() as $key => $messages) {
                if (preg_match('/^lines\.(\d+)$/', $key, $m) && isset($itemIdForProduct[(int) $m[1]])) {
                    $key = 'lines.'.$itemIdForProduct[(int) $m[1]].'.quantity';
                }

                $errors[$key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        $this->toastSuccess("Order accepted — sale {$sale->invoiceNumber()} created. The invoice is being sent on WhatsApp.");
        $this->redirectRoute('sales.show', $sale, navigate: true);
    }

    public function reject(): void
    {
        $this->authorizeDecision();

        $this->validate(['rejectReason' => ['required', 'string', 'max:255']]);

        RejectWhatsAppOrder::handle($this->order, $this->rejectReason, Auth::id());

        $this->toastSuccess('Order rejected. The customer is being notified on WhatsApp.');
        $this->redirectRoute('whatsapp-orders.index', navigate: true);
    }

    public function setFulfillment(string $status): void
    {
        $this->authorizeDecision();

        $notification = UpdateWhatsAppOrderFulfillment::handle($this->order, FulfillmentStatus::from($status), Auth::id());
        $this->order->refresh();

        if ($notification !== null && ! $notification->wasSent()) {
            $this->toastError('Status updated, but the customer was not notified: '.$notification->error);

            return;
        }

        $this->toastSuccess('Marked as '.strtolower(FulfillmentStatus::from($status)->label()).' — customer notified on WhatsApp.');
    }

    public function with(): array
    {
        return [
            'customers' => $this->order->isDecidable() ? Customer::query()->orderBy('name')->get() : collect(),
            'canDecide' => Auth::user()->hasAccessTo('whatsapp-orders-decide'),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Order {{ $order->orderNumber() }}</h1>
            <a href="{{ route('whatsapp-orders.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                ← All WhatsApp orders
            </a>
        </div>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Items" :description="$order->isDecidable() ? 'Adjust quantities or prices before accepting. Stock is only taken when you accept.' : null" :padding="false">
                <div class="divide-y divide-slate-100">
                    @foreach ($lines as $id => $line)
                        <div wire:key="line-{{ $id }}" @class(['flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center', 'opacity-50' => $line['removed']])>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-slate-900">{{ $line['name'] }}</p>
                                <p class="text-xs text-slate-500">
                                    Quoted Rs {{ number_format($line['quoted_price'], 2) }}
                                    @if ($line['product_id'] === null)
                                        · <span class="text-red-600">product deleted</span>
                                    @else
                                        @if ($line['current_price'] !== null && abs($line['current_price'] - $line['quoted_price']) > 0.009)
                                            · <span class="text-amber-700">now Rs {{ number_format($line['current_price'], 2) }}</span>
                                        @endif
                                        · <span @class(['text-red-600' => $line['stock'] < (int) $line['quantity']])>{{ $line['stock'] }} in stock</span>
                                    @endif
                                </p>
                            </div>

                            @if ($order->isDecidable() && $canDecide)
                                <div class="flex items-start gap-2">
                                    <x-ui.field name="lines.{{ $id }}.quantity" class="w-20">
                                        <x-ui.input wire:model.live.debounce.400ms="lines.{{ $id }}.quantity" type="number" min="1" aria-label="Quantity" :disabled="$line['removed']" />
                                    </x-ui.field>
                                    <x-ui.field name="lines.{{ $id }}.unit_price" class="w-32">
                                        <x-ui.input wire:model.live.debounce.400ms="lines.{{ $id }}.unit_price" type="number" step="0.01" min="0" aria-label="Unit price" :disabled="$line['removed']" />
                                    </x-ui.field>
                                    <x-ui.button size="sm" variant="ghost" wire:click="toggleLine({{ $id }})">
                                        {{ $line['removed'] ? 'Restore' : 'Remove' }}
                                    </x-ui.button>
                                </div>
                            @else
                                <p class="text-sm text-slate-700">{{ $line['quantity'] }} × Rs {{ number_format((float) $line['unit_price'], 2) }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
                <x-ui.error :messages="$errors->get('lines')" class="px-5 pb-4" />
            </x-ui.card>

            @if ($order->isDecidable() && $canDecide)
                <x-ui.card title="Reject order">
                    <form wire:submit="reject" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <x-ui.field label="Reason (sent to the customer)" name="rejectReason" for="rejectReason" class="flex-1">
                            <x-ui.input wire:model="rejectReason" id="rejectReason" placeholder="e.g. Out of stock in this colour" />
                        </x-ui.field>
                        <x-ui.button type="submit" variant="danger" wire:confirm="Reject this order and notify the customer?">Reject</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-6">
            <x-ui.card title="Customer">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">WhatsApp</dt><dd class="font-medium text-slate-900">+{{ $order->contact?->wa_id }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Name</dt><dd class="text-slate-900">{{ $order->contact?->profile_name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Delivery</dt><dd class="text-right text-slate-900">{{ $order->delivery_address ?? '—' }}</dd></div>
                    @if ($order->origin_ref || $order->referral)
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Came from</dt><dd class="text-slate-900">{{ $order->origin_ref ? 'Post REF-'.$order->origin_ref : 'Click-to-WhatsApp ad' }}</dd></div>
                    @endif
                </dl>
            </x-ui.card>

            @if ($order->isDecidable() && $canDecide)
                <x-ui.card title="Accept as sale">
                    <form wire:submit="accept" class="space-y-4">
                        <x-ui.field label="Customer" name="customerId" for="customerId" :help="$amountPaid !== '' && (float) $amountPaid < $this->total() ? 'Required — this order is not being paid in full' : 'Optional when fully paid'">
                            <x-ui.select wire:model.live="customerId" id="customerId">
                                <option value="">Walk-in (no customer)</option>
                                <option value="__create__">+ Create new customer</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->phone ? ' · '.$customer->phone : '' }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        @if ($customerId === '')
                            <x-ui.button size="sm" variant="secondary" wire:click="createCustomerFromContact" class="w-full">
                                Save WhatsApp contact as customer
                            </x-ui.button>
                        @endif

                        <x-ui.field label="Invoice discount" name="invoiceDiscount" for="invoiceDiscount">
                            <x-ui.input wire:model.live.debounce.400ms="invoiceDiscount" id="invoiceDiscount" type="number" step="0.01" min="0" />
                        </x-ui.field>

                        <x-ui.field label="Amount paid now" name="amountPaid" for="amountPaid" help="Leave blank if paid in full. Anything unpaid shows as this customer's sale balance.">
                            <x-ui.input wire:model.live.debounce.400ms="amountPaid" id="amountPaid" type="number" step="0.01" min="0" :placeholder="number_format($this->total(), 2, '.', '')" />
                        </x-ui.field>

                        <div class="flex items-center justify-between border-t border-slate-100 pt-4">
                            <span class="text-sm text-slate-500">Total</span>
                            <span class="text-lg font-semibold text-slate-900">Rs {{ number_format($this->total(), 2) }}</span>
                        </div>

                        <x-ui.error :messages="$errors->get('order')" />

                        <x-ui.button type="submit" class="w-full" wire:loading.attr="disabled">Accept &amp; create sale</x-ui.button>
                    </form>
                </x-ui.card>
            @else
                <x-ui.card title="Decision">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Status</dt><dd><x-ui.badge :variant="$order->status === WhatsAppOrderStatus::Accepted ? 'success' : ($order->isDecidable() ? 'warning' : 'danger')">{{ $order->status->label() }}</x-ui.badge></dd></div>
                        @if ($order->decided_at)
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">By</dt><dd class="text-slate-900">{{ $order->decidedBy?->name ?? '—' }}, {{ $order->decided_at->format('d M Y, h:i A') }}</dd></div>
                        @endif
                        @if ($order->reject_reason)
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Reason</dt><dd class="text-right text-slate-900">{{ $order->reject_reason }}</dd></div>
                        @endif
                        @if ($order->sale_id)
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Sale</dt><dd><a href="{{ route('sales.show', $order->sale_id) }}" wire:navigate class="font-medium text-brand-700 hover:text-brand-800">View sale →</a></dd></div>
                        @endif
                        @if ($order->status === WhatsAppOrderStatus::Accepted)
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Fulfilment</dt><dd class="text-slate-900">{{ $order->fulfillment_status?->label() ?? 'Not started' }}</dd></div>
                            @if ($canDecide && $order->fulfillment_status !== FulfillmentStatus::Delivered)
                                <div class="flex flex-wrap gap-2 pt-2">
                                    @foreach (FulfillmentStatus::cases() as $next)
                                        @if ($order->fulfillment_status === null || $next->step() > $order->fulfillment_status->step())
                                            <x-ui.button size="sm" variant="secondary" wire:click="setFulfillment('{{ $next->value }}')" wire:confirm="Mark as {{ strtolower($next->label()) }} and message the customer?">{{ $next->label() }}</x-ui.button>
                                        @endif
                                    @endforeach
                                </div>
                                <x-ui.error :messages="$errors->get('fulfillment')" />
                            @endif
                        @endif
                        @if ($order->notify_error)
                            <p class="rounded-lg bg-red-50 p-3 text-xs text-red-700">Customer was not notified: {{ $order->notify_error }}</p>
                        @elseif ($order->customer_notified_at)
                            <p class="text-xs text-emerald-700">Customer notified on WhatsApp {{ $order->customer_notified_at->diffForHumans() }}.</p>
                        @endif
                    </dl>
                </x-ui.card>
            @endif
        </div>
    </div>

    @if ($order->isDecidable() && $canDecide)
        <livewire:customers.quick-create />
    @endif
</div>
