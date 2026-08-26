<?php

use App\Enums\AccessoryCategory;
use App\Enums\ProductType;
use App\Enums\SimForm;
use App\Enums\SimType;
use App\Livewire\Concerns\Toasts;
use App\Models\Product;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Products')] class extends Component
{
    use Toasts, WithPagination;

    public string $search = '';
    public string $typeFilter = '';

    public ?int $editingId = null;

    public string $type = 'mobile';
    public string $name = '';
    public string $price = '';
    public string $cost_price = '';
    public string $stock_quantity = '0';

    public string $brand = '';
    public string $model = '';
    public string $imei = '';

    public string $category = '';

    public string $sim_type = 'prepaid';
    public string $sim_form = 'physical';
    public string $network = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetErrorBag();
    }

    public function with(): array
    {
        return [
            'products' => Product::query()
                ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
                ->when($this->typeFilter, fn ($query) => $query->where('type', $this->typeFilter))
                ->latest()
                ->paginate(10),
            'types' => ProductType::cases(),
            'accessoryCategories' => AccessoryCategory::cases(),
            'simTypes' => SimType::cases(),
            'simForms' => SimForm::cases(),
        ];
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', name: 'product-form');
    }

    public function openEdit(int $id): void
    {
        $product = Product::findOrFail($id);

        $this->editingId = $product->id;
        $this->type = $product->type->value;
        $this->name = $product->name;
        $this->price = (string) $product->price;
        $this->cost_price = (string) $product->cost_price;
        $this->stock_quantity = (string) $product->stock_quantity;

        $this->brand = $product->details['brand'] ?? '';
        $this->model = $product->details['model'] ?? '';
        $this->imei = $product->details['imei'] ?? '';
        $this->category = $product->details['category'] ?? '';
        $this->sim_type = $product->details['sim_type'] ?? 'prepaid';
        $this->sim_form = $product->details['sim_form'] ?? 'physical';
        $this->network = $product->details['network'] ?? '';

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'product-form');
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $product = $this->editingId ? Product::findOrFail($this->editingId) : new Product;

        $product->fill([
            'type' => $this->type,
            'name' => $this->name,
            'price' => $this->price,
            'cost_price' => $this->cost_price !== '' ? $this->cost_price : null,
            'stock_quantity' => $this->stock_quantity,
            'details' => $this->detailsForType(),
        ])->save();

        $this->toastSuccess($this->editingId ? 'Product updated.' : 'Product added.');
        $this->dispatch('close-modal', name: 'product-form');
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        Product::findOrFail($id)->delete();

        $this->toastSuccess('Product deleted.');
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'product-form');
        $this->resetForm();
    }

    protected function rules(): array
    {
        $rules = [
            'type' => ['required', Rule::enum(ProductType::class)],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
        ];

        return match ($this->type) {
            ProductType::Mobile->value => $rules + [
                'brand' => ['required', 'string', 'max:255'],
                'model' => ['required', 'string', 'max:255'],
                'imei' => ['nullable', 'string', 'max:50'],
            ],
            ProductType::Accessory->value => $rules + [
                'category' => ['required', Rule::enum(AccessoryCategory::class)],
            ],
            ProductType::Sim->value => $rules + [
                'sim_type' => ['required', Rule::enum(SimType::class)],
                'sim_form' => ['required', Rule::enum(SimForm::class)],
                'network' => ['required', 'string', 'max:255'],
            ],
            default => $rules,
        };
    }

    protected function detailsForType(): array
    {
        return match ($this->type) {
            ProductType::Mobile->value => [
                'brand' => $this->brand,
                'model' => $this->model,
                'imei' => $this->imei !== '' ? $this->imei : null,
            ],
            ProductType::Accessory->value => [
                'category' => $this->category,
            ],
            ProductType::Sim->value => [
                'sim_type' => $this->sim_type,
                'sim_form' => $this->sim_form,
                'network' => $this->network,
            ],
            default => [],
        };
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'price', 'cost_price',
            'brand', 'model', 'imei', 'category', 'network',
        ]);

        $this->type = 'mobile';
        $this->stock_quantity = '0';
        $this->sim_type = 'prepaid';
        $this->sim_form = 'physical';
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Products</h1>
    </x-slot>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row">
            <x-ui.input wire:model.live.debounce.400ms="search" type="search" placeholder="Search products…" class="sm:max-w-xs" />

            <x-ui.select wire:model.live="typeFilter" class="sm:max-w-xs">
                <option value="">All categories</option>
                @foreach ($types as $typeOption)
                    <option value="{{ $typeOption->value }}">{{ $typeOption->label() }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <x-ui.button wire:click="openCreate" class="shrink-0">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Add Product
        </x-ui.button>
    </div>

    @if ($products->isEmpty())
        <x-ui.empty-state
            title="No products yet"
            description="Add your first mobile, accessory, or SIM/eSIM to start selling."
        >
            <x-slot name="action">
                <x-ui.button wire:click="openCreate">Add Product</x-ui.button>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Product', 'Category', 'Price', 'Stock', '']">
            @foreach ($products as $product)
                <x-ui.table-row wire:key="product-{{ $product->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $product->name }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        <div class="flex flex-col">
                            <span class="text-slate-700">{{ $product->type->label() }}</span>
                            @if ($line = $product->summaryLine())
                                <span class="text-xs text-slate-400">{{ $line }}</span>
                            @endif
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($product->price, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($product->isOutOfStock())
                            <x-ui.badge variant="danger">Out of stock</x-ui.badge>
                        @elseif ($product->isLowStock())
                            <x-ui.badge variant="warning">Low · {{ $product->stock_quantity }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="success">{{ $product->stock_quantity }} in stock</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $product->id }})">
                                Edit
                            </x-ui.button>
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                wire:click="delete({{ $product->id }})"
                                wire:confirm="Delete {{ $product->name }}? This cannot be undone."
                                class="text-red-600 hover:bg-red-50"
                            >
                                Delete
                            </x-ui.button>
                        </div>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $products->links() }}
        </div>
    @endif

    <x-ui.modal name="product-form" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $editingId ? 'Edit Product' : 'Add Product' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Category" name="type" for="type">
                    <x-ui.select wire:model.live="type" id="type">
                        @foreach ($types as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Product Name" name="name" for="name">
                    <x-ui.input wire:model="name" id="name" autofocus />
                </x-ui.field>

                @if ($type === 'mobile')
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Brand" name="brand" for="brand">
                            <x-ui.input wire:model="brand" id="brand" placeholder="e.g. Samsung" />
                        </x-ui.field>

                        <x-ui.field label="Model" name="model" for="model">
                            <x-ui.input wire:model="model" id="model" placeholder="e.g. Galaxy A15" />
                        </x-ui.field>
                    </div>

                    <x-ui.field label="IMEI / Serial" name="imei" for="imei" help="Optional">
                        <x-ui.input wire:model="imei" id="imei" />
                    </x-ui.field>
                @elseif ($type === 'accessory')
                    <x-ui.field label="Accessory Category" name="category" for="category">
                        <x-ui.select wire:model="category" id="category">
                            <option value="">Select a category</option>
                            @foreach ($accessoryCategories as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @elseif ($type === 'sim')
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Plan Type" name="sim_type" for="sim_type">
                            <x-ui.select wire:model="sim_type" id="sim_type">
                                @foreach ($simTypes as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="SIM Form" name="sim_form" for="sim_form">
                            <x-ui.select wire:model="sim_form" id="sim_form">
                                @foreach ($simForms as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>

                    <x-ui.field label="Network / Carrier" name="network" for="network">
                        <x-ui.input wire:model="network" id="network" placeholder="e.g. Jazz, Zong, Telenor" />
                    </x-ui.field>
                @endif

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-ui.field label="Price" name="price" for="price">
                        <x-ui.input wire:model="price" id="price" type="number" step="0.01" min="0" />
                    </x-ui.field>

                    <x-ui.field label="Cost Price" name="cost_price" for="cost_price" help="Optional">
                        <x-ui.input wire:model="cost_price" id="cost_price" type="number" step="0.01" min="0" />
                    </x-ui.field>

                    <x-ui.field label="Stock Qty" name="stock_quantity" for="stock_quantity">
                        <x-ui.input wire:model="stock_quantity" id="stock_quantity" type="number" step="1" min="0" />
                    </x-ui.field>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingId ? 'Save Changes' : 'Add Product' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
