<?php

use App\Actions\ReceiveGoods;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Support\BranchContext;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Receive Goods')] class extends Component
{
    public int $orderId;
    public string $receivedOn = '';
    public string $branchId = '';
    public string $note = '';
    public bool $confirmOverReceipt = false;

    /** One row per batch: a delivery can carry two expiry dates of the same product. */
    public array $rows = [];
    public int $nextRow = 1;

    public function mount(int $order): void
    {
        abort_unless(Auth::user()->shop?->purchase_orders_enabled, 403);

        $po = PurchaseOrder::inCurrentBranch()->with(['lines.activeGrnLines', 'lines.product'])->findOrFail($order);
        abort_unless($po->canReceive(), 403);

        $this->orderId = $po->id;
        $this->receivedOn = now()->toDateString();
        $this->branchId = (string) ($po->branch_id ?? BranchContext::current()->recordingBranchIdFor(Auth::user()->shop_id));

        foreach ($po->lines as $line) {
            if ($line->product !== null) {
                $this->rows[$this->nextRow++] = $this->rowFor($line, $line->outstandingQuantity());
            }
        }
    }

    protected function rowFor(PoLine $line, int|float $quantity): array
    {
        return [
            'po_line_id' => $line->id,
            'quantity' => Quantity::compare($quantity, 0) > 0 ? Quantity::plain($quantity) : '',
            'cost_price' => (string) ($line->expected_cost ?? $line->product?->cost_price ?? ''),
            'selling_price' => (string) ($line->product?->price ?? ''),
            'expiry_date' => $this->suggestedExpiry($line->product),
        ];
    }

    protected function suggestedExpiry(?Product $product): string
    {
        return Auth::user()->shop?->expiry_tracking_enabled && $product?->shelf_life_days
            ? Carbon::parse($this->receivedOn)->addDays($product->shelf_life_days)->toDateString()
            : '';
    }

    public function addBatch(int $row): void
    {
        $line = PoLine::with('product')->findOrFail($this->rows[$row]['po_line_id']);
        abort_unless($line->purchase_order_id === $this->orderId, 403);

        $this->rows[$this->nextRow++] = $this->rowFor($line, 0);
        ksort($this->rows);
    }

    public function removeRow(int $row): void
    {
        unset($this->rows[$row]);
    }

    public function with(): array
    {
        $context = BranchContext::current();
        $order = PurchaseOrder::inCurrentBranch()->with(['vendor', 'lines.activeGrnLines'])->findOrFail($this->orderId);

        return [
            'order' => $order,
            'poLines' => $order->lines->keyBy('id'),
            'expiryEnabled' => (bool) Auth::user()->shop?->expiry_tracking_enabled,
            'branches' => $context->restrictedBranchId() ? collect() : $context->branchesFor(Auth::user()->shop_id),
        ];
    }

    public function save(): void
    {
        abort_unless(Auth::user()->shop?->purchase_orders_enabled, 403);

        $order = PurchaseOrder::inCurrentBranch()->with('lines')->findOrFail($this->orderId);
        $poLines = $order->lines->keyBy('id');

        // A row with no quantity simply didn't arrive in this delivery.
        $filled = array_filter($this->rows, fn (array $row) => trim((string) $row['quantity']) !== '');

        $rules = [
            'receivedOn' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ];

        foreach ($filled as $key => $row) {
            $rules["rows.$key.quantity"] = $poLines->get($row['po_line_id'])?->unit->quantityRules() ?? ['required', 'numeric'];
            $rules["rows.$key.cost_price"] = ['nullable', 'numeric', 'min:0', 'max:99999999.99'];
            $rules["rows.$key.selling_price"] = ['required', 'numeric', 'min:0', 'max:99999999.99'];
            $rules["rows.$key.expiry_date"] = ['nullable', 'date'];
        }

        $this->validate($rules, [], ['rows.*.quantity' => 'quantity', 'rows.*.selling_price' => 'selling price', 'rows.*.cost_price' => 'cost price']);

        $grn = ReceiveGoods::handle(
            $order,
            array_values(array_map(fn (array $row) => [
                'po_line_id' => (int) $row['po_line_id'],
                'quantity' => $row['quantity'],
                'cost_price' => $row['cost_price'] !== '' ? (float) $row['cost_price'] : null,
                'selling_price' => (float) $row['selling_price'],
                'expiry_date' => $row['expiry_date'] !== '' ? $row['expiry_date'] : null,
            ], $filled)),
            $this->receivedOn,
            $this->branchId !== '' ? (int) $this->branchId : null,
            $this->note,
            Auth::id(),
            $this->confirmOverReceipt,
        );

        session()->flash('status', $grn->number().' recorded.');
        $this->redirectRoute('purchase-orders.show', $order->id, navigate: true);
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Receive Goods: {{ $order->number() }}</h1>
            <a href="{{ route('purchase-orders.show', $order->id) }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">← Back to Order</a>
        </div>
    </x-slot>

    <form wire:submit="save" class="space-y-6">
        <x-ui.card title="Delivery" :description="'From '.($order->vendor?->name ?? 'vendor').'. This adds the stock now and adds to what you owe the vendor. Record payments on Stock In History, as usual.'">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-ui.field label="Date Received" name="receivedOn" for="receivedOn">
                    <x-ui.input wire:model="receivedOn" id="receivedOn" type="date" max="{{ now()->toDateString() }}" />
                </x-ui.field>

                @if ($branches->count() > 1)
                    <x-ui.field label="Received At" name="branchId" for="branchId">
                        <x-ui.select wire:model="branchId" id="branchId">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif

                <x-ui.field label="Note" name="note" for="note" help="Optional">
                    <x-ui.input wire:model="note" id="note" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="What Arrived" description="Leave a quantity blank if that product didn't come in this delivery. Each row becomes one batch.">
            <x-ui.error :messages="$errors->get('lines')" class="mb-3" />

            @error('overReceipt')
                <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <p>{{ $message }}</p>
                    <label class="mt-2 flex items-center gap-2 font-medium">
                        <input type="checkbox" wire:model="confirmOverReceipt" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        Yes, this is what arrived
                    </label>
                </div>
            @enderror

            <div class="space-y-4">
                {{-- An extra batch sits under its own product. --}}
                @foreach (collect($rows)->sortBy('po_line_id') as $key => $row)
                    @php($line = $poLines->get($row['po_line_id']))
                    <div class="border-b border-slate-100 pb-4" wire:key="grn-row-{{ $key }}">
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <p class="text-sm font-medium text-slate-900">
                                {{ $line->product_name }}
                                <span class="font-normal text-slate-400">· ordered {{ $line->unit->amount($line->quantity) }}, received {{ $line->unit->amount($line->receivedQuantity()) }}</span>
                            </p>
                            <div class="flex gap-2">
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="addBatch({{ $key }})">Add Another Batch</x-ui.button>
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="removeRow({{ $key }})" class="text-red-600 hover:bg-red-50">Remove</x-ui.button>
                            </div>
                        </div>
                        <div @class(['grid grid-cols-1 gap-3', 'sm:grid-cols-3' => ! $expiryEnabled, 'sm:grid-cols-2 lg:grid-cols-4' => $expiryEnabled])>
                            <x-ui.field :label="$line->unit->allowsDecimals() ? 'Quantity ('.$line->unit->symbol().')' : 'Quantity'" name="rows.{{ $key }}.quantity" for="row-quantity-{{ $key }}">
                                <x-ui.input wire:model="rows.{{ $key }}.quantity" id="row-quantity-{{ $key }}" type="number" :min="$line->unit->inputMin()" :step="$line->unit->inputStep()" />
                            </x-ui.field>
                            <x-ui.field label="Cost Price" name="rows.{{ $key }}.cost_price" for="row-cost-{{ $key }}" help="Optional">
                                <x-ui.input wire:model="rows.{{ $key }}.cost_price" id="row-cost-{{ $key }}" type="number" min="0" step="0.01" />
                            </x-ui.field>
                            <x-ui.field label="Selling Price" name="rows.{{ $key }}.selling_price" for="row-sell-{{ $key }}">
                                <x-ui.input wire:model="rows.{{ $key }}.selling_price" id="row-sell-{{ $key }}" type="number" min="0" step="0.01" />
                            </x-ui.field>
                            @if ($expiryEnabled)
                                <x-ui.field label="Expiry Date" name="rows.{{ $key }}.expiry_date" for="row-expiry-{{ $key }}" help="Optional. The last day it can be sold">
                                    <x-ui.input wire:model="rows.{{ $key }}.expiry_date" id="row-expiry-{{ $key }}" type="date" />
                                </x-ui.field>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.button type="submit" size="lg" wire:loading.attr="disabled" wire:target="save">Record Delivery</x-ui.button>
    </form>
</div>
