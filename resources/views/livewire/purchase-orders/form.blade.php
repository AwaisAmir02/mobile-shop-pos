<?php

use App\Actions\SavePurchaseOrder;
use App\Enums\QuantityUnit;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Purchase Order')] class extends Component
{
    public ?int $orderId = null;
    public string $vendorId = '';
    public string $branchId = '';
    public string $orderDate = '';
    public string $expectedDate = '';
    public string $note = '';

    /** @var array<int, array{product_id: string, quantity: string, expected_cost: string}> */
    public array $lines = [];
    public int $nextRow = 1;

    public function mount(?int $order = null): void
    {
        abort_unless(Auth::user()->shop?->purchase_orders_enabled, 403);

        $this->orderDate = now()->toDateString();
        $this->branchId = (string) BranchContext::current()->recordingBranchIdFor(Auth::user()->shop_id);

        if ($order === null) {
            $this->addRow();

            return;
        }

        $po = PurchaseOrder::inCurrentBranch()->with('lines')->findOrFail($order);
        abort_unless($po->isEditable(), 403);

        $this->orderId = $po->id;
        $this->vendorId = (string) $po->vendor_id;
        $this->branchId = (string) $po->branch_id;
        $this->orderDate = $po->order_date->toDateString();
        $this->expectedDate = $po->expected_date?->toDateString() ?? '';
        $this->note = (string) $po->note;

        foreach ($po->lines as $line) {
            $this->lines[$this->nextRow++] = ['product_id' => (string) $line->product_id, 'quantity' => (string) $line->quantity, 'expected_cost' => (string) ($line->expected_cost ?? '')];
        }
    }

    public function addRow(): void
    {
        $this->lines[$this->nextRow++] = ['product_id' => '', 'quantity' => '', 'expected_cost' => ''];
    }

    public function removeRow(int $row): void
    {
        unset($this->lines[$row]);
    }

    public function updatedLines(mixed $value, string $key): void
    {
        [$row, $field] = explode('.', $key);

        if ($field === 'product_id' && is_numeric($value)) {
            $this->lines[$row]['expected_cost'] = (string) (Product::find($value)?->cost_price ?? '');
        }
    }

    public function with(): array
    {
        $context = BranchContext::current();
        $shopId = Auth::user()->shop_id;
        $products = Product::query()->whereNull('archived_at')->orderBy('name')->get();

        return [
            'vendors' => Vendor::query()->orderBy('name')->get(),
            'products' => $products,
            'productsById' => $products->keyBy('id'),
            'branches' => $context->restrictedBranchId() ? collect() : $context->branchesFor($shopId),
        ];
    }

    public function save(): void
    {
        abort_unless(Auth::user()->shop?->purchase_orders_enabled, 403);

        $shopId = Auth::user()->shop_id;
        $rules = [
            'vendorId' => ['required', 'integer', Rule::exists('vendors', 'id')->where('shop_id', $shopId)],
            'orderDate' => ['required', 'date'],
            'expectedDate' => ['nullable', 'date', 'after_or_equal:orderDate'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
        ];

        foreach ($this->lines as $row => $line) {
            $unit = is_numeric($line['product_id']) ? (Product::find($line['product_id'])?->unit ?? QuantityUnit::Piece) : QuantityUnit::Piece;
            $rules["lines.$row.product_id"] = ['required', 'integer', Rule::exists('products', 'id')->where('shop_id', $shopId)];
            $rules["lines.$row.quantity"] = $unit->quantityRules();
            $rules["lines.$row.expected_cost"] = ['nullable', 'numeric', 'min:0', 'max:99999999.99'];
        }

        $this->validate($rules, [], ['vendorId' => 'vendor']);

        $order = SavePurchaseOrder::handle(
            $this->orderId ? PurchaseOrder::findOrFail($this->orderId) : null,
            [
                'vendor_id' => (int) $this->vendorId,
                'branch_id' => $this->branchId !== '' ? (int) $this->branchId : null,
                'order_date' => $this->orderDate,
                'expected_date' => $this->expectedDate !== '' ? $this->expectedDate : null,
                'note' => $this->note,
            ],
            array_values(array_map(fn (array $line) => [
                'product_id' => (int) $line['product_id'],
                'quantity' => $line['quantity'],
                'expected_cost' => $line['expected_cost'] !== '' ? (float) $line['expected_cost'] : null,
            ], $this->lines)),
            Auth::id(),
        );

        session()->flash('status', 'Purchase order saved.');
        $this->redirectRoute('purchase-orders.show', $order->id, navigate: true);
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">{{ $orderId ? 'Edit Purchase Order' : 'New Purchase Order' }}</h1>
            <a href="{{ route('purchase-orders.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">← All Purchase Orders</a>
        </div>
    </x-slot>

    <form wire:submit="save" class="space-y-6">
        <x-ui.card title="Order" description="An order moves no stock and no money. That happens when goods are received.">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.field label="Vendor" name="vendorId" for="vendorId">
                    <x-ui.select wire:model="vendorId" id="vendorId">
                        <option value="">Select a vendor</option>
                        @foreach ($vendors as $vendor)
                            <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Order Date" name="orderDate" for="orderDate">
                    <x-ui.input wire:model="orderDate" id="orderDate" type="date" />
                </x-ui.field>

                <x-ui.field label="Expected By" name="expectedDate" for="expectedDate" help="Optional">
                    <x-ui.input wire:model="expectedDate" id="expectedDate" type="date" />
                </x-ui.field>

                @if ($branches->count() > 1)
                    <x-ui.field label="Deliver To" name="branchId" for="branchId" help="The branch expecting the goods">
                        <x-ui.select wire:model="branchId" id="branchId">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif
            </div>

            <div class="mt-4">
                <x-ui.field label="Note" name="note" for="note" help="Optional">
                    <x-ui.input wire:model="note" id="note" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Products" description="Each size or colour of a variant group is its own line.">
            <x-ui.error :messages="$errors->get('lines')" class="mb-3" />

            <div class="space-y-4">
                @foreach ($lines as $row => $line)
                    @php($rowProduct = is_numeric($line['product_id']) ? $productsById->get((int) $line['product_id']) : null)
                    @php($rowUnit = $rowProduct?->unit ?? QuantityUnit::Piece)
                    <div class="grid grid-cols-1 gap-3 border-b border-slate-100 pb-4 sm:grid-cols-[minmax(0,1fr)_9rem_9rem_auto] sm:items-start" wire:key="po-row-{{ $row }}">
                        <x-ui.field label="Product" name="lines.{{ $row }}.product_id" for="line-product-{{ $row }}">
                            <x-ui.select wire:model.live="lines.{{ $row }}.product_id" id="line-product-{{ $row }}">
                                <option value="">Select a product</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field :label="$rowUnit->allowsDecimals() ? 'Quantity ('.$rowUnit->symbol().')' : 'Quantity'" name="lines.{{ $row }}.quantity" for="line-quantity-{{ $row }}">
                            <x-ui.input wire:model="lines.{{ $row }}.quantity" id="line-quantity-{{ $row }}" type="number" :min="$rowUnit->inputMin()" :step="$rowUnit->inputStep()" />
                        </x-ui.field>

                        <x-ui.field :label="$rowUnit->allowsDecimals() ? 'Expected Cost (per '.$rowUnit->symbol().')' : 'Expected Cost'" name="lines.{{ $row }}.expected_cost" for="line-cost-{{ $row }}" help="Optional">
                            <x-ui.input wire:model="lines.{{ $row }}.expected_cost" id="line-cost-{{ $row }}" type="number" min="0" step="0.01" wire:key="line-cost-input-{{ $row }}-{{ $line['product_id'] }}" />
                        </x-ui.field>

                        <div class="sm:pt-7">
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="removeRow({{ $row }})" class="text-red-600 hover:bg-red-50">Remove</x-ui.button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4">
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="addRow">Add Product</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.button type="submit" size="lg" wire:loading.attr="disabled" wire:target="save">Save Purchase Order</x-ui.button>
    </form>
</div>
