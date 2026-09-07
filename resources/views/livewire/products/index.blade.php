<?php

use App\Actions\CreateProduct;
use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\UploadsImages;
use App\Models\AccessoryCategoryOption;
use App\Models\MainCategory;
use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Products')] class extends Component
{
    use Toasts, UploadsImages, WithFileUploads, WithPagination;

    public string $search = '';
    public string $typeFilter = '';

    public ?int $editingId = null;

    public string $type = 'mobile';
    public string $name = '';
    public $image = null;
    public ?string $existingImageUrl = null;
    public string $price = '';
    public string $cost_price = '';
    public string $stock_quantity = '0';

    public string $brand = '';
    public string $model = '';
    public string $imei = '';

    public string $category = '';

    public function mount(): void
    {
        MainCategory::ensureDefaultsExist();
        AccessoryCategoryOption::ensureDefaultsExist();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    protected string $typeBeforeCreate = '';

    public function updatingType(string $value): void
    {
        if ($value === '__create__') {
            $this->typeBeforeCreate = $this->type;
        }
    }

    public function updatedType(): void
    {
        if ($this->type === '__create__') {
            $this->type = $this->typeBeforeCreate !== '' ? $this->typeBeforeCreate : 'mobile';
            $this->dispatch('open-modal', name: 'quick-create-main-category');

            return;
        }

        $this->resetErrorBag();
        $this->category = '';
    }

    #[On('main-category-created')]
    public function onMainCategoryCreated(string $slug): void
    {
        $this->type = $slug;
        $this->category = '';
    }

    public function updatedCategory(): void
    {
        if ($this->category === '__create__') {
            $this->category = '';
            $this->dispatch('open-modal', name: 'quick-create-accessory-category');
        }
    }

    #[On('accessory-category-created')]
    public function onAccessoryCategoryCreated(string $categoryName): void
    {
        $this->category = $categoryName;
    }

    public function with(): array
    {
        $mainCategories = MainCategory::query()->orderBy('name')->get();
        $currentMainCategory = $mainCategories->firstWhere('slug', $this->type);

        return [
            'products' => Product::query()
                ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
                ->when($this->typeFilter, fn ($query) => $query->where('type', $this->typeFilter))
                ->latest()
                ->paginate(10),
            'mainCategories' => $mainCategories,
            'typeFilterOptions' => $mainCategories
                ->map(fn (MainCategory $category) => ['value' => $category->slug, 'label' => $category->name])
                ->when(
                    Product::where('type', 'sim')->exists(),
                    fn ($options) => $options->push(['value' => 'sim', 'label' => 'SIM / eSIM (Legacy)'])
                ),
            'accessoryCategoryOptions' => collect([
                ['value' => '__create__', 'label' => 'New Sub-Category', 'image' => null, 'special' => true, 'modal' => 'quick-create-accessory-category'],
            ])->concat(
                AccessoryCategoryOption::query()
                    ->when($currentMainCategory, fn ($query) => $query->where('main_category_id', $currentMainCategory->id))
                    ->orderBy('name')
                    ->get()
                    ->map(fn (AccessoryCategoryOption $option) => [
                        'value' => $option->name,
                        'label' => $option->name,
                        'image' => $option->imageUrl(),
                    ])
            )->all(),
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

        abort_if($product->type === 'sim', 403, 'Legacy SIM products cannot be edited.');

        $this->editingId = $product->id;
        $this->type = $product->type;
        $this->name = $product->name;
        $this->image = null;
        $this->existingImageUrl = $product->imageUrl();
        $this->price = (string) $product->price;
        $this->cost_price = (string) $product->cost_price;
        $this->stock_quantity = (string) $product->stock_quantity;

        $this->brand = $product->details['brand'] ?? '';
        $this->model = $product->details['model'] ?? '';
        $this->imei = $product->details['imei'] ?? '';
        $this->category = $product->details['category'] ?? '';

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
        ]);

        if ($this->image) {
            $product->image_path = $this->storeImage($this->image, 'products', $product->image_path);
        }

        $product->save();

        $this->toastSuccess($this->editingId ? 'Product updated.' : 'Product added.');
        $this->dispatch('close-modal', name: 'product-form');
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $product = Product::findOrFail($id);
        $this->deleteImage($product->image_path);
        $product->delete();

        $this->toastSuccess('Product deleted.');
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'product-form');
        $this->resetForm();
    }

    protected function rules(): array
    {
        return CreateProduct::rules(auth()->user()->shop_id, $this->type);
    }

    protected function detailsForType(): array
    {
        return CreateProduct::detailsForType($this->type, $this->category, $this->brand, $this->model, $this->imei);
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'image', 'existingImageUrl', 'price', 'cost_price',
            'brand', 'model', 'imei', 'category',
        ]);

        $this->type = 'mobile';
        $this->stock_quantity = '0';
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
                @foreach ($typeFilterOptions as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
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
            description="Add your first product to start selling."
        >
            <x-slot name="action">
                <x-ui.button wire:click="openCreate">Add Product</x-ui.button>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Product', 'Category', 'Price', 'Stock', '']">
            @foreach ($products as $product)
                <x-ui.table-row wire:key="product-{{ $product->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">
                        <div class="flex items-center gap-3">
                            <x-ui.thumbnail :src="$product->imageUrl()" :label="$product->name" />
                            {{ $product->name }}
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>
                        <div class="flex flex-col">
                            <span class="flex items-center gap-1.5 text-slate-700">
                                {{ $product->typeLabel() }}
                                @if ($product->type === 'sim')
                                    <x-ui.badge variant="warning">Legacy</x-ui.badge>
                                @endif
                            </span>
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
                            @if ($product->type !== 'sim')
                                <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $product->id }})">
                                    Edit
                                </x-ui.button>
                            @endif
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
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Main Category" name="type" for="type">
                        <x-ui.select wire:model.live="type" id="type">
                            <option value="__create__">+ New Main Category</option>
                            @foreach ($mainCategories as $option)
                                <option value="{{ $option->slug }}">{{ $option->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Product Name" name="name" for="name">
                        <x-ui.input wire:model="name" id="name" autofocus />
                    </x-ui.field>
                </div>

                <x-ui.field label="Image" name="image" for="image" help="Optional">
                    <x-ui.file-input wire:model="image" id="image" :preview="$this->previewUrl($image, $existingImageUrl)" />
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
                @endif

                <x-ui.field label="Sub-Category" name="category" for="category" :help="$type === 'accessory' ? null : 'Optional'">
                    <x-ui.image-select
                        wire:key="category-select-{{ $type }}"
                        wire-model="category"
                        :options="$accessoryCategoryOptions"
                        id="category"
                        placeholder="Select a sub-category"
                    />
                </x-ui.field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-ui.field label="Selling Price" name="price" for="price">
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

    <livewire:accessory-categories.quick-create :default-main-category-slug="$type" />
    <livewire:main-categories.quick-create />
</div>
