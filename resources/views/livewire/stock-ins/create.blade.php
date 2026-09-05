<?php

use App\Enums\StockInPaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\StockIn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Stock In')] class extends Component
{
    use Toasts;

    public string $categoryFilter = '';
    public string $productId = '';
    public string $quantity = '';
    public string $paymentStatus = 'paid';
    public string $amountPaid = '';
    public string $stock_date = '';
    public string $note = '';

    public function mount(): void
    {
        MainCategory::ensureDefaultsExist();
        $this->stock_date = now()->toDateString();
    }

    public function updatedCategoryFilter(): void
    {
        $this->productId = '';
    }

    public function updatedPaymentStatus(): void
    {
        if ($this->paymentStatus !== 'partial') {
            $this->amountPaid = '';
        }
    }

    public function with(): array
    {
        return [
            'categories' => MainCategory::query()->orderBy('name')->get(),
            'products' => Product::query()
                ->when($this->categoryFilter, fn ($query) => $query->where('type', $this->categoryFilter))
                ->orderBy('name')
                ->get(),
            'paymentStatuses' => StockInPaymentStatus::cases(),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'productId' => ['required', 'integer', Rule::exists('products', 'id')->where('shop_id', auth()->user()->shop_id)],
            'quantity' => ['required', 'integer', 'min:1'],
            'paymentStatus' => ['required', Rule::enum(StockInPaymentStatus::class)],
            'amountPaid' => ['nullable', 'numeric', 'min:0', 'required_if:paymentStatus,partial'],
            'stock_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () {
            $product = Product::whereKey($this->productId)->lockForUpdate()->firstOrFail();

            $product->increment('stock_quantity', (int) $this->quantity);

            $totalCost = $product->cost_price !== null ? $product->cost_price * (int) $this->quantity : null;

            $amountPaid = match ($this->paymentStatus) {
                'paid' => $totalCost ?? 0,
                'partial' => $this->amountPaid !== '' ? $this->amountPaid : 0,
                default => 0,
            };

            StockIn::create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'user_id' => Auth::id(),
                'quantity' => $this->quantity,
                'total_cost' => $totalCost,
                'payment_status' => $this->paymentStatus,
                'amount_paid' => $amountPaid,
                'stock_date' => $this->stock_date,
                'note' => $this->note !== '' ? $this->note : null,
            ]);
        });

        $this->toastSuccess('Stock added.');
        $this->reset(['productId', 'quantity', 'amountPaid', 'note']);
        $this->paymentStatus = 'paid';
        $this->stock_date = now()->toDateString();
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Stock In</h1>
            <a href="{{ route('stock-ins.history') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                History →
            </a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-lg">
        <x-ui.card title="Log Incoming Stock">
            <form wire:submit="save" class="space-y-5">
                <x-ui.field label="Category" name="categoryFilter" for="categoryFilter" help="Optional — narrows the product list below">
                    <x-ui.select wire:model.live="categoryFilter" id="categoryFilter">
                        <option value="">All Categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->slug }}">{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Product" name="productId" for="productId">
                    <x-ui.select wire:model="productId" id="productId">
                        <option value="">Select a product</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }} (current stock: {{ $product->stock_quantity }})</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Quantity Purchase" name="quantity" for="quantity">
                    <x-ui.input wire:model="quantity" id="quantity" type="number" min="1" step="1" class="text-lg" />
                </x-ui.field>

                <x-ui.field label="Payment Status" name="paymentStatus" for="paymentStatus" help="Have you paid your supplier for this stock?">
                    <x-ui.select wire:model.live="paymentStatus" id="paymentStatus">
                        @foreach ($paymentStatuses as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                @if ($paymentStatus === 'partial')
                    <x-ui.field label="Amount Paid" name="amountPaid" for="amountPaid">
                        <x-ui.input wire:model="amountPaid" id="amountPaid" type="number" min="0" step="0.01" />
                    </x-ui.field>
                @endif

                <x-ui.field label="Date Received" name="stock_date" for="stock_date">
                    <x-ui.input wire:model="stock_date" id="stock_date" type="date" />
                </x-ui.field>

                <x-ui.field label="Note" name="note" for="note" help="Optional — e.g. supplier name or reference">
                    <x-ui.input wire:model="note" id="note" />
                </x-ui.field>

                <x-ui.button type="submit" size="lg" class="w-full justify-center" wire:loading.attr="disabled" wire:target="save">
                    Add Stock
                </x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>
