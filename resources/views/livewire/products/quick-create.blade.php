<?php

use App\Actions\CreateProduct;
use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\UploadsImages;
use App\Models\AccessoryCategoryOption;
use App\Models\MainCategory;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use Toasts, UploadsImages, WithFileUploads;

    #[Reactive]
    public string $defaultMainCategorySlug = '';

    public string $type = 'mobile';
    public string $name = '';
    public $image = null;
    public string $price = '';
    public string $cost_price = '';
    public string $stock_quantity = '0';

    public string $brand = '';
    public string $model = '';
    public string $imei = '';

    public string $category = '';

    protected string $typeBeforeCreate = '';

    public function mount(): void
    {
        MainCategory::ensureDefaultsExist();
        $this->syncDefaultType();
    }

    public function updatedDefaultMainCategorySlug(): void
    {
        $this->syncDefaultType();
    }

    protected function syncDefaultType(): void
    {
        $this->type = $this->defaultMainCategorySlug !== ''
            ? $this->defaultMainCategorySlug
            : (MainCategory::query()->orderBy('name')->value('slug') ?? 'mobile');
    }

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
            'mainCategories' => $mainCategories,
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

    public function save(): void
    {
        $this->validate(CreateProduct::rules(Auth::user()->shop_id, $this->type));

        $product = CreateProduct::handle([
            'type' => $this->type,
            'name' => $this->name,
            'price' => $this->price,
            'cost_price' => $this->cost_price !== '' ? $this->cost_price : null,
            'stock_quantity' => $this->stock_quantity,
            'details' => CreateProduct::detailsForType($this->type, $this->category, $this->brand, $this->model, $this->imei),
        ]);

        if ($this->image) {
            $product->image_path = $this->storeImage($this->image, 'products', $product->image_path);
            $product->save();
        }

        $this->toastSuccess('Product added.');
        $this->dispatch('close-modal', name: 'quick-create-product');
        $this->dispatch(
            'product-created',
            productId: $product->id,
            mainCategorySlug: $product->type,
            subCategoryName: $product->details['category'] ?? '',
        );
        $this->reset(['name', 'image', 'price', 'cost_price', 'brand', 'model', 'imei', 'category']);
        $this->stock_quantity = '0';
    }

    public function close(): void
    {
        $this->dispatch('close-modal', name: 'quick-create-product');
        $this->reset(['name', 'image', 'price', 'cost_price', 'brand', 'model', 'imei', 'category']);
        $this->stock_quantity = '0';
        $this->syncDefaultType();
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ui.modal name="quick-create-product" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">New Product</h2>

            <div class="mt-5 space-y-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Main Category" name="type" for="quickProductType">
                        <x-ui.select wire:model.live="type" id="quickProductType">
                            <option value="__create__">+ New Main Category</option>
                            @foreach ($mainCategories as $option)
                                <option value="{{ $option->slug }}">{{ $option->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Product Name" name="name" for="quickProductName">
                        <x-ui.input wire:model="name" id="quickProductName" autofocus />
                    </x-ui.field>
                </div>

                <x-ui.field label="Image" name="image" for="quickProductImage" help="Optional">
                    <x-ui.file-input wire:model="image" id="quickProductImage" :preview="$this->previewUrl($image, null)" />
                </x-ui.field>

                @if ($type === 'mobile')
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Brand" name="brand" for="quickProductBrand">
                            <x-ui.input wire:model="brand" id="quickProductBrand" placeholder="e.g. Samsung" />
                        </x-ui.field>

                        <x-ui.field label="Model" name="model" for="quickProductModel">
                            <x-ui.input wire:model="model" id="quickProductModel" placeholder="e.g. Galaxy A15" />
                        </x-ui.field>
                    </div>

                    <x-ui.field label="IMEI / Serial" name="imei" for="quickProductImei" help="Optional">
                        <x-ui.input wire:model="imei" id="quickProductImei" />
                    </x-ui.field>
                @endif

                <x-ui.field label="Sub-Category" name="category" for="quickProductCategory" :help="$type === 'accessory' ? null : 'Optional'">
                    <x-ui.image-select
                        wire:key="quick-product-category-select-{{ $type }}"
                        wire-model="category"
                        :options="$accessoryCategoryOptions"
                        id="quickProductCategory"
                        placeholder="Select a sub-category"
                    />
                </x-ui.field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-ui.field label="Selling Price" name="price" for="quickProductPrice">
                        <x-ui.input wire:model="price" id="quickProductPrice" type="number" step="0.01" min="0" />
                    </x-ui.field>

                    <x-ui.field label="Cost Price" name="cost_price" for="quickProductCostPrice" help="Optional">
                        <x-ui.input wire:model="cost_price" id="quickProductCostPrice" type="number" step="0.01" min="0" />
                    </x-ui.field>

                    <x-ui.field label="Stock Qty" name="stock_quantity" for="quickProductStockQuantity">
                        <x-ui.input wire:model="stock_quantity" id="quickProductStockQuantity" type="number" step="1" min="0" />
                    </x-ui.field>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="close">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    Add Product
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
