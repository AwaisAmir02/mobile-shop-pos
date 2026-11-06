<?php

use App\Actions\CreateProduct;
use App\Enums\LimitKey;
use App\Enums\QuantityUnit;
use App\Support\Quantity;
use App\Exceptions\UsageLimitReached;
use App\Livewire\Concerns\GuardsExportSize;
use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\UploadsImages;
use App\Models\AccessoryCategoryOption;
use App\Models\Brand;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\ProductGroup;
use App\ReportQueries\ProductReport;
use App\Services\BarcodeService;
use App\Services\Billing\Entitlements;
use App\Services\ProductGroupService;
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
    public string $unit = 'piece';
    public bool $unitLocked = false;
    public string $shelf_life_days = '';

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

    public string $variantLabel = '';
    public string $editingVariantGroupName = '';

    public array $expandedGroups = [];

    // The variant group form: 'create' a group, 'add' variants to one, or 'edit' its shared details.
    public string $groupFormMode = 'create';
    public ?int $groupFormId = null;
    public int $groupFormNonce = 0;
    public string $groupName = '';
    public string $groupType = '';
    public string $groupCategory = '';
    public string $groupUnit = 'piece';
    public bool $groupUnitLocked = false;
    public $groupImage = null;
    public ?string $groupExistingImageUrl = null;
    public string $groupSizes = '';
    public string $groupColours = '';
    public string $groupPrice = '';
    public string $groupCost = '';
    public string $attachSearch = '';
    /** @var array<int, string> product id => the label it will have in the group */
    public array $attachLabels = [];

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

        $products = $this->report()->query()
            ->oneRowPerGroup($this->report()->query())
            ->latest()
            ->paginate(10);

        return [
            'products' => $products,
            'groupRows' => $this->groupRows($products->getCollection()),
            'variantsEnabled' => $this->variantsEnabled(),
            'measuredEnabled' => $this->measuredEnabled(),
            'formUnit' => $this->formUnit(),
            'showUnitField' => $this->showUnitField(),
            'expiryEnabled' => (bool) Auth::user()->shop?->expiry_tracking_enabled,
            'groupTypeOptions' => $mainCategories->reject(fn (MainCategory $category) => in_array($category->slug, ProductGroup::EXCLUDED_TYPES, true))->values(),
            'groupCategoryOptions' => $this->groupCategoryOptions($mainCategories),
            'generatedLabels' => $this->generatedLabels(),
            'attachCandidates' => $this->attachCandidates(),
            'attachSelected' => $this->attachLabels !== [] ? Product::whereIn('id', array_keys($this->attachLabels))->get()->keyBy('id') : collect(),
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
            'whatsappEnabled' => (bool) Auth::user()->shop?->whatsapp_ordering_enabled,
            'barcodePreview' => $this->barcodePreview(),
            'missingBarcodeCount' => Auth::user()->canUseBarcodes() ? Product::whereNull('barcode')->count() : 0,
            'printingProduct' => $this->printingId ? Product::find($this->printingId) : null,
            'sheetProducts' => $this->labelSelection !== [] ? Product::whereIn('id', $this->selectedLabelIds())->orderBy('name')->get() : collect(),
        ];
    }

    protected function variantsEnabled(): bool
    {
        return (bool) Auth::user()->shop?->variants_enabled;
    }

    protected function measuredEnabled(): bool
    {
        return (bool) Auth::user()->shop?->measured_units_enabled;
    }

    /**
     * The unit this form is working in. The chosen one only counts when it's
     * actually open to change, so a crafted request can't set it otherwise.
     */
    protected function formUnit(): QuantityUnit
    {
        $current = $this->editingId ? Product::find($this->editingId) : null;
        $open = $this->measuredEnabled() && ! $this->unitLocked && ! $current?->isVariant() && ! in_array($this->type, QuantityUnit::PIECE_ONLY_TYPES, true);

        return $open ? QuantityUnit::fromValue($this->unit) : ($current?->unit ?? QuantityUnit::Piece);
    }

    protected function showUnitField(): bool
    {
        $current = $this->editingId ? Product::find($this->editingId) : null;

        if ($current?->isVariant() || in_array($this->type, QuantityUnit::PIECE_ONLY_TYPES, true)) {
            return false;
        }

        return $this->measuredEnabled();
    }

    /**
     * What each group on this page shows. A search keeps its groups open, so
     * a variant found by name or barcode is visible straight away.
     *
     * @return array<int, array<string, mixed>> keyed by group id
     */
    protected function groupRows($pageProducts): array
    {
        $ids = $pageProducts->pluck('product_group_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return ProductGroup::whereIn('id', $ids)->with('variants')->get()->mapWithKeys(function (ProductGroup $group) {
            $prices = Product::sellingPricesFor($group->variants);

            return [$group->id => [
                'group' => $group,
                'variants' => $group->variants,
                'prices' => $prices,
                'minPrice' => min($prices),
                'maxPrice' => max($prices),
                'stock' => Quantity::sum($group->variants->pluck('stock_quantity')),
                'listed' => $group->variants->contains('whatsapp_listed', true),
                'expanded' => $this->search !== '' || in_array($group->id, $this->expandedGroups, true),
            ]];
        })->all();
    }

    protected function groupCategoryOptions($mainCategories)
    {
        $main = $mainCategories->firstWhere('slug', $this->groupType);

        return $main ? AccessoryCategoryOption::query()->where('main_category_id', $main->id)->orderBy('name')->pluck('name') : collect();
    }

    /** @return array<int, string> */
    protected function generatedLabels(): array
    {
        return ProductGroupService::combineOptions(
            ProductGroupService::parseOptions($this->groupSizes),
            ProductGroupService::parseOptions($this->groupColours),
        );
    }

    protected function attachCandidates()
    {
        if (trim($this->attachSearch) === '') {
            return collect();
        }

        return Product::query()
            ->whereNull('product_group_id')
            ->whereNull('archived_at')
            ->where('unit', QuantityUnit::fromValue($this->groupUnit)->value)
            ->whereNotIn('type', ProductGroup::EXCLUDED_TYPES)
            ->whereNotIn('id', array_keys($this->attachLabels))
            ->where('name', 'like', '%'.trim($this->attachSearch).'%')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->filter(fn (Product $product) => $product->canHaveVariants())
            ->values();
    }

    public function toggleGroup(int $groupId): void
    {
        $this->expandedGroups = in_array($groupId, $this->expandedGroups, true)
            ? array_values(array_diff($this->expandedGroups, [$groupId]))
            : [...$this->expandedGroups, $groupId];
    }

    public function openCreateGroup(): void
    {
        abort_unless($this->variantsEnabled(), 403);

        $this->resetGroupForm();
        $this->dispatch('open-modal', name: 'variant-group-form');
    }

    public function openAddVariants(int $groupId): void
    {
        abort_unless($this->variantsEnabled(), 403);

        $group = ProductGroup::findOrFail($groupId);

        $this->resetGroupForm();
        $this->groupFormMode = 'add';
        $this->groupFormId = $group->id;
        $this->groupName = $group->name;
        $this->groupUnit = $group->unit->value;
        $this->dispatch('open-modal', name: 'variant-group-form');
    }

    public function openEditGroup(int $groupId): void
    {
        abort_unless($this->variantsEnabled(), 403);

        $group = ProductGroup::findOrFail($groupId);

        $this->resetGroupForm();
        $this->groupFormMode = 'edit';
        $this->groupFormId = $group->id;
        $this->groupName = $group->name;
        $this->groupType = $group->type;
        $this->groupCategory = (string) ($group->details['category'] ?? '');
        $this->groupUnit = $group->unit->value;
        $this->groupUnitLocked = app(ProductGroupService::class)->unitIsLocked($group);
        $this->groupExistingImageUrl = $group->imageUrl();
        $this->dispatch('open-modal', name: 'variant-group-form');
    }

    public function closeGroupForm(): void
    {
        $this->dispatch('close-modal', name: 'variant-group-form');
        $this->resetGroupForm();
    }

    public function updatedGroupType(): void
    {
        $this->groupCategory = '';
    }

    public function addAttach(int $productId): void
    {
        $product = Product::findOrFail($productId);

        $this->attachLabels[$product->id] = \Illuminate\Support\Str::limit($product->name, 100, '');
        $this->attachSearch = '';
    }

    public function removeAttach(int $productId): void
    {
        unset($this->attachLabels[$productId]);
    }

    public function saveGroup(ProductGroupService $groups): void
    {
        abort_unless($this->variantsEnabled(), 403);

        $this->groupFormMode === 'edit' ? $this->saveGroupDetails($groups) : $this->saveGroupVariants($groups);
    }

    protected function saveGroupDetails(ProductGroupService $groups): void
    {
        $group = ProductGroup::findOrFail($this->groupFormId);

        $this->validate($this->groupDetailRules($group->id));

        if ($this->groupImage) {
            $group->update(['image_path' => $this->storeImage($this->groupImage, 'products', $group->image_path)]);
        }

        $groups->updateGroup($group, $this->groupName, $this->groupType, $this->groupCategory, $this->measuredEnabled() ? QuantityUnit::fromValue($this->groupUnit) : null);

        $this->toastSuccess('Group updated.');
        $this->closeGroupForm();
    }

    protected function saveGroupVariants(ProductGroupService $groups): void
    {
        $group = $this->groupFormMode === 'add' ? ProductGroup::findOrFail($this->groupFormId) : null;
        $labels = $this->generatedLabels();

        $this->validate([
            ...($group ? [] : $this->groupDetailRules(null)),
            'groupPrice' => [$labels !== [] ? 'required' : 'nullable', 'numeric', 'min:0'],
            'groupCost' => ['nullable', 'numeric', 'min:0'],
            'attachLabels.*' => ['required', 'string', 'max:100'],
        ], [], ['groupPrice' => 'selling price', 'groupCost' => 'cost price', 'attachLabels.*' => 'variant label']);

        if ($labels === [] && $this->attachLabels === []) {
            throw ValidationException::withMessages(['groupSizes' => 'Enter at least one size or colour, or attach an existing product.']);
        }

        if (count($labels) > ProductGroupService::MAX_VARIANTS_PER_RUN) {
            throw ValidationException::withMessages(['groupSizes' => 'That makes '.count($labels).' variants. Add up to '.ProductGroupService::MAX_VARIANTS_PER_RUN.' at a time.']);
        }

        foreach ($labels as $label) {
            if (mb_strlen($label) > 100) {
                throw ValidationException::withMessages(['groupSizes' => "\"{$label}\" is too long. A variant label can be up to 100 characters."]);
            }
        }

        $attaching = array_map(fn ($label) => trim((string) $label), $this->attachLabels);

        if (count(array_unique(array_map('mb_strtolower', [...$labels, ...$attaching]))) !== count($labels) + count($attaching)) {
            throw ValidationException::withMessages(['attachLabels' => 'Each variant in a group needs its own label.']);
        }

        // Attaching a product uses its own place on the plan, so only generating alone can need a new one.
        $needsRoom = $attaching === [] && ($group === null || $groups->addingCountsTowardLimit($group));

        try {
            if ($needsRoom) {
                app(Entitlements::class)->ensureCanAdd(Auth::user()->shop, LimitKey::Products);
            }
        } catch (UsageLimitReached $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $imagePath = ! $group && $this->groupImage ? $this->storeImage($this->groupImage, 'products') : null;

        \Illuminate\Support\Facades\DB::transaction(function () use ($groups, $group, $labels, $attaching, $imagePath) {
            $group ??= $groups->createGroup(Auth::user()->shop_id, $this->groupName, $this->groupType, $this->groupCategory, $imagePath, $this->measuredEnabled() ? QuantityUnit::fromValue($this->groupUnit) : QuantityUnit::Piece);

            foreach ($attaching as $productId => $label) {
                $groups->attach($group, Product::findOrFail($productId), $label);
            }

            if ($labels !== []) {
                $groups->addVariants($group, $labels, $this->groupPrice, $this->groupCost !== '' ? $this->groupCost : null);
            }

            $this->expandedGroups = [...$this->expandedGroups, $group->id];
        });

        app(Entitlements::class)->forgetMeters(Auth::user()->shop);

        $this->toastSuccess($this->groupFormMode === 'add' ? 'Variants added.' : 'Variant group created.');
        $this->closeGroupForm();
    }

    protected function groupDetailRules(?int $ignoreGroupId): array
    {
        $shopId = Auth::user()->shop_id;
        $rules = CreateProduct::rules($shopId, $this->groupType);

        return [
            'groupName' => ['required', 'string', 'max:150', Rule::unique('product_groups', 'name')->where('shop_id', $shopId)->ignore($ignoreGroupId)],
            'groupType' => [...$rules['type'], Rule::notIn(ProductGroup::EXCLUDED_TYPES)],
            'groupCategory' => $rules['category'],
            'groupImage' => $rules['image'],
        ];
    }

    protected function resetGroupForm(): void
    {
        $this->reset([
            'groupFormMode', 'groupFormId', 'groupName', 'groupType', 'groupCategory', 'groupUnit', 'groupUnitLocked', 'groupImage', 'groupExistingImageUrl',
            'groupSizes', 'groupColours', 'groupPrice', 'groupCost', 'attachSearch', 'attachLabels',
        ]);
        $this->groupFormNonce++;
        $this->resetErrorBag();
    }

    public function ungroup(int $groupId, ProductGroupService $groups): void
    {
        $group = ProductGroup::findOrFail($groupId);

        try {
            $groups->ungroup($group);
        } catch (UsageLimitReached) {
            $this->toastError("Ungrouping {$group->name} would take you over your plan's product limit, because each variant would count separately.");

            return;
        }

        $this->toastSuccess("{$group->name} ungrouped. Each variant is now a separate product.");
    }

    public function removeFromGroup(int $productId, ProductGroupService $groups): void
    {
        $product = Product::findOrFail($productId);

        try {
            $groups->detach($product);
        } catch (UsageLimitReached) {
            $this->toastError("Taking {$product->name} out of its group would take you over your plan's product limit.");

            return;
        }

        $this->toastSuccess("{$product->name} is now a separate product.");
    }

    public function toggleGroupWhatsApp(int $groupId): void
    {
        abort_unless(Auth::user()->shop?->whatsapp_ordering_enabled, 403);

        $group = ProductGroup::findOrFail($groupId);

        if ($group->unit->allowsDecimals()) {
            $this->toastError('Items sold by '.$group->unit->symbol().' can\'t be ordered on WhatsApp yet.');

            return;
        }

        $listed = $group->variants()->where('whatsapp_listed', true)->exists();

        $group->variants()->whereNull('archived_at')->update(['whatsapp_listed' => ! $listed]);

        $this->toastSuccess($listed ? "{$group->name} removed from WhatsApp." : "{$group->name} is now available on WhatsApp.");
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
        $this->unit = $product->unit->value;
        $this->unitLocked = $product->unitIsLocked();
        $this->shelf_life_days = (string) ($product->shelf_life_days ?? '');

        $this->brand = $product->details['brand'] ?? '';
        $this->model = $product->details['model'] ?? '';
        $this->imei = $product->details['imei'] ?? '';
        $this->category = $product->details['category'] ?? '';
        $this->barcode = (string) ($product->barcode ?? '');
        $this->variantLabel = (string) ($product->variant_label ?? '');
        $this->editingVariantGroupName = (string) ($product->group?->name ?? '');

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'product-form');
    }

    public function save(): void
    {
        $this->barcode = trim($this->barcode);
        $this->variantLabel = trim($this->variantLabel);
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

        if ($product->isVariant()) {
            // Name comes from the group plus this label; category belongs to the group.
            $product->fill([
                'variant_label' => $this->variantLabel,
                'name' => ProductGroupService::fullName($product->group->name, $this->variantLabel),
                'stock_quantity' => $this->stock_quantity,
            ]);
        } else {
            $product->fill([
                'type' => $this->type,
                'name' => $this->name,
                'stock_quantity' => $this->stock_quantity,
                'unit' => $this->formUnit(),
                'details' => $this->detailsForType(),
            ]);
        }

        if (Auth::user()->shop?->expiry_tracking_enabled) {
            $product->shelf_life_days = $this->shelf_life_days !== '' ? (int) $this->shelf_life_days : null;
        }

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
        abort_unless(Auth::user()->shop?->whatsapp_ordering_enabled, 403);

        $product = Product::findOrFail($id);

        if ($product->unit->allowsDecimals()) {
            $this->toastError('Items sold by '.$product->unit->symbol().' can\'t be ordered on WhatsApp yet.');

            return;
        }

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

        $this->toastSuccess($product->isVariant()
            ? "{$product->name} archived. It comes back automatically if you stock it again."
            : "{$product->name} archived. It no longer counts towards your plan, and comes back automatically if you stock it again.");
    }

    public function restore(int $id): void
    {
        $product = Product::findOrFail($id);

        if (! $product->isArchived()) {
            return;
        }

        try {
            if (app(ProductGroupService::class)->restoringCountsTowardLimit($product)) {
                app(Entitlements::class)->ensureCanAdd(Auth::user()->shop, LimitKey::Products);
            }
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
        $groupId = $product->product_group_id;
        $this->deleteImage($product->image_path);
        $product->delete();
        app(ProductGroupService::class)->deleteIfEmpty($groupId);

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
        $rules = CreateProduct::rules(auth()->user()->shop_id, $this->type, $this->editingId, $this->formUnit());
        $rules['shelf_life_days'] = ['nullable', 'integer', 'min:1', 'max:3650'];
        $groupId = $this->editingId ? Product::whereKey($this->editingId)->value('product_group_id') : null;

        if ($groupId === null) {
            return $rules;
        }

        // A variant's name, category and type belong to its group.
        unset($rules['type'], $rules['name'], $rules['category']);

        return $rules + [
            'variantLabel' => ['required', 'string', 'max:100', Rule::unique('products', 'variant_label')->where('product_group_id', $groupId)->ignore($this->editingId)],
        ];
    }

    protected function detailsForType(): array
    {
        return CreateProduct::detailsForType($this->type, $this->category, $this->brand, $this->model, $this->imei);
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editingId', 'editingHasBatches', 'unit', 'unitLocked', 'shelf_life_days', 'name', 'image', 'existingImageUrl', 'price', 'cost_price',
            'brand', 'model', 'imei', 'category', 'barcode', 'variantLabel', 'editingVariantGroupName',
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

    <x-billing.limit-notice limit="products" />

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
            @if ($variantsEnabled)
                <x-ui.button type="button" variant="secondary" wire:click="openCreateGroup" class="shrink-0">Add Variant Group</x-ui.button>
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
                @php($groupRow = $product->product_group_id !== null ? ($groupRows[$product->product_group_id] ?? null) : null)
                @if ($groupRow)
                    <x-ui.table-row wire:key="group-{{ $product->product_group_id }}" class="bg-slate-50/60">
                        <x-ui.table-cell class="font-medium text-slate-900">
                            <div class="flex items-center gap-3">
                                <button type="button" wire:click="toggleGroup({{ $product->product_group_id }})" class="rounded p-1 text-slate-500 hover:bg-slate-100" aria-expanded="{{ $groupRow['expanded'] ? 'true' : 'false' }}" aria-label="{{ $groupRow['expanded'] ? 'Hide' : 'Show' }} variants of {{ $groupRow['group']->name }}">
                                    <svg @class(['h-4 w-4 transition-transform', 'rotate-90' => $groupRow['expanded']]) fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" /></svg>
                                </button>
                                <x-ui.thumbnail :src="$groupRow['group']->imageUrl()" :label="$groupRow['group']->name" />
                                <span>
                                    {{ $groupRow['group']->name }}
                                    <span class="block text-xs font-normal text-slate-500">{{ $groupRow['variants']->count() }} {{ \Illuminate\Support\Str::plural('variant', $groupRow['variants']->count()) }}</span>
                                </span>
                            </div>
                        </x-ui.table-cell>
                        <x-ui.table-cell>
                            <div class="flex flex-col">
                                <span class="text-slate-700">{{ $product->typeLabel() }}</span>
                                @if ($line = $product->summaryLine())
                                    <span class="text-xs text-slate-400">{{ $line }}</span>
                                @endif
                            </div>
                        </x-ui.table-cell>
                        @if ($barcodeEnabled)
                            <x-ui.table-cell class="text-xs text-slate-400">—</x-ui.table-cell>
                        @endif
                        <x-ui.table-cell>{{ $groupRow['maxPrice'] > $groupRow['minPrice'] ? 'From ' : '' }}Rs {{ number_format($groupRow['minPrice'], 2) }}{{ $groupRow['group']->unit->perSuffix() }}</x-ui.table-cell>
                        <x-ui.table-cell>
                            <x-ui.badge :variant="$groupRow['stock'] > 0 ? 'success' : 'danger'">{{ $groupRow['stock'] > 0 ? $groupRow['group']->unit->amount($groupRow['stock']).' in stock' : 'Out of stock' }}</x-ui.badge>
                        </x-ui.table-cell>
                        <x-ui.table-cell align="right">
                            <div class="flex w-72 flex-wrap justify-start gap-2">
                                @if ($whatsappEnabled && ! $groupRow['group']->unit->allowsDecimals())
                                    <x-ui.button size="sm" variant="ghost" wire:click="toggleGroupWhatsApp({{ $product->product_group_id }})" @class(['text-emerald-700' => $groupRow['listed']])>
                                        {{ $groupRow['listed'] ? 'On WhatsApp' : 'Add to WhatsApp' }}
                                    </x-ui.button>
                                @endif
                                @if ($variantsEnabled)
                                    <x-ui.button size="sm" variant="ghost" wire:click="openAddVariants({{ $product->product_group_id }})">Add variants</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="openEditGroup({{ $product->product_group_id }})">Edit group</x-ui.button>
                                @endif
                                <x-ui.button size="sm" variant="ghost" wire:click="ungroup({{ $product->product_group_id }})" wire:confirm="Ungroup {{ $groupRow['group']->name }}? Each variant stays as a separate product with its stock and barcode.">Ungroup</x-ui.button>
                            </div>
                        </x-ui.table-cell>
                    </x-ui.table-row>
                    @if ($groupRow['expanded'])
                        @foreach ($groupRow['variants'] as $variant)
                            <x-ui.table-row wire:key="product-{{ $variant->id }}">
                                <x-ui.table-cell class="font-medium text-slate-900">
                                    <div class="flex items-center gap-3 pl-9">
                                        @if ($barcodeEnabled)
                                            <input type="checkbox" wire:model.live="labelSelection" value="{{ $variant->id }}" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" aria-label="Select {{ $variant->name }} for label printing">
                                        @endif
                                        {{ $variant->variant_label }}
                                    </div>
                                </x-ui.table-cell>
                                <x-ui.table-cell></x-ui.table-cell>
                                @if ($barcodeEnabled)
                                    <x-ui.table-cell class="font-mono text-xs text-slate-600">{{ $variant->barcode ?? '—' }}</x-ui.table-cell>
                                @endif
                                <x-ui.table-cell>Rs {{ number_format($groupRow['prices'][$variant->id], 2) }}{{ $variant->unit->perSuffix() }}</x-ui.table-cell>
                                <x-ui.table-cell><x-products.stock-badge :product="$variant" /></x-ui.table-cell>
                                <x-ui.table-cell align="right">
                                    <x-products.row-actions :product="$variant" :barcode-enabled="$barcodeEnabled" :whatsapp-enabled="$whatsappEnabled" />
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @endforeach
                    @endif
                    @continue
                @endif
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
                    <x-ui.table-cell>Rs {{ number_format($product->price, 2) }}{{ $product->unit->perSuffix() }}</x-ui.table-cell>
                    <x-ui.table-cell><x-products.stock-badge :product="$product" /></x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <x-products.row-actions :product="$product" :barcode-enabled="$barcodeEnabled" :whatsapp-enabled="$whatsappEnabled" />
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
                @if ($editingVariantGroupName !== '')
                    <div class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        Variant of <span class="font-semibold text-slate-900">{{ $editingVariantGroupName }}</span>. Its name and category come from the group.
                    </div>

                    <x-ui.field label="Variant Label" name="variantLabel" for="variantLabel" help="e.g. M / Red">
                        <x-ui.input wire:model="variantLabel" id="variantLabel" />
                    </x-ui.field>
                @else
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
                @endif

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

                @if ($editingVariantGroupName === '')
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
                @endif

                @if ($showUnitField)
                    @if ($unitLocked)
                        <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                            Sold by <span class="font-medium text-slate-700">{{ $formUnit->label() }}</span>. Locked because this product already has stock-in or sales records.
                        </p>
                    @else
                        <x-ui.field label="Sold By" name="unit" for="unit" help="Piece for whole items. The others take quantities like 1.5.">
                            <div wire:key="unit-{{ $editingId ?? 'new' }}">
                                <x-ui.select wire:model.live="unit" id="unit">
                                    @foreach (\App\Enums\QuantityUnit::cases() as $unitOption)
                                        <option value="{{ $unitOption->value }}">{{ $unitOption->label() }}</option>
                                    @endforeach
                                </x-ui.select>
                            </div>
                        </x-ui.field>
                    @endif
                @endif

                @if ($expiryEnabled)
                    <x-ui.field label="Shelf Life (days)" name="shelf_life_days" for="shelf_life_days" help="Optional. Pre-fills the expiry date on Stock In">
                        <x-ui.input wire:model="shelf_life_days" id="shelf_life_days" type="number" min="1" max="3650" step="1" />
                    </x-ui.field>
                @endif

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

                    <x-ui.field :label="$formUnit->allowsDecimals() ? 'Stock Qty ('.$formUnit->symbol().')' : 'Stock Qty'" name="stock_quantity" for="stock_quantity">
                        <x-ui.input wire:model="stock_quantity" id="stock_quantity" type="number" :step="$formUnit->inputStep()" min="0" />
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

    @if ($variantsEnabled)
    <x-ui.modal name="variant-group-form" max-width="2xl">
        <form wire:submit="saveGroup" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ ['create' => 'Add Variant Group', 'add' => 'Add Variants', 'edit' => 'Edit Group'][$groupFormMode] ?? 'Variant Group' }}
            </h2>
            <p class="mt-1 text-sm text-slate-500">
                @if ($groupFormMode === 'add')
                    Adding to <span class="font-medium text-slate-700">{{ $groupName }}</span>.
                @elseif ($groupFormMode === 'edit')
                    Changes apply to every variant. Past sales keep the name they were sold under.
                @else
                    One product in several sizes or colours. Each variant has its own stock, barcode and price, and the group counts as one product on your plan.
                @endif
            </p>

            <div class="mt-5 space-y-5">
                @if ($groupFormMode !== 'add')
                    <x-ui.field label="Group Name" name="groupName" for="groupName" help="e.g. Cotton Shirt">
                        <x-ui.input wire:model="groupName" id="groupName" />
                    </x-ui.field>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Main Category" name="groupType" for="groupType" help="Not available for mobile phones or SIMs">
                            <div wire:key="group-type-{{ $groupFormNonce }}">
                                <x-ui.select wire:model.live="groupType" id="groupType">
                                    <option value="">Select a category</option>
                                    @foreach ($groupTypeOptions as $option)
                                        <option value="{{ $option->slug }}">{{ $option->name }}</option>
                                    @endforeach
                                </x-ui.select>
                            </div>
                        </x-ui.field>

                        <x-ui.field label="Sub-Category" name="groupCategory" for="groupCategory" :help="$groupType === 'accessory' ? null : 'Optional'">
                            <div wire:key="group-category-{{ $groupFormNonce }}-{{ $groupType }}">
                                <x-ui.select wire:model="groupCategory" id="groupCategory">
                                    <option value="">Select a sub-category</option>
                                    @foreach ($groupCategoryOptions as $option)
                                        <option value="{{ $option }}">{{ $option }}</option>
                                    @endforeach
                                </x-ui.select>
                            </div>
                        </x-ui.field>
                    </div>

                    @if ($measuredEnabled)
                        @if ($groupUnitLocked)
                            <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                                Sold by <span class="font-medium text-slate-700">{{ \App\Enums\QuantityUnit::fromValue($groupUnit)->label() }}</span>. Locked because a variant already has stock-in or sales records.
                            </p>
                        @else
                            <x-ui.field label="Sold By" name="groupUnit" for="groupUnit" help="Every variant in a group shares one unit">
                                <div wire:key="group-unit-{{ $groupFormNonce }}">
                                    <x-ui.select wire:model.live="groupUnit" id="groupUnit">
                                        @foreach (\App\Enums\QuantityUnit::cases() as $unitOption)
                                            <option value="{{ $unitOption->value }}">{{ $unitOption->label() }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </div>
                            </x-ui.field>
                        @endif
                    @endif

                    <x-ui.field label="Image" name="groupImage" for="groupImage" help="Optional — used by any variant without its own photo">
                        <x-ui.file-input wire:model="groupImage" id="groupImage" :preview="$this->previewUrl($groupImage, $groupExistingImageUrl)" />
                    </x-ui.field>
                @endif

                @if ($groupFormMode !== 'edit')
                    <div class="rounded-lg border border-slate-200 p-4">
                        <p class="text-sm font-medium text-slate-700">Generate variants</p>
                        <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-ui.field label="Sizes" name="groupSizes" for="groupSizes" help="Comma-separated, e.g. S, M, L">
                                <x-ui.input wire:model.live.debounce.300ms="groupSizes" id="groupSizes" />
                            </x-ui.field>
                            <x-ui.field label="Colours" name="groupColours" for="groupColours" help="Comma-separated, e.g. Red, Blue">
                                <x-ui.input wire:model.live.debounce.300ms="groupColours" id="groupColours" />
                            </x-ui.field>
                            <x-ui.field label="Selling Price" name="groupPrice" for="groupPrice" help="Each variant starts here; change any one later">
                                <x-ui.input wire:model="groupPrice" id="groupPrice" type="number" step="0.01" min="0" />
                            </x-ui.field>
                            <x-ui.field label="Cost Price" name="groupCost" for="groupCost" help="Optional">
                                <x-ui.input wire:model="groupCost" id="groupCost" type="number" step="0.01" min="0" />
                            </x-ui.field>
                        </div>

                        @if ($generatedLabels !== [])
                            <p class="mt-3 text-xs font-medium text-slate-500">{{ count($generatedLabels) }} {{ \Illuminate\Support\Str::plural('variant', count($generatedLabels)) }} to add, with no stock yet. Add stock through Stock In.</p>
                            <div class="mt-2 flex max-h-28 flex-wrap gap-1.5 overflow-y-auto">
                                @foreach ($generatedLabels as $label)
                                    <x-ui.badge wire:key="generated-{{ $loop->index }}">{{ $label }}</x-ui.badge>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="rounded-lg border border-slate-200 p-4">
                        <p class="text-sm font-medium text-slate-700">Attach existing products</p>
                        <p class="mt-1 text-xs text-slate-500">They keep their stock, batches, barcode and price, and are renamed to the group name plus the label below.</p>

                        <div class="mt-3">
                            <x-ui.input wire:model.live.debounce.300ms="attachSearch" type="search" placeholder="Search your products…" aria-label="Search products to attach" autocomplete="off" />
                        </div>

                        @if ($attachCandidates->isNotEmpty())
                            <div class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200">
                                @foreach ($attachCandidates as $candidate)
                                    <button type="button" wire:key="attach-candidate-{{ $candidate->id }}" wire:click="addAttach({{ $candidate->id }})" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50">
                                        <span class="font-medium text-slate-900">{{ $candidate->name }}</span>
                                        <span class="text-xs text-slate-400">{{ $candidate->stockLabel() }} in stock</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        @foreach ($attachSelected as $selected)
                            <div class="mt-3 flex items-end gap-2" wire:key="attach-selected-{{ $selected->id }}">
                                <div class="flex-1">
                                    <x-ui.field :label="'Label for '.$selected->name" name="attachLabels.{{ $selected->id }}" for="attach-label-{{ $selected->id }}">
                                        <x-ui.input wire:model="attachLabels.{{ $selected->id }}" id="attach-label-{{ $selected->id }}" placeholder="e.g. M / Red" />
                                    </x-ui.field>
                                </div>
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="removeAttach({{ $selected->id }})">Remove</x-ui.button>
                            </div>
                        @endforeach
                        <x-ui.error :messages="$errors->get('attachLabels')" class="mt-2" />
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeGroupForm">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveGroup">
                    {{ ['create' => 'Create Group', 'add' => 'Add Variants', 'edit' => 'Save Changes'][$groupFormMode] ?? 'Save' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
    @endif

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
