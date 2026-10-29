<?php

use App\Actions\CreateAccessoryCategory;
use App\Actions\CreateBillCategory;
use App\Actions\CreateBillProvider;
use App\Actions\CreateServiceCategory;
use App\Actions\CreateServiceItem;
use App\Enums\ShopScreen;
use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\UploadsImages;
use App\Models\AccessoryCategoryOption;
use App\Models\BalanceLoad;
use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Models\Brand;
use App\Models\MainCategory;
use App\Models\Network;
use App\Models\Product;
use App\Models\Role;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\ServicePayment;
use App\Models\ShopAccount;
use App\Models\WalletLoad;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Settings')] class extends Component
{
    use Toasts, UploadsImages, WithFileUploads;

    #[Url]
    public string $tab = 'profile';

    // Main Categories
    public ?int $mainCategoryEditingId = null;
    public string $mainCategoryName = '';

    // Sub-Categories (formerly "Accessory Categories")
    public ?int $accessoryCategoryEditingId = null;
    public string $accessoryCategoryName = '';
    public string $accessoryCategoryMainCategoryId = '';
    public $accessoryCategoryImage = null;
    public ?string $accessoryCategoryExistingImageUrl = null;

    // Brands
    public ?int $brandEditingId = null;
    public string $brandName = '';

    // Networks
    public ?int $networkEditingId = null;
    public string $networkName = '';
    public string $networkColor = '#049669';
    public $networkImage = null;
    public ?string $networkExistingImageUrl = null;

    // Shop Accounts
    public ?int $shopAccountEditingId = null;
    public string $shopAccountName = '';
    public string $shopAccountProviderType = '';

    // Bill Categories
    public ?int $billCategoryEditingId = null;
    public string $billCategoryName = '';

    // Bill Providers
    public ?int $billProviderEditingId = null;
    public string $billProviderName = '';
    public string $billProviderCategoryId = '';
    public string $billProviderRegion = '';

    // Service Categories
    public ?int $serviceCategoryEditingId = null;
    public string $serviceCategoryName = '';

    // Service Items
    public ?int $serviceItemEditingId = null;
    public string $serviceItemName = '';
    public string $serviceItemCategoryId = '';

    // Roles
    public ?int $roleEditingId = null;
    public string $roleName = '';
    public array $rolePermissions = [];

    // Commission Percentages
    public string $simSaleCommissionPercent = '';
    public string $balanceLoadCommissionPercent = '';
    public string $walletLoadCommissionPercent = '';
    public string $billsCommissionPercent = '';
    public string $nadraVerificationCommissionPercent = '';

    // Branding
    public $brandingLogo = null;
    public ?string $brandingExistingLogoUrl = null;
    public string $brandingDisplayName = '';
    public string $brandingAddress = '';

    public function mount(): void
    {
        MainCategory::ensureDefaultsExist();

        if (Auth::user()->shop?->services_enabled) {
            ServiceCategory::ensureDefaultsExist();
        }

        $accessible = $this->accessibleTabs();

        if (! in_array($this->tab, $accessible, true)) {
            $this->tab = $accessible[0] ?? 'profile';
        }

        $shop = Auth::user()->shop;
        $this->simSaleCommissionPercent = $shop?->sim_sale_commission_percent !== null ? (string) $shop->sim_sale_commission_percent : '';
        $this->balanceLoadCommissionPercent = $shop?->balance_load_commission_percent !== null ? (string) $shop->balance_load_commission_percent : '';
        $this->walletLoadCommissionPercent = $shop?->wallet_load_commission_percent !== null ? (string) $shop->wallet_load_commission_percent : '';
        $this->billsCommissionPercent = $shop?->bills_commission_percent !== null ? (string) $shop->bills_commission_percent : '';
        $this->nadraVerificationCommissionPercent = $shop?->nadra_verification_commission_percent !== null ? (string) $shop->nadra_verification_commission_percent : '';
        $this->brandingDisplayName = $shop?->invoice_display_name ?? '';
        $this->brandingAddress = $shop?->invoice_address ?? '';
        $this->brandingExistingLogoUrl = $shop?->logoUrl();
    }

    /**
     * The settings.index route accepts several screen permissions (it now
     * hosts many feature sets), so each tab checks its own specific
     * permission rather than assuming the page gate covers it. Profile has
     * no permission requirement, matching the standalone /profile route
     * it mirrors.
     */
    protected function accessibleTabs(): array
    {
        $tabs = [];

        if (Auth::user()->hasAccessTo('settings-categories')) {
            $tabs[] = 'accessory-categories';
        }

        if (Auth::user()->hasAccessTo('settings-brands')) {
            $tabs[] = 'brands';
        }

        if (Auth::user()->hasAccessTo('settings-networks')) {
            $tabs[] = 'networks';
        }

        // Branding is gated twice: the shop must have it turned on by Super
        // Admin (shops.branding_enabled — opt-in, off by default, mirroring
        // barcode_enabled/import_enabled), AND the user needs the ordinary
        // settings-branding permission. A shop without it enabled never
        // sees this tab at all, regardless of role.
        if (Auth::user()->shop?->branding_enabled && Auth::user()->hasAccessTo('settings-branding')) {
            $tabs[] = 'branding';
        }

        if (Auth::user()->hasAccessTo('settings-bill-config')) {
            $tabs[] = 'bills';
        }

        // Also gated twice, same as Branding: shops.services_enabled (opt-in,
        // off by default) plus the ordinary settings-services permission.
        if (Auth::user()->shop?->services_enabled && Auth::user()->hasAccessTo('settings-services')) {
            $tabs[] = 'services';
        }

        if ((Auth::user()->shop?->whatsapp_enabled || Auth::user()->shop?->social_enabled || Auth::user()->shop?->ads_enabled) && Auth::user()->hasAccessTo('settings-integrations')) {
            $tabs[] = 'integrations';
        }

        if (Auth::user()->hasAccessTo('settings-percentage')) {
            $tabs[] = 'percentage';
        }

        if (Auth::user()->hasAccessTo('shop-accounts')) {
            $tabs[] = 'shop-accounts';
        }

        if (Auth::user()->hasAccessTo('users')) {
            $tabs[] = 'roles';
        }

        if (Auth::user()->is_owner) {
            $tabs[] = 'modules';
        }

        // Profile has no permission requirement (matching the standalone
        // /profile route), so it's always available — last, since it's a
        // fallback capability everyone has, not the reason most people
        // land on this page.
        $tabs[] = 'profile';

        return $tabs;
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, $this->accessibleTabs(), true)) {
            $this->tab = $tab;
        }
    }

    public function with(): array
    {
        $accessible = $this->accessibleTabs();

        return [
            'accessibleTabs' => $accessible,
            'mainCategories' => in_array('accessory-categories', $accessible, true)
                ? MainCategory::query()->withCount('subCategories')->orderBy('name')->get()
                : collect(),
            'accessoryCategories' => in_array('accessory-categories', $accessible, true)
                ? AccessoryCategoryOption::query()->with('mainCategory')->orderBy('name')->get()
                : collect(),
            'brands' => in_array('brands', $accessible, true)
                ? Brand::query()->orderBy('name')->get()
                : collect(),
            'networks' => in_array('networks', $accessible, true)
                ? Network::query()->orderBy('name')->get()
                : collect(),
            'shopAccounts' => in_array('shop-accounts', $accessible, true)
                ? ShopAccount::query()
                    ->withSum(['walletLoads as wallet_cash_in_sum_amount' => fn ($query) => $query->where('direction', 'cash_in')], 'amount')
                    ->withSum(['walletLoads as wallet_cash_out_sum_amount' => fn ($query) => $query->where('direction', 'cash_out')], 'amount')
                    ->withSum('billPayments', 'amount')
                    ->orderBy('name')
                    ->get()
                : collect(),
            'billCategories' => in_array('bills', $accessible, true)
                ? BillCategory::query()->withCount('billProviders')->orderBy('name')->get()
                : collect(),
            'billProviders' => in_array('bills', $accessible, true)
                ? BillProvider::query()->with('billCategory')->orderBy('name')->get()
                : collect(),
            'serviceCategories' => in_array('services', $accessible, true)
                ? ServiceCategory::query()->withCount('serviceItems')->orderBy('name')->get()
                : collect(),
            'serviceItems' => in_array('services', $accessible, true)
                ? ServiceItem::query()->with('serviceCategory')->orderBy('name')->get()
                : collect(),
            'roles' => in_array('roles', $accessible, true)
                ? Role::query()->withCount('users')->orderBy('name')->get()
                : collect(),
            'screens' => ShopScreen::cases(),
            'groupedScreens' => ShopScreen::grouped(),
        ];
    }

    // ── Main Categories ──────────────────────────────────────────────

    public function openMainCategoryCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-categories'), 403);

        $this->reset(['mainCategoryEditingId', 'mainCategoryName']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'main-category-form');
    }

    public function openMainCategoryEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-categories'), 403);

        $category = MainCategory::findOrFail($id);

        $this->mainCategoryEditingId = $category->id;
        $this->mainCategoryName = $category->name;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'main-category-form');
    }

    public function saveMainCategory(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-categories'), 403);

        $this->validate([
            'mainCategoryName' => ['required', 'string', 'max:255', Rule::unique('main_categories', 'name')->where('shop_id', Auth::user()->shop_id)->ignore($this->mainCategoryEditingId)],
        ]);

        if ($this->mainCategoryEditingId) {
            $category = MainCategory::findOrFail($this->mainCategoryEditingId);
            $category->name = $this->mainCategoryName;
            $category->save();
        } else {
            MainCategory::create([
                'name' => $this->mainCategoryName,
                'slug' => MainCategory::uniqueSlugFor($this->mainCategoryName),
            ]);
        }

        $this->toastSuccess($this->mainCategoryEditingId ? 'Main category updated.' : 'Main category added.');
        $this->dispatch('close-modal', name: 'main-category-form');
        $this->reset(['mainCategoryEditingId', 'mainCategoryName']);
    }

    public function deleteMainCategory(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-categories'), 403);

        $category = MainCategory::findOrFail($id);

        if ($category->is_builtin) {
            $this->toastError("\"{$category->name}\" is a built-in category and cannot be deleted.");

            return;
        }

        if (Product::where('type', $category->slug)->exists()) {
            $this->toastError("Cannot delete \"{$category->name}\" — it's used on existing products.");

            return;
        }

        if (AccessoryCategoryOption::where('main_category_id', $category->id)->exists()) {
            $this->toastError("Cannot delete \"{$category->name}\" — it has sub-categories under it. Delete those first.");

            return;
        }

        $category->delete();
        $this->toastSuccess('Main category deleted.');
    }

    public function closeMainCategoryForm(): void
    {
        $this->dispatch('close-modal', name: 'main-category-form');
        $this->reset(['mainCategoryEditingId', 'mainCategoryName']);
    }

    // ── Sub-Categories ───────────────────────────────────────────────

    public function openAccessoryCategoryCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-categories'), 403);

        $this->reset(['accessoryCategoryEditingId', 'accessoryCategoryName', 'accessoryCategoryImage', 'accessoryCategoryExistingImageUrl']);
        $this->accessoryCategoryMainCategoryId = (string) (MainCategory::query()->orderBy('name')->value('id') ?? '');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'accessory-category-form');
    }

    public function openAccessoryCategoryEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-categories'), 403);

        $option = AccessoryCategoryOption::findOrFail($id);

        $this->accessoryCategoryEditingId = $option->id;
        $this->accessoryCategoryName = $option->name;
        $this->accessoryCategoryMainCategoryId = (string) $option->main_category_id;
        $this->accessoryCategoryImage = null;
        $this->accessoryCategoryExistingImageUrl = $option->imageUrl();

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'accessory-category-form');
    }

    public function saveAccessoryCategory(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-categories'), 403);

        $this->validate([
            'accessoryCategoryName' => ['required', 'string', 'max:255', Rule::unique('accessory_category_options', 'name')->where('shop_id', Auth::user()->shop_id)->ignore($this->accessoryCategoryEditingId)],
            'accessoryCategoryMainCategoryId' => ['required', 'integer', Rule::exists('main_categories', 'id')->where('shop_id', Auth::user()->shop_id)],
            'accessoryCategoryImage' => ['nullable', 'image', 'max:2048'],
        ]);

        $option = $this->accessoryCategoryEditingId
            ? AccessoryCategoryOption::findOrFail($this->accessoryCategoryEditingId)
            : CreateAccessoryCategory::handle($this->accessoryCategoryName, (int) $this->accessoryCategoryMainCategoryId);

        $option->name = $this->accessoryCategoryName;
        $option->main_category_id = $this->accessoryCategoryMainCategoryId;

        if ($this->accessoryCategoryImage) {
            $option->image_path = $this->storeImage($this->accessoryCategoryImage, 'accessory-categories', $option->image_path);
        }

        $option->save();

        $this->toastSuccess($this->accessoryCategoryEditingId ? 'Sub-category updated.' : 'Sub-category added.');
        $this->dispatch('close-modal', name: 'accessory-category-form');
        $this->reset(['accessoryCategoryEditingId', 'accessoryCategoryName', 'accessoryCategoryMainCategoryId', 'accessoryCategoryImage', 'accessoryCategoryExistingImageUrl']);
    }

    public function deleteAccessoryCategory(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-categories'), 403);

        $option = AccessoryCategoryOption::findOrFail($id);

        if (Product::where('details->category', $option->name)->exists()) {
            $this->toastError("Cannot delete \"{$option->name}\" — it's used on existing products.");

            return;
        }

        $this->deleteImage($option->image_path);
        $option->delete();
        $this->toastSuccess('Sub-category deleted.');
    }

    public function closeAccessoryCategoryForm(): void
    {
        $this->dispatch('close-modal', name: 'accessory-category-form');
        $this->reset(['accessoryCategoryEditingId', 'accessoryCategoryName', 'accessoryCategoryMainCategoryId', 'accessoryCategoryImage', 'accessoryCategoryExistingImageUrl']);
    }

    // ── Brands ───────────────────────────────────────────────────────

    public function openBrandCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-brands'), 403);

        $this->reset(['brandEditingId', 'brandName']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'brand-form');
    }

    public function openBrandEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-brands'), 403);

        $brand = Brand::findOrFail($id);

        $this->brandEditingId = $brand->id;
        $this->brandName = $brand->name;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'brand-form');
    }

    public function saveBrand(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-brands'), 403);

        $this->validate([
            'brandName' => ['required', 'string', 'max:255', Rule::unique('brands', 'name')->where('shop_id', Auth::user()->shop_id)->ignore($this->brandEditingId)],
        ]);

        $brand = $this->brandEditingId ? Brand::findOrFail($this->brandEditingId) : new Brand;
        $brand->name = $this->brandName;
        $brand->save();

        $this->toastSuccess($this->brandEditingId ? 'Brand updated.' : 'Brand added.');
        $this->dispatch('close-modal', name: 'brand-form');
        $this->reset(['brandEditingId', 'brandName']);
    }

    public function deleteBrand(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-brands'), 403);

        $brand = Brand::findOrFail($id);

        if (Product::where('details->brand', $brand->name)->exists()) {
            $this->toastError("Cannot delete \"{$brand->name}\" — it's used on existing products.");

            return;
        }

        $brand->delete();
        $this->toastSuccess('Brand deleted.');
    }

    public function closeBrandForm(): void
    {
        $this->dispatch('close-modal', name: 'brand-form');
        $this->reset(['brandEditingId', 'brandName']);
    }

    // ── Networks ─────────────────────────────────────────────────────

    public function openNetworkCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-networks'), 403);

        $this->reset(['networkEditingId', 'networkName', 'networkImage', 'networkExistingImageUrl']);
        $this->networkColor = '#049669';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'network-form');
    }

    public function openNetworkEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-networks'), 403);

        $network = Network::findOrFail($id);

        $this->networkEditingId = $network->id;
        $this->networkName = $network->name;
        $this->networkColor = $network->color ?? '#049669';
        $this->networkImage = null;
        $this->networkExistingImageUrl = $network->imageUrl();

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'network-form');
    }

    public function saveNetwork(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-networks'), 403);

        $this->validate([
            'networkName' => ['required', 'string', 'max:255', Rule::unique('networks', 'name')->where('shop_id', Auth::user()->shop_id)->ignore($this->networkEditingId)],
            'networkColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'networkImage' => ['nullable', 'image', 'max:2048'],
        ]);

        $network = $this->networkEditingId ? Network::findOrFail($this->networkEditingId) : new Network;

        $network->name = $this->networkName;
        $network->color = $this->networkColor;

        if ($this->networkImage) {
            $network->image_path = $this->storeImage($this->networkImage, 'networks', $network->image_path);
        }

        $network->save();

        $this->toastSuccess($this->networkEditingId ? 'Network updated.' : 'Network added.');
        $this->dispatch('close-modal', name: 'network-form');
        $this->reset(['networkEditingId', 'networkName', 'networkImage', 'networkExistingImageUrl']);
    }

    public function deleteNetwork(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-networks'), 403);

        $network = Network::findOrFail($id);

        if (BalanceLoad::where('network', $network->name)->exists()) {
            $this->toastError("Cannot delete \"{$network->name}\" — it has balance load history.");

            return;
        }

        $this->deleteImage($network->image_path);
        $network->delete();
        $this->toastSuccess('Network deleted.');
    }

    public function closeNetworkForm(): void
    {
        $this->dispatch('close-modal', name: 'network-form');
        $this->reset(['networkEditingId', 'networkName', 'networkImage', 'networkExistingImageUrl']);
    }

    // ── Shop Accounts ────────────────────────────────────────────────

    public function openShopAccountCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('shop-accounts'), 403);

        $this->reset(['shopAccountEditingId', 'shopAccountName', 'shopAccountProviderType']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'shop-account-form');
    }

    public function openShopAccountEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('shop-accounts'), 403);

        $account = ShopAccount::findOrFail($id);

        $this->shopAccountEditingId = $account->id;
        $this->shopAccountName = $account->name;
        $this->shopAccountProviderType = $account->provider_type;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'shop-account-form');
    }

    public function saveShopAccount(): void
    {
        abort_unless(Auth::user()->hasAccessTo('shop-accounts'), 403);

        $this->validate([
            'shopAccountName' => ['required', 'string', 'max:255', Rule::unique('shop_accounts', 'name')->where('shop_id', Auth::user()->shop_id)->ignore($this->shopAccountEditingId)],
            'shopAccountProviderType' => ['required', 'string', 'max:255'],
        ]);

        $account = $this->shopAccountEditingId ? ShopAccount::findOrFail($this->shopAccountEditingId) : new ShopAccount;

        $account->name = $this->shopAccountName;
        $account->provider_type = $this->shopAccountProviderType;
        $account->save();

        $this->toastSuccess($this->shopAccountEditingId ? 'Shop account updated.' : 'Shop account added.');
        $this->dispatch('close-modal', name: 'shop-account-form');
        $this->reset(['shopAccountEditingId', 'shopAccountName', 'shopAccountProviderType']);
    }

    public function deleteShopAccount(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('shop-accounts'), 403);

        $account = ShopAccount::findOrFail($id);

        if (WalletLoad::where('shop_account_id', $account->id)->exists()) {
            $this->toastError("Cannot delete \"{$account->name}\" — it has wallet load history.");

            return;
        }

        if (BillPayment::where('shop_account_id', $account->id)->exists()) {
            $this->toastError("Cannot delete \"{$account->name}\" — it has bill payment history.");

            return;
        }

        $account->delete();
        $this->toastSuccess('Shop account deleted.');
    }

    public function closeShopAccountForm(): void
    {
        $this->dispatch('close-modal', name: 'shop-account-form');
        $this->reset(['shopAccountEditingId', 'shopAccountName', 'shopAccountProviderType']);
    }

    // ── Bill Categories ──────────────────────────────────────────────

    public function openBillCategoryCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-bill-config'), 403);

        $this->reset(['billCategoryEditingId', 'billCategoryName']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'bill-category-form');
    }

    public function openBillCategoryEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-bill-config'), 403);

        $category = BillCategory::findOrFail($id);

        $this->billCategoryEditingId = $category->id;
        $this->billCategoryName = $category->name;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'bill-category-form');
    }

    public function saveBillCategory(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-bill-config'), 403);

        $this->validate([
            'billCategoryName' => ['required', 'string', 'max:255', Rule::unique('bill_categories', 'name')->where('shop_id', Auth::user()->shop_id)->ignore($this->billCategoryEditingId)],
        ]);

        $category = $this->billCategoryEditingId
            ? BillCategory::findOrFail($this->billCategoryEditingId)
            : CreateBillCategory::handle($this->billCategoryName);

        $category->name = $this->billCategoryName;
        $category->save();

        $this->toastSuccess($this->billCategoryEditingId ? 'Bill category updated.' : 'Bill category added.');
        $this->dispatch('close-modal', name: 'bill-category-form');
        $this->reset(['billCategoryEditingId', 'billCategoryName']);
    }

    public function deleteBillCategory(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-bill-config'), 403);

        $category = BillCategory::withCount('billProviders')->findOrFail($id);

        if ($category->bill_providers_count > 0) {
            $this->toastError("Cannot delete \"{$category->name}\" — it has providers under it. Delete those first.");

            return;
        }

        $category->delete();
        $this->toastSuccess('Bill category deleted.');
    }

    public function closeBillCategoryForm(): void
    {
        $this->dispatch('close-modal', name: 'bill-category-form');
        $this->reset(['billCategoryEditingId', 'billCategoryName']);
    }

    // ── Bill Providers ───────────────────────────────────────────────

    public function openBillProviderCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-bill-config'), 403);

        $this->reset(['billProviderEditingId', 'billProviderName', 'billProviderRegion']);
        $this->billProviderCategoryId = (string) (BillCategory::query()->orderBy('name')->value('id') ?? '');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'bill-provider-form');
    }

    public function openBillProviderEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-bill-config'), 403);

        $provider = BillProvider::findOrFail($id);

        $this->billProviderEditingId = $provider->id;
        $this->billProviderName = $provider->name;
        $this->billProviderCategoryId = (string) $provider->bill_category_id;
        $this->billProviderRegion = $provider->region ?? '';

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'bill-provider-form');
    }

    public function saveBillProvider(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-bill-config'), 403);

        $this->validate([
            'billProviderName' => ['required', 'string', 'max:255'],
            'billProviderCategoryId' => ['required', 'integer', Rule::exists('bill_categories', 'id')->where('shop_id', Auth::user()->shop_id)],
            'billProviderRegion' => ['nullable', 'string', 'max:255'],
        ]);

        $provider = $this->billProviderEditingId
            ? BillProvider::findOrFail($this->billProviderEditingId)
            : CreateBillProvider::handle($this->billProviderName, (int) $this->billProviderCategoryId, $this->billProviderRegion !== '' ? $this->billProviderRegion : null);

        $provider->name = $this->billProviderName;
        $provider->bill_category_id = $this->billProviderCategoryId;
        $provider->region = $this->billProviderRegion !== '' ? $this->billProviderRegion : null;
        $provider->save();

        $this->toastSuccess($this->billProviderEditingId ? 'Bill provider updated.' : 'Bill provider added.');
        $this->dispatch('close-modal', name: 'bill-provider-form');
        $this->reset(['billProviderEditingId', 'billProviderName', 'billProviderCategoryId', 'billProviderRegion']);
    }

    public function deleteBillProvider(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-bill-config'), 403);

        $provider = BillProvider::findOrFail($id);

        if (BillPayment::where('bill_provider_id', $provider->id)->exists()) {
            $this->toastError("Cannot delete \"{$provider->name}\" — it has bill payment history.");

            return;
        }

        $provider->delete();
        $this->toastSuccess('Bill provider deleted.');
    }

    public function closeBillProviderForm(): void
    {
        $this->dispatch('close-modal', name: 'bill-provider-form');
        $this->reset(['billProviderEditingId', 'billProviderName', 'billProviderCategoryId', 'billProviderRegion']);
    }

    // ── Service Categories ───────────────────────────────────────────

    public function openServiceCategoryCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-services'), 403);

        $this->reset(['serviceCategoryEditingId', 'serviceCategoryName']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'service-category-form');
    }

    public function openServiceCategoryEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-services'), 403);

        $category = ServiceCategory::findOrFail($id);

        $this->serviceCategoryEditingId = $category->id;
        $this->serviceCategoryName = $category->name;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'service-category-form');
    }

    public function saveServiceCategory(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-services'), 403);

        $this->validate([
            'serviceCategoryName' => ['required', 'string', 'max:255', Rule::unique('service_categories', 'name')->where('shop_id', Auth::user()->shop_id)->ignore($this->serviceCategoryEditingId)],
        ]);

        $category = $this->serviceCategoryEditingId
            ? ServiceCategory::findOrFail($this->serviceCategoryEditingId)
            : CreateServiceCategory::handle($this->serviceCategoryName);

        $category->name = $this->serviceCategoryName;
        $category->save();

        $this->toastSuccess($this->serviceCategoryEditingId ? 'Service category updated.' : 'Service category added.');
        $this->dispatch('close-modal', name: 'service-category-form');
        $this->reset(['serviceCategoryEditingId', 'serviceCategoryName']);
    }

    public function deleteServiceCategory(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-services'), 403);

        $category = ServiceCategory::withCount('serviceItems')->findOrFail($id);

        if ($category->service_items_count > 0) {
            $this->toastError("Cannot delete \"{$category->name}\" — it has items under it. Delete those first.");

            return;
        }

        $category->delete();
        $this->toastSuccess('Service category deleted.');
    }

    public function closeServiceCategoryForm(): void
    {
        $this->dispatch('close-modal', name: 'service-category-form');
        $this->reset(['serviceCategoryEditingId', 'serviceCategoryName']);
    }

    // ── Service Items ────────────────────────────────────────────────

    public function openServiceItemCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-services'), 403);

        $this->reset(['serviceItemEditingId', 'serviceItemName']);
        $this->serviceItemCategoryId = (string) (ServiceCategory::query()->orderBy('name')->value('id') ?? '');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'service-item-form');
    }

    public function openServiceItemEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-services'), 403);

        $item = ServiceItem::findOrFail($id);

        $this->serviceItemEditingId = $item->id;
        $this->serviceItemName = $item->name;
        $this->serviceItemCategoryId = (string) $item->service_category_id;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'service-item-form');
    }

    public function saveServiceItem(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-services'), 403);

        $this->validate([
            'serviceItemName' => ['required', 'string', 'max:255'],
            'serviceItemCategoryId' => ['required', 'integer', Rule::exists('service_categories', 'id')->where('shop_id', Auth::user()->shop_id)],
        ]);

        $item = $this->serviceItemEditingId
            ? ServiceItem::findOrFail($this->serviceItemEditingId)
            : CreateServiceItem::handle($this->serviceItemName, (int) $this->serviceItemCategoryId);

        $item->name = $this->serviceItemName;
        $item->service_category_id = $this->serviceItemCategoryId;
        $item->save();

        $this->toastSuccess($this->serviceItemEditingId ? 'Service item updated.' : 'Service item added.');
        $this->dispatch('close-modal', name: 'service-item-form');
        $this->reset(['serviceItemEditingId', 'serviceItemName', 'serviceItemCategoryId']);
    }

    public function deleteServiceItem(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-services'), 403);

        $item = ServiceItem::findOrFail($id);

        if (ServicePayment::where('service_item_id', $item->id)->exists()) {
            $this->toastError("Cannot delete \"{$item->name}\" — it has service payment history.");

            return;
        }

        $item->delete();
        $this->toastSuccess('Service item deleted.');
    }

    public function closeServiceItemForm(): void
    {
        $this->dispatch('close-modal', name: 'service-item-form');
        $this->reset(['serviceItemEditingId', 'serviceItemName', 'serviceItemCategoryId']);
    }

    // ── Roles ────────────────────────────────────────────────────────

    public function openRoleCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('users'), 403);

        $this->reset(['roleEditingId', 'roleName', 'rolePermissions']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'role-form');
    }

    public function openRoleEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('users'), 403);

        $role = Role::findOrFail($id);

        $this->roleEditingId = $role->id;
        $this->roleName = $role->name;
        $this->rolePermissions = $role->permissions ?? [];

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'role-form');
    }

    public function saveRole(): void
    {
        abort_unless(Auth::user()->hasAccessTo('users'), 403);

        $this->validate([
            'roleName' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('shop_id', Auth::user()->shop_id)->ignore($this->roleEditingId)],
            'rolePermissions' => ['array'],
            'rolePermissions.*' => [Rule::enum(ShopScreen::class)],
        ]);

        $role = $this->roleEditingId ? Role::findOrFail($this->roleEditingId) : new Role;

        $role->fill([
            'name' => $this->roleName,
            'permissions' => array_values($this->rolePermissions),
        ])->save();

        $this->toastSuccess($this->roleEditingId ? 'Role updated.' : 'Role created.');
        $this->dispatch('close-modal', name: 'role-form');
        $this->reset(['roleEditingId', 'roleName', 'rolePermissions']);
    }

    public function deleteRole(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('users'), 403);

        $role = Role::withCount('users')->findOrFail($id);

        if ($role->users_count > 0) {
            $this->toastError("Cannot delete \"{$role->name}\" — it's assigned to {$role->users_count} user(s). Reassign them first.");

            return;
        }

        $role->delete();
        $this->toastSuccess('Role deleted.');
    }

    public function closeRoleForm(): void
    {
        $this->dispatch('close-modal', name: 'role-form');
        $this->reset(['roleEditingId', 'roleName', 'rolePermissions']);
    }

    // ── Commission Percentages ───────────────────────────────────────

    public function saveCommissionPercentages(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-percentage'), 403);

        $this->validate([
            'simSaleCommissionPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'balanceLoadCommissionPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'walletLoadCommissionPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'billsCommissionPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'nadraVerificationCommissionPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        Auth::user()->shop->update([
            'sim_sale_commission_percent' => $this->simSaleCommissionPercent !== '' ? $this->simSaleCommissionPercent : null,
            'balance_load_commission_percent' => $this->balanceLoadCommissionPercent !== '' ? $this->balanceLoadCommissionPercent : null,
            'wallet_load_commission_percent' => $this->walletLoadCommissionPercent !== '' ? $this->walletLoadCommissionPercent : null,
            'bills_commission_percent' => $this->billsCommissionPercent !== '' ? $this->billsCommissionPercent : null,
            'nadra_verification_commission_percent' => $this->nadraVerificationCommissionPercent !== '' ? $this->nadraVerificationCommissionPercent : null,
        ]);

        $this->toastSuccess('Commission percentages saved.');
    }

    // ── Branding ──────────────────────────────────────────────────────

    public function saveBranding(): void
    {
        abort_unless(Auth::user()->shop?->branding_enabled && Auth::user()->hasAccessTo('settings-branding'), 403);

        $this->validate([
            'brandingLogo' => ['nullable', 'image', 'max:2048'],
            'brandingDisplayName' => ['nullable', 'string', 'max:255'],
            'brandingAddress' => ['nullable', 'string', 'max:500'],
        ]);

        $shop = Auth::user()->shop;

        if ($this->brandingLogo) {
            $shop->logo_path = $this->storeImage($this->brandingLogo, 'branding', $shop->logo_path);
        }

        $shop->invoice_display_name = $this->brandingDisplayName !== '' ? $this->brandingDisplayName : null;
        $shop->invoice_address = $this->brandingAddress !== '' ? $this->brandingAddress : null;
        $shop->save();

        $this->brandingLogo = null;
        $this->brandingExistingLogoUrl = $shop->logoUrl();

        $this->toastSuccess('Branding saved.');
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Settings</h1>
    </x-slot>

    @php
        $tabLabels = [
            'accessory-categories' => 'Categories',
            'brands' => 'Brands',
            'networks' => 'Networks',
            'branding' => 'Branding',
            'shop-accounts' => 'Shop Accounts',
            'bills' => 'Bills',
            'services' => 'Services',
            'integrations' => 'Integrations',
            'percentage' => 'Percentage',
            'profile' => 'Profile',
            'roles' => 'Roles',
            'modules' => 'Modules',
        ];
    @endphp

    @php
        // Display grouping only — which tabs exist still comes from accessibleTabs().
        $tabGroups = collect([
            'Your shop' => ['profile', 'branding', 'shop-accounts'],
            'Catalogue' => ['accessory-categories', 'brands', 'networks'],
            'Pricing & services' => ['percentage', 'bills', 'services'],
            'Team & access' => ['roles', 'modules'],
            'Connections' => ['integrations'],
        ])->map(fn ($keys) => array_values(array_intersect($keys, $accessibleTabs)))->filter();
        // A tab added to accessibleTabs() but not grouped above still shows up.
        $ungrouped = array_values(array_diff($accessibleTabs, $tabGroups->flatten()->all()));
        if ($ungrouped) {
            $tabGroups->put('More', $ungrouped);
        }
    @endphp

    <div class="lg:grid lg:grid-cols-[13rem_minmax(0,1fr)] lg:gap-8">
        {{-- Phones/tablets: one scrollable row of tabs, in the same group order. --}}
        <nav class="-mx-4 mb-5 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0 lg:hidden" aria-label="Settings sections">
            @foreach ($tabGroups->flatten() as $key)
                <button
                    type="button"
                    wire:click="setTab('{{ $key }}')"
                    @if ($tab === $key) aria-current="page" @endif
                    @class([
                        'shrink-0 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors',
                        'border-brand-600 bg-brand-600 text-white' => $tab === $key,
                        'border-slate-300 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-900' => $tab !== $key,
                    ])
                >
                    {{ $tabLabels[$key] ?? \Illuminate\Support\Str::headline($key) }}
                </button>
            @endforeach
        </nav>

        {{-- Desktop: grouped side menu. --}}
        <nav class="hidden lg:block" aria-label="Settings sections">
            <div class="sticky top-20 space-y-5">
                @foreach ($tabGroups as $group => $keys)
                    <div>
                        <p class="px-3 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $group }}</p>
                        <div class="mt-1.5 space-y-0.5">
                            @foreach ($keys as $key)
                                <button
                                    type="button"
                                    wire:click="setTab('{{ $key }}')"
                                    @if ($tab === $key) aria-current="page" @endif
                                    @class([
                                        'flex w-full items-center rounded-md border-l-2 px-3 py-1.5 text-left text-sm font-medium transition-colors',
                                        'border-accent-500 bg-white text-brand-800 shadow-sm' => $tab === $key,
                                        'border-transparent text-slate-600 hover:bg-white hover:text-slate-900' => $tab !== $key,
                                    ])
                                >
                                    {{ $tabLabels[$key] ?? \Illuminate\Support\Str::headline($key) }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </nav>

        <div class="min-w-0">
    @if ($tab === 'accessory-categories')
        <div class="space-y-6">
            <x-ui.card title="Main Categories" description="The top-level categories a Product can be. Mobile Phone and Accessory are built in; add your own for anything else your shop sells.">
                <x-slot name="actions">
                    <x-ui.button size="sm" wire:click="openMainCategoryCreate">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Add Main Category
                    </x-ui.button>
                </x-slot>

                <x-ui.table :headers="['Main Category', 'Sub-Categories', '']">
                    @foreach ($mainCategories as $category)
                        <x-ui.table-row wire:key="main-category-{{ $category->id }}">
                            <x-ui.table-cell class="font-medium text-slate-900">
                                <div class="flex items-center gap-2">
                                    {{ $category->name }}
                                    @if ($category->is_builtin)
                                        <x-ui.badge variant="brand">Built-in</x-ui.badge>
                                    @endif
                                </div>
                            </x-ui.table-cell>
                            <x-ui.table-cell>{{ $category->sub_categories_count }}</x-ui.table-cell>
                            <x-ui.table-cell align="right">
                                <div class="flex justify-end gap-2">
                                    <x-ui.button size="sm" variant="ghost" wire:click="openMainCategoryEdit({{ $category->id }})">
                                        Edit
                                    </x-ui.button>
                                    @unless ($category->is_builtin)
                                        <x-ui.button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="deleteMainCategory({{ $category->id }})"
                                            wire:confirm="Delete {{ $category->name }}?"
                                            class="text-red-600 hover:bg-red-50"
                                        >
                                            Delete
                                        </x-ui.button>
                                    @endunless
                                </div>
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Sub-Categories" description="Manage the sub-categories available under each Main Category when adding a product.">
                <x-slot name="actions">
                    <x-ui.button size="sm" wire:click="openAccessoryCategoryCreate">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Add Sub-Category
                    </x-ui.button>
                </x-slot>

                @if ($accessoryCategories->isEmpty())
                    <x-ui.empty-state
                        title="No sub-categories yet"
                        description="Add Case / Cover, Charger, Cable, or any other sub-category your shop uses."
                    >
                        <x-slot name="action">
                            <x-ui.button wire:click="openAccessoryCategoryCreate">Add Sub-Category</x-ui.button>
                        </x-slot>
                    </x-ui.empty-state>
                @else
                    <x-ui.table :headers="['Sub-Category', 'Main Category', '']">
                        @foreach ($accessoryCategories as $option)
                            <x-ui.table-row wire:key="accessory-category-{{ $option->id }}">
                                <x-ui.table-cell class="font-medium text-slate-900">
                                    <div class="flex items-center gap-3">
                                        <x-ui.thumbnail :src="$option->imageUrl()" :label="$option->name" />
                                        {{ $option->name }}
                                    </div>
                                </x-ui.table-cell>
                                <x-ui.table-cell>{{ $option->mainCategory?->name ?? '—' }}</x-ui.table-cell>
                                <x-ui.table-cell align="right">
                                    <div class="flex justify-end gap-2">
                                        <x-ui.button size="sm" variant="ghost" wire:click="openAccessoryCategoryEdit({{ $option->id }})">
                                            Edit
                                        </x-ui.button>
                                        <x-ui.button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="deleteAccessoryCategory({{ $option->id }})"
                                            wire:confirm="Delete {{ $option->name }}?"
                                            class="text-red-600 hover:bg-red-50"
                                        >
                                            Delete
                                        </x-ui.button>
                                    </div>
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        </div>
    @elseif ($tab === 'brands')
        <x-ui.card title="Brands" description="Manage the brands available when adding a Mobile Phone product.">
            <x-slot name="actions">
                <x-ui.button size="sm" wire:click="openBrandCreate">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Add Brand
                </x-ui.button>
            </x-slot>

            @if ($brands->isEmpty())
                <x-ui.empty-state
                    title="No brands yet"
                    description="Add Samsung, Apple, Infinix, or any other brand your shop sells."
                >
                    <x-slot name="action">
                        <x-ui.button wire:click="openBrandCreate">Add Brand</x-ui.button>
                    </x-slot>
                </x-ui.empty-state>
            @else
                <x-ui.table :headers="['Brand', '']">
                    @foreach ($brands as $brand)
                        <x-ui.table-row wire:key="brand-{{ $brand->id }}">
                            <x-ui.table-cell class="font-medium text-slate-900">{{ $brand->name }}</x-ui.table-cell>
                            <x-ui.table-cell align="right">
                                <div class="flex justify-end gap-2">
                                    <x-ui.button size="sm" variant="ghost" wire:click="openBrandEdit({{ $brand->id }})">
                                        Edit
                                    </x-ui.button>
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="deleteBrand({{ $brand->id }})"
                                        wire:confirm="Delete {{ $brand->name }}?"
                                        class="text-red-600 hover:bg-red-50"
                                    >
                                        Delete
                                    </x-ui.button>
                                </div>
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    @elseif ($tab === 'networks')
        <x-ui.card title="Networks" description="Manage the networks available on the Balance Load screen.">
            <x-slot name="actions">
                <x-ui.button size="sm" wire:click="openNetworkCreate">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Add Network
                </x-ui.button>
            </x-slot>

            @if ($networks->isEmpty())
                <x-ui.empty-state
                    title="No networks yet"
                    description="Add Jazz, Zong, Telenor, Ufone, or any other operator your shop supports."
                >
                    <x-slot name="action">
                        <x-ui.button wire:click="openNetworkCreate">Add Network</x-ui.button>
                    </x-slot>
                </x-ui.empty-state>
            @else
                <x-ui.table :headers="['Network', '']">
                    @foreach ($networks as $network)
                        <x-ui.table-row wire:key="network-{{ $network->id }}">
                            <x-ui.table-cell class="font-medium text-slate-900">
                                <div class="flex items-center gap-3">
                                    <x-ui.thumbnail :src="$network->imageUrl()" :label="$network->name" :color="$network->color" />
                                    {{ $network->name }}
                                    <x-ui.color-dot :color="$network->color" />
                                </div>
                            </x-ui.table-cell>
                            <x-ui.table-cell align="right">
                                <div class="flex justify-end gap-2">
                                    <x-ui.button size="sm" variant="ghost" wire:click="openNetworkEdit({{ $network->id }})">
                                        Edit
                                    </x-ui.button>
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="deleteNetwork({{ $network->id }})"
                                        wire:confirm="Delete {{ $network->name }}?"
                                        class="text-red-600 hover:bg-red-50"
                                    >
                                        Delete
                                    </x-ui.button>
                                </div>
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    @elseif ($tab === 'shop-accounts')
        <x-ui.card title="Shop Accounts" description="Manage the shop's own wallet/bank accounts used to send Wallet Loads, and see how much has moved through each.">
            <x-slot name="actions">
                <x-ui.button size="sm" wire:click="openShopAccountCreate">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Add Account
                </x-ui.button>
            </x-slot>

            @if ($shopAccounts->isEmpty())
                <x-ui.empty-state
                    title="No shop accounts yet"
                    description="Add the shop's own JazzCash, Easypaisa, or bank account used to send Wallet Loads."
                >
                    <x-slot name="action">
                        <x-ui.button wire:click="openShopAccountCreate">Add Account</x-ui.button>
                    </x-slot>
                </x-ui.empty-state>
            @else
                <x-ui.table :headers="['Account', 'Provider / Type', 'Total Sent', 'Total Received', '']">
                    @foreach ($shopAccounts as $account)
                        <x-ui.table-row wire:key="shop-account-{{ $account->id }}">
                            <x-ui.table-cell class="font-medium text-slate-900">{{ $account->name }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $account->provider_type }}</x-ui.table-cell>
                            <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format(($account->wallet_cash_in_sum_amount ?? 0) + ($account->bill_payments_sum_amount ?? 0), 2) }}</x-ui.table-cell>
                            <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($account->wallet_cash_out_sum_amount ?? 0, 2) }}</x-ui.table-cell>
                            <x-ui.table-cell align="right">
                                <div class="flex justify-end gap-2">
                                    <x-ui.button size="sm" variant="ghost" wire:click="openShopAccountEdit({{ $account->id }})">
                                        Edit
                                    </x-ui.button>
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="deleteShopAccount({{ $account->id }})"
                                        wire:confirm="Delete {{ $account->name }}?"
                                        class="text-red-600 hover:bg-red-50"
                                    >
                                        Delete
                                    </x-ui.button>
                                </div>
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    @elseif ($tab === 'bills')
        <div class="space-y-6">
            <x-ui.card title="Bill Categories" description="Manage the types of bills your shop accepts payment for (Electricity, Gas, Water, Telephone, etc.).">
                <x-slot name="actions">
                    <x-ui.button size="sm" wire:click="openBillCategoryCreate">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Add Category
                    </x-ui.button>
                </x-slot>

                @if ($billCategories->isEmpty())
                    <x-ui.empty-state
                        title="No bill categories yet"
                        description="Add Electricity, Gas, Water, Telephone, or any other category your shop accepts."
                    >
                        <x-slot name="action">
                            <x-ui.button wire:click="openBillCategoryCreate">Add Category</x-ui.button>
                        </x-slot>
                    </x-ui.empty-state>
                @else
                    <x-ui.table :headers="['Category', 'Providers', '']">
                        @foreach ($billCategories as $category)
                            <x-ui.table-row wire:key="bill-category-{{ $category->id }}">
                                <x-ui.table-cell class="font-medium text-slate-900">{{ $category->name }}</x-ui.table-cell>
                                <x-ui.table-cell>{{ $category->bill_providers_count }}</x-ui.table-cell>
                                <x-ui.table-cell align="right">
                                    <div class="flex justify-end gap-2">
                                        <x-ui.button size="sm" variant="ghost" wire:click="openBillCategoryEdit({{ $category->id }})">
                                            Edit
                                        </x-ui.button>
                                        <x-ui.button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="deleteBillCategory({{ $category->id }})"
                                            wire:confirm="Delete {{ $category->name }}?"
                                            class="text-red-600 hover:bg-red-50"
                                        >
                                            Delete
                                        </x-ui.button>
                                    </div>
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>

            <x-ui.card title="Bill Providers" description="Manage the companies under each category (e.g. LESCO, K-Electric under Electricity).">
                <x-slot name="actions">
                    <x-ui.button size="sm" wire:click="openBillProviderCreate">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Add Provider
                    </x-ui.button>
                </x-slot>

                @if ($billProviders->isEmpty())
                    <x-ui.empty-state
                        title="No bill providers yet"
                        description="Add the companies your shop collects payments for, e.g. LESCO or K-Electric."
                    >
                        <x-slot name="action">
                            <x-ui.button wire:click="openBillProviderCreate">Add Provider</x-ui.button>
                        </x-slot>
                    </x-ui.empty-state>
                @else
                    <x-ui.table :headers="['Provider', 'Category', 'Region', '']">
                        @foreach ($billProviders as $provider)
                            <x-ui.table-row wire:key="bill-provider-{{ $provider->id }}">
                                <x-ui.table-cell class="font-medium text-slate-900">{{ $provider->name }}</x-ui.table-cell>
                                <x-ui.table-cell>{{ $provider->billCategory->name }}</x-ui.table-cell>
                                <x-ui.table-cell>{{ $provider->region ?? '—' }}</x-ui.table-cell>
                                <x-ui.table-cell align="right">
                                    <div class="flex justify-end gap-2">
                                        <x-ui.button size="sm" variant="ghost" wire:click="openBillProviderEdit({{ $provider->id }})">
                                            Edit
                                        </x-ui.button>
                                        <x-ui.button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="deleteBillProvider({{ $provider->id }})"
                                            wire:confirm="Delete {{ $provider->name }}?"
                                            class="text-red-600 hover:bg-red-50"
                                        >
                                            Delete
                                        </x-ui.button>
                                    </div>
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        </div>
    @elseif ($tab === 'services')
        <div class="space-y-6">
            <x-ui.card title="Service Categories" description="Manage the top-level categories your shop groups services under (Services, Installations, Downloads, etc.).">
                <x-slot name="actions">
                    <x-ui.button size="sm" wire:click="openServiceCategoryCreate">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Add Category
                    </x-ui.button>
                </x-slot>

                @if ($serviceCategories->isEmpty())
                    <x-ui.empty-state
                        title="No service categories yet"
                        description="Add Services, Installations, Downloads, or any other category your shop offers."
                    >
                        <x-slot name="action">
                            <x-ui.button wire:click="openServiceCategoryCreate">Add Category</x-ui.button>
                        </x-slot>
                    </x-ui.empty-state>
                @else
                    <x-ui.table :headers="['Category', 'Items', '']">
                        @foreach ($serviceCategories as $category)
                            <x-ui.table-row wire:key="service-category-{{ $category->id }}">
                                <x-ui.table-cell class="font-medium text-slate-900">{{ $category->name }}</x-ui.table-cell>
                                <x-ui.table-cell>{{ $category->service_items_count }}</x-ui.table-cell>
                                <x-ui.table-cell align="right">
                                    <div class="flex justify-end gap-2">
                                        <x-ui.button size="sm" variant="ghost" wire:click="openServiceCategoryEdit({{ $category->id }})">
                                            Edit
                                        </x-ui.button>
                                        <x-ui.button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="deleteServiceCategory({{ $category->id }})"
                                            wire:confirm="Delete {{ $category->name }}?"
                                            class="text-red-600 hover:bg-red-50"
                                        >
                                            Delete
                                        </x-ui.button>
                                    </div>
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>

            <x-ui.card title="Service Items" description="Manage the sellable items under each category (e.g. Screen Protector Install under Installations).">
                <x-slot name="actions">
                    <x-ui.button size="sm" wire:click="openServiceItemCreate">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Add Item
                    </x-ui.button>
                </x-slot>

                @if ($serviceItems->isEmpty())
                    <x-ui.empty-state
                        title="No service items yet"
                        description="Add the items your shop sells under each category."
                    >
                        <x-slot name="action">
                            <x-ui.button wire:click="openServiceItemCreate">Add Item</x-ui.button>
                        </x-slot>
                    </x-ui.empty-state>
                @else
                    <x-ui.table :headers="['Item', 'Category', '']">
                        @foreach ($serviceItems as $item)
                            <x-ui.table-row wire:key="service-item-{{ $item->id }}">
                                <x-ui.table-cell class="font-medium text-slate-900">{{ $item->name }}</x-ui.table-cell>
                                <x-ui.table-cell>{{ $item->serviceCategory->name }}</x-ui.table-cell>
                                <x-ui.table-cell align="right">
                                    <div class="flex justify-end gap-2">
                                        <x-ui.button size="sm" variant="ghost" wire:click="openServiceItemEdit({{ $item->id }})">
                                            Edit
                                        </x-ui.button>
                                        <x-ui.button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="deleteServiceItem({{ $item->id }})"
                                            wire:confirm="Delete {{ $item->name }}?"
                                            class="text-red-600 hover:bg-red-50"
                                        >
                                            Delete
                                        </x-ui.button>
                                    </div>
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        </div>
    @elseif ($tab === 'percentage')
        <div class="max-w-2xl space-y-6">
            <x-ui.card title="Commission Percentages" description="Set a default % for each service. When staff enter an amount, this auto-suggests the service fee — they can still edit it freely before saving.">
                <form wire:submit="saveCommissionPercentages" class="space-y-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="SIM Sale %" name="simSaleCommissionPercent" for="simSaleCommissionPercent" help="Leave blank for no auto-suggestion">
                            <x-ui.input wire:model="simSaleCommissionPercent" id="simSaleCommissionPercent" type="number" min="0" max="100" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="Balance Load %" name="balanceLoadCommissionPercent" for="balanceLoadCommissionPercent" help="Leave blank for no auto-suggestion">
                            <x-ui.input wire:model="balanceLoadCommissionPercent" id="balanceLoadCommissionPercent" type="number" min="0" max="100" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="Wallet Load %" name="walletLoadCommissionPercent" for="walletLoadCommissionPercent" help="Leave blank for no auto-suggestion">
                            <x-ui.input wire:model="walletLoadCommissionPercent" id="walletLoadCommissionPercent" type="number" min="0" max="100" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="Bills %" name="billsCommissionPercent" for="billsCommissionPercent" help="Leave blank for no auto-suggestion">
                            <x-ui.input wire:model="billsCommissionPercent" id="billsCommissionPercent" type="number" min="0" max="100" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="NADRA Verification %" name="nadraVerificationCommissionPercent" for="nadraVerificationCommissionPercent" help="Leave blank for no auto-suggestion">
                            <x-ui.input wire:model="nadraVerificationCommissionPercent" id="nadraVerificationCommissionPercent" type="number" min="0" max="100" step="0.01" />
                        </x-ui.field>
                    </div>

                    <p class="text-sm text-slate-500">
                        Udhaar isn't listed here — it's a customer loan, not a paid service, so it has no commission.
                    </p>

                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveCommissionPercentages">
                        Save Percentages
                    </x-ui.button>
                </form>
            </x-ui.card>
        </div>
    @elseif ($tab === 'branding')
        <div class="max-w-2xl space-y-6">
            <x-ui.card title="Branding" description="Shown on this shop's own receipts and invoices in place of the plain shop name.">
                <form wire:submit="saveBranding" class="space-y-5">
                    <x-ui.field label="Logo" name="brandingLogo" for="brandingLogo" help="Optional">
                        <x-ui.file-input
                            wire:model="brandingLogo"
                            id="brandingLogo"
                            :preview="$this->previewUrl($brandingLogo, $brandingExistingLogoUrl)"
                        />
                    </x-ui.field>

                    <x-ui.field label="Display Name" name="brandingDisplayName" for="brandingDisplayName" :help="'Shown on receipts instead of \''.Auth::user()->shop->name.'\'. Leave blank to use the shop name.'">
                        <x-ui.input wire:model="brandingDisplayName" id="brandingDisplayName" :placeholder="Auth::user()->shop->name" />
                    </x-ui.field>

                    <x-ui.field label="Address" name="brandingAddress" for="brandingAddress" help="Shown on receipts and invoices. Leave blank to hide.">
                        <textarea
                            wire:model="brandingAddress"
                            id="brandingAddress"
                            rows="2"
                            class="block w-full rounded-lg border-slate-300 py-2 px-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500"
                            placeholder="Shop #, Street, Market / City"
                        ></textarea>
                    </x-ui.field>

                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveBranding">
                        Save Branding
                    </x-ui.button>
                </form>
            </x-ui.card>
        </div>
    @elseif ($tab === 'modules')
        <div class="max-w-2xl">
            <livewire:settings.modules />
        </div>
    @elseif ($tab === 'integrations')
        <div class="max-w-3xl">
            <livewire:settings.integrations />
        </div>
    @elseif ($tab === 'profile')
        <div class="max-w-2xl space-y-6">
            <x-ui.card>
                <livewire:profile.update-profile-information-form />
            </x-ui.card>

            <x-ui.card>
                <livewire:profile.update-password-form />
            </x-ui.card>

            <x-ui.card>
                <livewire:profile.delete-user-form />
            </x-ui.card>
        </div>
    @elseif ($tab === 'roles')
        <x-ui.card title="Roles" description="Control which screens each role can access.">
            <x-slot name="actions">
                <x-ui.button size="sm" wire:click="openRoleCreate">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Add Role
                </x-ui.button>
            </x-slot>

            @if ($roles->isEmpty())
                <x-ui.empty-state
                    title="No roles yet"
                    description="Create a role to control what staff can access, then assign it when adding a user."
                >
                    <x-slot name="action">
                        <x-ui.button wire:click="openRoleCreate">Add Role</x-ui.button>
                    </x-slot>
                </x-ui.empty-state>
            @else
                <x-ui.table :headers="['Role', 'Screen Access', 'Users', '']">
                    @foreach ($roles as $role)
                        <x-ui.table-row wire:key="role-{{ $role->id }}">
                            <x-ui.table-cell class="font-medium text-slate-900">{{ $role->name }}</x-ui.table-cell>
                            <x-ui.table-cell>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach (ShopScreen::cases() as $screen)
                                        @if ($role->hasAccess($screen))
                                            <x-ui.badge variant="brand">{{ $screen->label() }}</x-ui.badge>
                                        @endif
                                    @endforeach
                                    @if (empty($role->permissions))
                                        <span class="text-sm text-slate-400">No access</span>
                                    @endif
                                </div>
                            </x-ui.table-cell>
                            <x-ui.table-cell>{{ $role->users_count }}</x-ui.table-cell>
                            <x-ui.table-cell align="right">
                                <div class="flex justify-end gap-2">
                                    <x-ui.button size="sm" variant="ghost" wire:click="openRoleEdit({{ $role->id }})">
                                        Edit
                                    </x-ui.button>
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="deleteRole({{ $role->id }})"
                                        wire:confirm="Delete the {{ $role->name }} role? This cannot be undone."
                                        class="text-red-600 hover:bg-red-50"
                                    >
                                        Delete
                                    </x-ui.button>
                                </div>
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    @endif
        </div>
    </div>

    <x-ui.modal name="main-category-form" max-width="sm">
        <form wire:submit="saveMainCategory" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $mainCategoryEditingId ? 'Edit Main Category' : 'Add Main Category' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Main Category Name" name="mainCategoryName" for="mainCategoryName">
                    <x-ui.input wire:model="mainCategoryName" id="mainCategoryName" placeholder="e.g. Repair Parts" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeMainCategoryForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveMainCategory">
                    {{ $mainCategoryEditingId ? 'Save Changes' : 'Add Main Category' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="accessory-category-form" max-width="md">
        <form wire:submit="saveAccessoryCategory" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $accessoryCategoryEditingId ? 'Edit Sub-Category' : 'Add Sub-Category' }}
            </h2>

            <div class="mt-5 space-y-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Main Category" name="accessoryCategoryMainCategoryId" for="accessoryCategoryMainCategoryId">
                        <x-ui.select wire:model="accessoryCategoryMainCategoryId" id="accessoryCategoryMainCategoryId">
                            @foreach ($mainCategories as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Sub-Category Name" name="accessoryCategoryName" for="accessoryCategoryName">
                        <x-ui.input wire:model="accessoryCategoryName" id="accessoryCategoryName" placeholder="e.g. Charger" />
                    </x-ui.field>
                </div>

                <x-ui.field label="Image" name="accessoryCategoryImage" for="accessoryCategoryImage" help="Optional">
                    <x-ui.file-input
                        wire:model="accessoryCategoryImage"
                        id="accessoryCategoryImage"
                        :preview="$this->previewUrl($accessoryCategoryImage, $accessoryCategoryExistingImageUrl)"
                    />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeAccessoryCategoryForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveAccessoryCategory">
                    {{ $accessoryCategoryEditingId ? 'Save Changes' : 'Add Sub-Category' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="brand-form" max-width="sm">
        <form wire:submit="saveBrand" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $brandEditingId ? 'Edit Brand' : 'Add Brand' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Brand Name" name="brandName" for="brandName">
                    <x-ui.input wire:model="brandName" id="brandName" placeholder="e.g. Samsung" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeBrandForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveBrand">
                    {{ $brandEditingId ? 'Save Changes' : 'Add Brand' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="network-form" max-width="md">
        <form wire:submit="saveNetwork" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $networkEditingId ? 'Edit Network' : 'Add Network' }}
            </h2>

            <div class="mt-5 space-y-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Network Name" name="networkName" for="networkName">
                        <x-ui.input wire:model="networkName" id="networkName" placeholder="e.g. Jazz" autofocus />
                    </x-ui.field>

                    <x-ui.field label="Color" name="networkColor" for="networkColor">
                        <div class="flex items-center gap-3">
                            <input type="color" wire:model="networkColor" id="networkColor" class="h-10 w-14 cursor-pointer rounded-lg border border-slate-300">
                            <span class="text-sm text-slate-500">{{ $networkColor }}</span>
                        </div>
                    </x-ui.field>
                </div>

                <x-ui.field label="Image" name="networkImage" for="networkImage" help="Optional">
                    <x-ui.file-input
                        wire:model="networkImage"
                        id="networkImage"
                        :preview="$this->previewUrl($networkImage, $networkExistingImageUrl)"
                    />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeNetworkForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveNetwork">
                    {{ $networkEditingId ? 'Save Changes' : 'Add Network' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>


    <x-ui.modal name="shop-account-form" max-width="md">
        <form wire:submit="saveShopAccount" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $shopAccountEditingId ? 'Edit Shop Account' : 'Add Shop Account' }}
            </h2>

            <div class="mt-5 space-y-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Account Name" name="shopAccountName" for="shopAccountName" help="e.g. My JazzCash — 03xx-xxxxxxx">
                        <x-ui.input wire:model="shopAccountName" id="shopAccountName" autofocus />
                    </x-ui.field>

                    <x-ui.field label="Provider / Type" name="shopAccountProviderType" for="shopAccountProviderType" help="e.g. JazzCash, Easypaisa, Bank">
                        <x-ui.input wire:model="shopAccountProviderType" id="shopAccountProviderType" />
                    </x-ui.field>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeShopAccountForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveShopAccount">
                    {{ $shopAccountEditingId ? 'Save Changes' : 'Add Account' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="bill-category-form" max-width="sm">
        <form wire:submit="saveBillCategory" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $billCategoryEditingId ? 'Edit Bill Category' : 'Add Bill Category' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Category Name" name="billCategoryName" for="billCategoryName" help="e.g. Electricity, Gas, Water">
                    <x-ui.input wire:model="billCategoryName" id="billCategoryName" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeBillCategoryForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveBillCategory">
                    {{ $billCategoryEditingId ? 'Save Changes' : 'Add Category' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="bill-provider-form" max-width="md">
        <form wire:submit="saveBillProvider" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $billProviderEditingId ? 'Edit Bill Provider' : 'Add Bill Provider' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Category" name="billProviderCategoryId" for="billProviderCategoryId">
                    <x-ui.select wire:model="billProviderCategoryId" id="billProviderCategoryId">
                        @foreach ($billCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Provider Name" name="billProviderName" for="billProviderName" help="e.g. LESCO, K-Electric">
                        <x-ui.input wire:model="billProviderName" id="billProviderName" />
                    </x-ui.field>

                    <x-ui.field label="Region / Province" name="billProviderRegion" for="billProviderRegion" help="Optional">
                        <x-ui.input wire:model="billProviderRegion" id="billProviderRegion" placeholder="e.g. Punjab" />
                    </x-ui.field>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeBillProviderForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveBillProvider">
                    {{ $billProviderEditingId ? 'Save Changes' : 'Add Provider' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="service-category-form" max-width="sm">
        <form wire:submit="saveServiceCategory" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $serviceCategoryEditingId ? 'Edit Service Category' : 'Add Service Category' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Category Name" name="serviceCategoryName" for="serviceCategoryName" help="e.g. Services, Installations, Downloads">
                    <x-ui.input wire:model="serviceCategoryName" id="serviceCategoryName" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeServiceCategoryForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveServiceCategory">
                    {{ $serviceCategoryEditingId ? 'Save Changes' : 'Add Category' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="service-item-form" max-width="md">
        <form wire:submit="saveServiceItem" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $serviceItemEditingId ? 'Edit Service Item' : 'Add Service Item' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Category" name="serviceItemCategoryId" for="serviceItemCategoryId">
                    <x-ui.select wire:model="serviceItemCategoryId" id="serviceItemCategoryId">
                        @foreach ($serviceCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Item Name" name="serviceItemName" for="serviceItemName" help="e.g. Screen Protector Install, Google Play Gift Card">
                    <x-ui.input wire:model="serviceItemName" id="serviceItemName" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeServiceItemForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveServiceItem">
                    {{ $serviceItemEditingId ? 'Save Changes' : 'Add Item' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="role-form" max-width="lg">
        <form wire:submit="saveRole" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $roleEditingId ? 'Edit Role' : 'Add Role' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Role Name" name="roleName" for="roleName">
                    <x-ui.input wire:model="roleName" id="roleName" placeholder="e.g. Cashier, Manager" autofocus />
                </x-ui.field>

                <x-ui.field label="Screen Access" name="rolePermissions">
                    <div class="max-h-96 space-y-4 overflow-y-auto rounded-lg border border-slate-200 p-4">
                        @foreach ($groupedScreens as $groupLabel => $groupScreens)
                            <div>
                                <h4 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $groupLabel }}</h4>
                                <div class="grid grid-cols-2 gap-3">
                                    @foreach ($groupScreens as $screen)
                                        <x-ui.checkbox wire:model="rolePermissions" value="{{ $screen->value }}" :label="$screen->label()" />
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeRoleForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveRole">
                    {{ $roleEditingId ? 'Save Changes' : 'Add Role' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
