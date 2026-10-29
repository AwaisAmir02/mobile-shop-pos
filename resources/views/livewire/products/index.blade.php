<?php

use App\Actions\CreateProduct;
use App\Enums\LimitKey;
use App\Exceptions\UsageLimitReached;
use App\Livewire\Concerns\GuardsExportSize;
use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\UploadsImages;
use App\Models\AccessoryCategoryOption;
use App\Models\Brand;
use App\Models\MainCategory;
use App\Models\Product;
use App\ReportQueries\ProductReport;
use App\Services\BarcodeService;
use App\Services\Billing\Entitlements;
use App\Services\ProductLabelPdfService;
use App\Services\TableExportService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Products')] class extends Component
{
    use GuardsExportSize, Toasts, UploadsImages, WithFileUploads, WithPagination;

    public const MAX_SHEET_PRODUCTS = 100;

    public string $search = '';
    public string $typeFilter = '';

    public ?int $editingId = null;
    public bool $editingHasBatches = false;

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

    public string $barcode = '';

    public ?int $printingId = null;
    public string $printCopies = '1';
    public string $printLayout = ProductLabelPdfService::LAYOUT_ROLL;
    public bool $printShowPrice = true;

    public array $labelSelection = [];

    public array $sheetCopies = [];

    public bool $sheetShowPrice = true;

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

    protected string $brandBeforeCreate = '';

    public function updatingBrand(string $value): void
    {
        if ($value === '__create__') {
            $this->brandBeforeCreate = $this->brand;
        }
    }

    public function updatedBrand(): void
    {
        if ($this->brand === '__create__') {
            $this->brand = $this->brandBeforeCreate;
            $this->dispatch('open-modal', name: 'quick-create-brand');
        }
    }

    #[On('brand-created')]
    public function onBrandCreated(string $name): void
    {
        $this->brand = $name;
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

    protected function report(): ProductReport
    {
        return new ProductReport($this->search, $this->typeFilter);
    }

    public function with(): array
    {
        $mainCategories = MainCategory::query()->orderBy('name')->get();
        $currentMainCategory = $mainCategories->firstWhere('slug', $this->type);

        return [
            'products' => $this->report()->query()
                ->latest()
                ->paginate(10),
            'mainCategories' => $mainCategories,
            'brands' => Brand::query()->orderBy('name')->get(),
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
            'canImportProducts' => Auth::user()->canImportProducts(),
            'barcodeEnabled' => Auth::user()->canUseBarcodes(),
            'whatsappEnabled' => (bool) Auth::user()->shop?->whatsapp_enabled,
            'barcodePreview' => $this->barcodePreview(),
            'missingBarcodeCount' => Auth::user()->canUseBarcodes() ? Product::whereNull('barcode')->count() : 0,
            'printingProduct' => $this->printingId ? Product::find($this->printingId) : null,
            'sheetProducts' => $this->labelSelection !== [] ? Product::whereIn('id', $this->selectedLabelIds())->orderBy('name')->get() : collect(),
        ];
    }

    protected function barcodePreview(): ?string
    {
        if (! Auth::user()->canUseBarcodes() || ! BarcodeService::isPrintable(trim($this->barcode))) {
            return null;
        }

        return BarcodeService::imageDataUri(trim($this->barcode));
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', name: 'product-form');
    }

    /**
     * Products Import is a separate opt-in capability (shops.import_enabled,
     * off by default) on top of ordinary Products access — this gate is
     * only the button's own convenience layer; the import component's own
     * actions (upload, preview, confirm, template download) each guard
     * themselves independently, since a tampered request could call them
     * directly without ever going through this method. Dispatches a
     * request event rather than 'open-modal' directly so the always-mounted
     * <livewire:products.import /> component resets its own state (any
     * leftover file/step from a previous import) before showing itself —
     * dispatching 'open-modal' straight from here would just reveal
     * whatever state it was last left in.
     */
    public function openImportModal(): void
    {
        abort_unless(Auth::user()->canImportProducts(), 403);

        $this->dispatch('request-open-product-import');
    }

    #[On('product-import-finished')]
    public function onProductImportFinished(): void
    {
        $this->resetPage();
    }

    public function openEdit(int $id): void
    {
        $product = Product::findOrFail($id);

        abort_if($product->type === 'sim', 403, 'Legacy SIM products cannot be edited.');

        $this->editingId = $product->id;
        $this->editingHasBatches = $product->hasBatches();
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
        $this->barcode = (string) ($product->barcode ?? '');

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'product-form');
    }

    public function save(): void
    {
        $this->barcode = trim($this->barcode);
        $this->validate($this->rules());

        if (! $this->editingId) {
            try {
                app(Entitlements::class)->ensureCanAdd(Auth::user()->shop, LimitKey::Products);
            } catch (UsageLimitReached $e) {
                $this->toastError($e->getMessage());

                return;
            }
        }

        $product = $this->editingId ? Product::findOrFail($this->editingId) : new Product;

        $product->fill([
            'type' => $this->type,
            'name' => $this->name,
            'stock_quantity' => $this->stock_quantity,
            'details' => $this->detailsForType(),
        ]);

        if (! $this->editingHasBatches) {
            $product->price = $this->price;
            $product->cost_price = $this->cost_price !== '' ? $this->cost_price : null;
        }

        if (Auth::user()->canUseBarcodes()) {
            $product->barcode = $this->barcode !== '' ? $this->barcode : null;
        }

        if ($this->image) {
            $product->image_path = $this->storeImage($this->image, 'products', $product->image_path);
        }

        try {
            $product->save();
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'barcode' => BarcodeService::duplicateMessageFor(Auth::user()->shop_id, $this->barcode, $this->editingId) ?? 'This barcode is already in use.',
            ]);
        }

        $this->toastSuccess($this->editingId ? 'Product updated.' : 'Product added.');
        $this->dispatch('close-modal', name: 'product-form');
        $this->resetForm();
    }

    public function generateBarcode(): void
    {
        abort_unless(Auth::user()->canUseBarcodes(), 403);

        $this->barcode = BarcodeService::generateUniqueFor(Auth::user()->shop_id, $this->editingId);
        $this->resetErrorBag('barcode');
    }

    public function runBulkBarcodeGeneration(): void
    {
        abort_unless(Auth::user()->canUseBarcodes(), 403);

        $result = BarcodeService::assignMissing(Auth::user()->shop_id);

        $this->toastSuccess($result['remaining'] > 0
            ? "Generated {$result['assigned']} barcodes. {$result['remaining']} products still need one — run this again to continue."
            : "Generated {$result['assigned']} barcodes. Every product now has one.");
    }

    public function generateBarcodeFor(int $id): void
    {
        abort_unless(Auth::user()->canUseBarcodes(), 403);

        $product = Product::findOrFail($id);

        if ($product->barcode === null || $product->barcode === '') {
            BarcodeService::assignToProduct($product->id);
        }

        $this->toastSuccess('Barcode generated. You can print the label now.');
    }

    public function openPrintLabel(int $id): void
    {
        abort_unless(Auth::user()->canUseBarcodes(), 403);

        $product = Product::findOrFail($id);

        $this->printingId = $product->id;
        $this->printCopies = '1';
        $this->printLayout = ProductLabelPdfService::LAYOUT_ROLL;
        $this->printShowPrice = true;
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'print-label-form');
    }

    public function closePrintLabel(): void
    {
        $this->dispatch('close-modal', name: 'print-label-form');
        $this->reset(['printingId', 'printCopies', 'printLayout', 'printShowPrice']);
    }

    public function printLabels(ProductLabelPdfService $labels): ?StreamedResponse
    {
        abort_unless(Auth::user()->canUseBarcodes(), 403);

        $this->validate([
            'printCopies' => ['required', 'integer', 'min:1', 'max:'.BarcodeService::MAX_LABEL_COPIES],
            'printLayout' => ['required', Rule::in([ProductLabelPdfService::LAYOUT_ROLL, ProductLabelPdfService::LAYOUT_A4])],
        ], [
            'printCopies.max' => 'You can print up to '.BarcodeService::MAX_LABEL_COPIES.' labels per request. Print in batches for more.',
        ]);

        $product = Product::findOrFail($this->printingId);

        if ($product->barcode === null || $product->barcode === '') {
            $this->toastError('This product has no barcode yet. Generate one first.');

            return null;
        }

        $this->dispatch('close-modal', name: 'print-label-form');

        return $labels->download(
            $product,
            (int) $this->printCopies,
            $this->printLayout,
            $this->printShowPrice,
            Auth::user()->shop->name,
        );
    }

    public function openLabelSheet(): void
    {
        abort_unless(Auth::user()->canUseBarcodes(), 403);

        $ids = $this->selectedLabelIds();

        if ($ids === []) {
            $this->toastError('Tick the products you want labels for first.');

            return;
        }

        if (count($ids) > self::MAX_SHEET_PRODUCTS) {
            $this->toastError('Select up to '.self::MAX_SHEET_PRODUCTS.' products per label sheet.');

            return;
        }

        $this->sheetCopies = collect($ids)->mapWithKeys(fn (int $id) => [$id => (string) ($this->sheetCopies[$id] ?? '1')])->all();
        $this->sheetShowPrice = true;
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'label-sheet-form');
    }

    public function generateBarcodesForSelection(): void
    {
        abort_unless(Auth::user()->canUseBarcodes(), 403);

        $generated = 0;

        foreach (Product::whereIn('id', $this->selectedLabelIds())->whereNull('barcode')->pluck('id') as $productId) {
            if (BarcodeService::assignToProduct($productId)) {
                $generated++;
            }
        }

        $this->toastSuccess("Generated {$generated} barcode(s).");
    }

    public function removeFromLabelSelection(int $id): void
    {
        $this->labelSelection = array_values(array_diff($this->selectedLabelIds(), [$id]));
        unset($this->sheetCopies[$id]);

        if ($this->labelSelection === []) {
            $this->dispatch('close-modal', name: 'label-sheet-form');
        }
    }

    public function clearLabelSelection(): void
    {
        $this->reset(['labelSelection', 'sheetCopies']);
    }

    public function printLabelSheet(ProductLabelPdfService $labels): ?StreamedResponse
    {
        abort_unless(Auth::user()->canUseBarcodes(), 403);

        $ids = $this->selectedLabelIds();

        $this->validate([
            'sheetCopies' => ['array'],
            'sheetCopies.*' => ['required', 'integer', 'min:1', 'max:'.BarcodeService::MAX_LABEL_COPIES],
        ], [], ['sheetCopies.*' => 'copies']);

        $products = Product::whereIn('id', $ids)->whereNotNull('barcode')->where('barcode', '!=', '')->orderBy('name')->get();

        if ($products->isEmpty()) {
            $this->toastError('None of the selected products has a barcode yet. Generate them first.');

            return null;
        }

        $total = $products->sum(fn (Product $product) => (int) ($this->sheetCopies[$product->id] ?? 1));

        if ($total > BarcodeService::MAX_LABEL_COPIES) {
            $this->addError('sheetCopies', 'That is '.$total.' labels — you can print up to '.BarcodeService::MAX_LABEL_COPIES.' per sheet request. Lower some copies or print in two batches.');

            return null;
        }

        $this->dispatch('close-modal', name: 'label-sheet-form');
        $this->reset(['labelSelection', 'sheetCopies']);

        return $labels->downloadSheet(
            $products->map(fn (Product $product) => ['product' => $product, 'copies' => (int) ($this->sheetCopiesFor($product->id))]),
            $this->sheetShowPrice,
            Auth::user()->shop->name,
        );
    }

    protected function sheetCopiesFor(int $productId): int
    {
        return max(1, (int) ($this->sheetCopies[$productId] ?? 1));
    }

    protected function selectedLabelIds(): array
    {
        return array_values(array_unique(array_map('intval', array_filter($this->labelSelection, fn ($id) => is_numeric($id)))));
    }

    public function toggleWhatsAppListing(int $id): void
    {
        abort_unless(Auth::user()->shop?->whatsapp_enabled, 403);

        $product = Product::findOrFail($id);
        $product->update(['whatsapp_listed' => ! $product->whatsapp_listed]);

        $this->toastSuccess($product->whatsapp_listed ? "{$product->name} is now available on WhatsApp." : "{$product->name} removed from WhatsApp.");
    }

    public function archive(int $id): void
    {
        $product = Product::findOrFail($id);

        if (! $product->canBeArchived()) {
            $this->toastError('Only products with no stock left can be archived.');

            return;
        }

        $product->update(['archived_at' => now(), 'whatsapp_listed' => false]);
        app(Entitlements::class)->forgetMeters(Auth::user()->shop);

        $this->toastSuccess("{$product->name} archived. It no longer counts towards your plan, and comes back automatically if you stock it again.");
    }

    public function restore(int $id): void
    {
        $product = Product::findOrFail($id);

        if (! $product->isArchived()) {
            return;
        }

        try {
            app(Entitlements::class)->ensureCanAdd(Auth::user()->shop, LimitKey::Products);
        } catch (UsageLimitReached $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $product->update(['archived_at' => null]);
        app(Entitlements::class)->forgetMeters(Auth::user()->shop);

        $this->toastSuccess("{$product->name} restored.");
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

    public function exportPdf(TableExportService $exportService): ?StreamedResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toPdf(
            'Products',
            Auth::user()->shop->name,
            $report->filtersSummary(),
            $report->tableHeaders(),
            $report->tableRows(forExcel: false),
        ));
    }

    public function exportExcel(TableExportService $exportService): ?BinaryFileResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toExcel(
            'Products',
            Auth::user()->shop->name,
            $report->filtersSummary(),
            $report->tableHeaders(),
            $report->tableRows(forExcel: true),
            $report->columnTypes(),
        ));
    }

    protected function rules(): array
    {
        return CreateProduct::rules(auth()->user()->shop_id, $this->type, $this->editingId);
    }

    protected function detailsForType(): array
    {
        return CreateProduct::detailsForType($this->type, $this->category, $this->brand, $this->model, $this->imei);
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editingId', 'editingHasBatches', 'name', 'image', 'existingImageUrl', 'price', 'cost_price',
            'brand', 'model', 'imei', 'category', 'barcode',
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

        <div class="flex items-center gap-2">
            <x-ui.export-dropdown />
            @if ($barcodeEnabled && $missingBarcodeCount > 0)
                <x-ui.button
                    type="button"
                    variant="secondary"
                    wire:click="runBulkBarcodeGeneration"
                    wire:confirm="Generate barcodes for up to {{ \App\Services\BarcodeService::BULK_RUN_LIMIT }} products that don't have one yet?"
                    class="shrink-0"
                >
                    Generate missing barcodes ({{ $missingBarcodeCount }})
                </x-ui.button>
            @endif
            @if ($canImportProducts)
                <x-ui.button type="button" variant="secondary" wire:click="openImportModal" class="shrink-0">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M7.5 7.5L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    Import
                </x-ui.button>
            @endif
            <x-ui.button wire:click="openCreate" class="shrink-0">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Add Product
            </x-ui.button>
        </div>
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
        @php
            $productHeaders = $barcodeEnabled
                ? ['Product', 'Category', 'Barcode', 'Price', 'Stock', '']
                : ['Product', 'Category', 'Price', 'Stock', ''];
        @endphp
        @if ($barcodeEnabled && $labelSelection !== [])
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm">
                <span class="font-medium text-brand-900">{{ count($labelSelection) }} {{ \Illuminate\Support\Str::plural('product', count($labelSelection)) }} selected for labels</span>
                <div class="flex items-center gap-2">
                    <x-ui.button size="sm" variant="ghost" wire:click="clearLabelSelection">Clear</x-ui.button>
                    <x-ui.button size="sm" wire:click="openLabelSheet">Print label sheet</x-ui.button>
                </div>
            </div>
        @endif

        <x-ui.table :headers="$productHeaders">
            @foreach ($products as $product)
                <x-ui.table-row wire:key="product-{{ $product->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">
                        <div class="flex items-center gap-3">
                            @if ($barcodeEnabled && $product->type !== 'sim')
                                <input type="checkbox" wire:model.live="labelSelection" value="{{ $product->id }}" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" aria-label="Select {{ $product->name }} for label printing">
                            @endif
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
                    @if ($barcodeEnabled)
                        <x-ui.table-cell class="font-mono text-xs text-slate-600">{{ $product->barcode ?? '—' }}</x-ui.table-cell>
                    @endif
                    <x-ui.table-cell>Rs {{ number_format($product->price, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($product->isArchived())
                            <x-ui.badge>Archived</x-ui.badge>
                        @elseif ($product->isOutOfStock())
                            <x-ui.badge variant="danger">Out of stock</x-ui.badge>
                        @elseif ($product->isLowStock())
                            <x-ui.badge variant="warning">Low · {{ $product->stock_quantity }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="success">{{ $product->stock_quantity }} in stock</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            @if ($barcodeEnabled && $product->type !== 'sim')
                                <x-ui.button size="sm" variant="ghost" wire:click="openPrintLabel({{ $product->id }})">
                                    Print Label
                                </x-ui.button>
                            @endif
                            @if ($whatsappEnabled && $product->type !== 'sim')
                                <x-ui.button size="sm" variant="ghost" wire:click="toggleWhatsAppListing({{ $product->id }})" @class(['text-emerald-700' => $product->whatsapp_listed]) title="{{ $product->whatsapp_listed ? 'Listed on WhatsApp — click to remove' : 'Not on WhatsApp — click to list' }}">
                                    {{ $product->whatsapp_listed ? 'On WhatsApp' : 'Add to WhatsApp' }}
                                </x-ui.button>
                            @endif
                            @if ($product->type !== 'sim')
                                <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $product->id }})">
                                    Edit
                                </x-ui.button>
                            @endif
                            @if ($product->isArchived())
                                <x-ui.button size="sm" variant="ghost" wire:click="restore({{ $product->id }})">Restore</x-ui.button>
                            @elseif ($product->canBeArchived())
                                <x-ui.button size="sm" variant="ghost" wire:click="archive({{ $product->id }})" title="Hide this out-of-stock product so it no longer counts towards your plan">Archive</x-ui.button>
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

    <x-ui.modal name="product-form" max-width="xl">
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
                            <x-ui.select wire:model.live="brand" id="brand">
                                <option value="">Select a brand</option>
                                <option value="__create__">+ New Brand</option>
                                @foreach ($brands as $option)
                                    <option value="{{ $option->name }}">{{ $option->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Model" name="model" for="model" help="Optional">
                            <x-ui.input wire:model="model" id="model" placeholder="e.g. Galaxy A15" />
                        </x-ui.field>
                    </div>
                @endif

                <div @class(['grid grid-cols-1 gap-4', 'sm:grid-cols-2' => $type === 'mobile'])>
                    @if ($type === 'mobile')
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
                </div>

                @if ($editingHasBatches)
                    <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                        Price now comes from this product's Stock In batches. To correct a price, edit the specific Stock In entry instead.
                    </p>
                @endif

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-ui.field label="Selling Price" name="price" for="price">
                        <x-ui.input wire:model="price" id="price" type="number" step="0.01" min="0" :disabled="$editingHasBatches" />
                    </x-ui.field>

                    <x-ui.field label="Cost Price" name="cost_price" for="cost_price" help="Optional">
                        <x-ui.input wire:model="cost_price" id="cost_price" type="number" step="0.01" min="0" :disabled="$editingHasBatches" />
                    </x-ui.field>

                    <x-ui.field label="Stock Qty" name="stock_quantity" for="stock_quantity">
                        <x-ui.input wire:model="stock_quantity" id="stock_quantity" type="number" step="1" min="0" />
                    </x-ui.field>
                </div>

                @if ($barcodeEnabled)
                    <x-ui.field label="Barcode" name="barcode" for="barcode" help="Optional — type or scan one, or generate a unique one">
                        <div class="flex gap-2">
                            <x-ui.input
                                wire:model="barcode"
                                id="barcode"
                                autocomplete="off"
                                class="font-mono"
                                x-on:keydown.enter.prevent
                            />
                            <x-ui.button type="button" variant="secondary" wire:click="generateBarcode" wire:loading.attr="disabled" wire:target="generateBarcode" class="shrink-0">
                                Generate
                            </x-ui.button>
                        </div>
                    </x-ui.field>

                    @if ($barcodePreview)
                        <div class="rounded-lg border border-slate-200 bg-white p-3">
                            <img src="{{ $barcodePreview }}" alt="Barcode {{ $barcode }}" class="h-16 max-w-full" />
                        </div>
                    @endif
                @endif
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

    <x-ui.modal name="print-label-form" max-width="sm">
        <form wire:submit="printLabels" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Print Label</h2>

            @if ($printingProduct)
                <p class="mt-1 text-sm text-slate-500">{{ $printingProduct->name }}</p>

                @if (! $printingProduct->barcode)
                    <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        This product has no barcode yet.
                        <div class="mt-3">
                            <x-ui.button type="button" variant="secondary" size="sm" wire:click="generateBarcodeFor({{ $printingProduct->id }})">
                                Generate barcode
                            </x-ui.button>
                        </div>
                    </div>
                @else
                    <div class="mt-5 space-y-5">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-ui.field label="Copies" name="printCopies" for="printCopies" help="Up to {{ \App\Services\BarcodeService::MAX_LABEL_COPIES }} per request">
                                <x-ui.input wire:model="printCopies" id="printCopies" type="number" min="1" max="{{ \App\Services\BarcodeService::MAX_LABEL_COPIES }}" step="1" />
                            </x-ui.field>

                            <x-ui.field label="Layout" name="printLayout" for="printLayout">
                                <x-ui.select wire:model="printLayout" id="printLayout">
                                    <option value="{{ \App\Services\ProductLabelPdfService::LAYOUT_ROLL }}">Single label roll (50 × 30 mm)</option>
                                    <option value="{{ \App\Services\ProductLabelPdfService::LAYOUT_A4 }}">A4 sheet (3 × 8 grid)</option>
                                </x-ui.select>
                            </x-ui.field>
                        </div>

                        <x-ui.checkbox wire:model="printShowPrice" label="Show selling price on the label" />
                    </div>
                @endif
            @endif

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closePrintLabel">
                    Cancel
                </x-ui.button>

                @if ($printingProduct && $printingProduct->barcode)
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="printLabels">
                        Download PDF
                    </x-ui.button>
                @endif
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="label-sheet-form" max-width="lg">
        <form wire:submit="printLabelSheet" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Print label sheet</h2>
            <p class="mt-1 text-sm text-slate-500">One A4 sheet (3 × 8 labels per page) with each product’s label repeated by its copies.</p>

            @php($missing = $sheetProducts->filter(fn ($p) => $p->barcode === null || $p->barcode === ''))

            @if ($missing->isNotEmpty())
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    <span>{{ $missing->count() }} selected {{ \Illuminate\Support\Str::plural('product', $missing->count()) }} {{ $missing->count() === 1 ? 'has' : 'have' }} no barcode and will be skipped.</span>
                    <button type="button" wire:click="generateBarcodesForSelection" class="font-semibold underline">Generate barcodes for {{ $missing->count() === 1 ? 'it' : 'them' }}</button>
                </div>
            @endif

            <ul class="mt-4 max-h-80 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
                @foreach ($sheetProducts as $sheetProduct)
                    <li class="flex items-center justify-between gap-3 px-3 py-2" wire:key="sheet-{{ $sheetProduct->id }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $sheetProduct->name }}</p>
                            <p class="font-mono text-xs text-slate-500">{{ $sheetProduct->barcode ?: 'No barcode — skipped' }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($sheetProduct->barcode)
                                <label class="sr-only" for="sheet-copies-{{ $sheetProduct->id }}">Copies of {{ $sheetProduct->name }}</label>
                                <x-ui.input wire:model="sheetCopies.{{ $sheetProduct->id }}" id="sheet-copies-{{ $sheetProduct->id }}" type="number" min="1" max="{{ \App\Services\BarcodeService::MAX_LABEL_COPIES }}" class="w-20 py-1.5 text-sm" />
                            @endif
                            <button type="button" wire:click="removeFromLabelSelection({{ $sheetProduct->id }})" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-red-600" aria-label="Remove {{ $sheetProduct->name }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
            <x-ui.error :messages="$errors->get('sheetCopies')" class="mt-2" />
            @foreach ($errors->get('sheetCopies.*') as $messages)
                <x-ui.error :messages="$messages" class="mt-1" />
            @endforeach

            <div class="mt-4">
                <x-ui.checkbox wire:model="sheetShowPrice" label="Show selling price on the labels" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'label-sheet-form' })">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="printLabelSheet" :disabled="$sheetProducts->every(fn ($p) => ! $p->barcode)">Download PDF</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <livewire:accessory-categories.quick-create :default-main-category-slug="$type" />
    <livewire:main-categories.quick-create />
    <livewire:brands.quick-create />
    @if ($canImportProducts)
        <livewire:products.import />
    @endif
</div>
