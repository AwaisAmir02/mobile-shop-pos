<?php

use App\Actions\CreateAccessoryCategory;
use App\Actions\CreateWalletProvider;
use App\Enums\ShopScreen;
use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\UploadsImages;
use App\Models\AccessoryCategoryOption;
use App\Models\BalanceLoad;
use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Models\MainCategory;
use App\Models\Network;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShopAccount;
use App\Models\WalletLoad;
use App\Models\WalletProvider;
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
    public string $tab = 'accessory-categories';

    // Main Categories
    public ?int $mainCategoryEditingId = null;
    public string $mainCategoryName = '';

    // Sub-Categories (formerly "Accessory Categories")
    public ?int $accessoryCategoryEditingId = null;
    public string $accessoryCategoryName = '';
    public string $accessoryCategoryMainCategoryId = '';
    public $accessoryCategoryImage = null;
    public ?string $accessoryCategoryExistingImageUrl = null;

    // Wallet Providers
    public ?int $providerEditingId = null;
    public string $providerName = '';
    public $providerImage = null;
    public ?string $providerExistingImageUrl = null;

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

    public function mount(): void
    {
        MainCategory::ensureDefaultsExist();

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
    }

    /**
     * The settings.index route accepts either the "settings" or "users"
     * screen permission (it now hosts both feature sets), so each tab
     * checks its own specific permission rather than assuming the page
     * gate covers it. Profile has no permission requirement, matching
     * the standalone /profile route it mirrors.
     */
    protected function accessibleTabs(): array
    {
        $tabs = [];

        if (Auth::user()->hasAccessTo('settings')) {
            $tabs = [...$tabs, 'accessory-categories', 'networks', 'wallet-providers', 'bills', 'percentage'];
        }

        if (Auth::user()->hasAccessTo('shop-accounts')) {
            $tabs[] = 'shop-accounts';
        }

        if (Auth::user()->hasAccessTo('users')) {
            $tabs[] = 'roles';
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
            'providers' => in_array('wallet-providers', $accessible, true)
                ? WalletProvider::query()->orderBy('name')->get()
                : collect(),
            'networks' => in_array('networks', $accessible, true)
                ? Network::query()->orderBy('name')->get()
                : collect(),
            'shopAccounts' => in_array('shop-accounts', $accessible, true)
                ? ShopAccount::query()->withSum('walletLoads', 'amount')->orderBy('name')->get()
                : collect(),
            'billCategories' => in_array('bills', $accessible, true)
                ? BillCategory::query()->withCount('billProviders')->orderBy('name')->get()
                : collect(),
            'billProviders' => in_array('bills', $accessible, true)
                ? BillProvider::query()->with('billCategory')->orderBy('name')->get()
                : collect(),
            'roles' => in_array('roles', $accessible, true)
                ? Role::query()->withCount('users')->orderBy('name')->get()
                : collect(),
            'screens' => ShopScreen::cases(),
        ];
    }

    // ── Main Categories ──────────────────────────────────────────────

    public function openMainCategoryCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $this->reset(['mainCategoryEditingId', 'mainCategoryName']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'main-category-form');
    }

    public function openMainCategoryEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $category = MainCategory::findOrFail($id);

        $this->mainCategoryEditingId = $category->id;
        $this->mainCategoryName = $category->name;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'main-category-form');
    }

    public function saveMainCategory(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

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
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

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
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $this->reset(['accessoryCategoryEditingId', 'accessoryCategoryName', 'accessoryCategoryImage', 'accessoryCategoryExistingImageUrl']);
        $this->accessoryCategoryMainCategoryId = (string) (MainCategory::query()->orderBy('name')->value('id') ?? '');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'accessory-category-form');
    }

    public function openAccessoryCategoryEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

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
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

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
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

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

    // ── Wallet Providers ─────────────────────────────────────────────

    public function openProviderCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $this->reset(['providerEditingId', 'providerName', 'providerImage', 'providerExistingImageUrl']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'wallet-provider-form');
    }

    public function openProviderEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $provider = WalletProvider::findOrFail($id);

        $this->providerEditingId = $provider->id;
        $this->providerName = $provider->name;
        $this->providerImage = null;
        $this->providerExistingImageUrl = $provider->imageUrl();

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'wallet-provider-form');
    }

    public function saveProvider(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $this->validate([
            'providerName' => ['required', 'string', 'max:255', Rule::unique('wallet_providers', 'name')->where('shop_id', Auth::user()->shop_id)->ignore($this->providerEditingId)],
            'providerImage' => ['nullable', 'image', 'max:2048'],
        ]);

        $provider = $this->providerEditingId ? WalletProvider::findOrFail($this->providerEditingId) : CreateWalletProvider::handle($this->providerName);

        $provider->name = $this->providerName;

        if ($this->providerImage) {
            $provider->image_path = $this->storeImage($this->providerImage, 'wallet-providers', $provider->image_path);
        }

        $provider->save();

        $this->toastSuccess($this->providerEditingId ? 'Wallet provider updated.' : 'Wallet provider added.');
        $this->dispatch('close-modal', name: 'wallet-provider-form');
        $this->reset(['providerEditingId', 'providerName', 'providerImage', 'providerExistingImageUrl']);
    }

    public function deleteProvider(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $provider = WalletProvider::findOrFail($id);

        if (WalletLoad::where('provider', $provider->name)->exists()) {
            $this->toastError("Cannot delete \"{$provider->name}\" — it has wallet load history.");

            return;
        }

        $this->deleteImage($provider->image_path);
        $provider->delete();
        $this->toastSuccess('Wallet provider deleted.');
    }

    public function closeProviderForm(): void
    {
        $this->dispatch('close-modal', name: 'wallet-provider-form');
        $this->reset(['providerEditingId', 'providerName', 'providerImage', 'providerExistingImageUrl']);
    }

    // ── Networks ─────────────────────────────────────────────────────

    public function openNetworkCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $this->reset(['networkEditingId', 'networkName', 'networkImage', 'networkExistingImageUrl']);
        $this->networkColor = '#049669';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'network-form');
    }

    public function openNetworkEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

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
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

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
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

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
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $this->reset(['billCategoryEditingId', 'billCategoryName']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'bill-category-form');
    }

    public function openBillCategoryEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $category = BillCategory::findOrFail($id);

        $this->billCategoryEditingId = $category->id;
        $this->billCategoryName = $category->name;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'bill-category-form');
    }

    public function saveBillCategory(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $this->validate([
            'billCategoryName' => ['required', 'string', 'max:255', Rule::unique('bill_categories', 'name')->where('shop_id', Auth::user()->shop_id)->ignore($this->billCategoryEditingId)],
        ]);

        $category = $this->billCategoryEditingId ? BillCategory::findOrFail($this->billCategoryEditingId) : new BillCategory;

        $category->name = $this->billCategoryName;
        $category->save();

        $this->toastSuccess($this->billCategoryEditingId ? 'Bill category updated.' : 'Bill category added.');
        $this->dispatch('close-modal', name: 'bill-category-form');
        $this->reset(['billCategoryEditingId', 'billCategoryName']);
    }

    public function deleteBillCategory(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

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
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $this->reset(['billProviderEditingId', 'billProviderName', 'billProviderRegion']);
        $this->billProviderCategoryId = (string) (BillCategory::query()->orderBy('name')->value('id') ?? '');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'bill-provider-form');
    }

    public function openBillProviderEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

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
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $this->validate([
            'billProviderName' => ['required', 'string', 'max:255'],
            'billProviderCategoryId' => ['required', 'integer', Rule::exists('bill_categories', 'id')->where('shop_id', Auth::user()->shop_id)],
            'billProviderRegion' => ['nullable', 'string', 'max:255'],
        ]);

        $provider = $this->billProviderEditingId ? BillProvider::findOrFail($this->billProviderEditingId) : new BillProvider;

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
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

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
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

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
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Settings</h1>
    </x-slot>

    @php
        $tabLabels = [
            'accessory-categories' => 'Categories',
            'networks' => 'Networks',
            'wallet-providers' => 'Wallet Providers',
            'shop-accounts' => 'Shop Accounts',
            'bills' => 'Bills',
            'percentage' => 'Percentage',
            'profile' => 'Profile',
            'roles' => 'Roles',
        ];
    @endphp

    <div class="mb-6 border-b border-slate-200">
        <nav class="-mb-px flex gap-6 overflow-x-auto">
            @foreach ($accessibleTabs as $key)
                <button
                    type="button"
                    wire:click="setTab('{{ $key }}')"
                    @class([
                        'whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition',
                        'border-brand-600 text-brand-700' => $tab === $key,
                        'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $tab !== $key,
                    ])
                >
                    {{ $tabLabels[$key] }}
                </button>
            @endforeach
        </nav>
    </div>

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
    @elseif ($tab === 'wallet-providers')
        <x-ui.card title="Wallet Providers" description="Manage the providers available on the Wallet Load screen.">
            <x-slot name="actions">
                <x-ui.button size="sm" wire:click="openProviderCreate">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Add Provider
                </x-ui.button>
            </x-slot>

            @if ($providers->isEmpty())
                <x-ui.empty-state
                    title="No wallet providers yet"
                    description="Add JazzCash, Easypaisa, NayaPay, or any other provider your shop supports."
                >
                    <x-slot name="action">
                        <x-ui.button wire:click="openProviderCreate">Add Provider</x-ui.button>
                    </x-slot>
                </x-ui.empty-state>
            @else
                <x-ui.table :headers="['Provider', '']">
                    @foreach ($providers as $provider)
                        <x-ui.table-row wire:key="provider-{{ $provider->id }}">
                            <x-ui.table-cell class="font-medium text-slate-900">
                                <div class="flex items-center gap-3">
                                    <x-ui.thumbnail :src="$provider->imageUrl()" :label="$provider->name" />
                                    {{ $provider->name }}
                                </div>
                            </x-ui.table-cell>
                            <x-ui.table-cell align="right">
                                <div class="flex justify-end gap-2">
                                    <x-ui.button size="sm" variant="ghost" wire:click="openProviderEdit({{ $provider->id }})">
                                        Edit
                                    </x-ui.button>
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="deleteProvider({{ $provider->id }})"
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
                <x-ui.table :headers="['Account', 'Provider / Type', 'Total Sent', '']">
                    @foreach ($shopAccounts as $account)
                        <x-ui.table-row wire:key="shop-account-{{ $account->id }}">
                            <x-ui.table-cell class="font-medium text-slate-900">{{ $account->name }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $account->provider_type }}</x-ui.table-cell>
                            <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($account->wallet_loads_sum_amount ?? 0, 2) }}</x-ui.table-cell>
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

    <x-ui.modal name="accessory-category-form" max-width="sm">
        <form wire:submit="saveAccessoryCategory" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $accessoryCategoryEditingId ? 'Edit Sub-Category' : 'Add Sub-Category' }}
            </h2>

            <div class="mt-5 space-y-5">
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

    <x-ui.modal name="network-form" max-width="sm">
        <form wire:submit="saveNetwork" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $networkEditingId ? 'Edit Network' : 'Add Network' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Network Name" name="networkName" for="networkName">
                    <x-ui.input wire:model="networkName" id="networkName" placeholder="e.g. Jazz" autofocus />
                </x-ui.field>

                <x-ui.field label="Color" name="networkColor" for="networkColor">
                    <div class="flex items-center gap-3">
                        <input type="color" wire:model="networkColor" id="networkColor" class="h-10 w-14 cursor-pointer rounded-lg border border-slate-300">
                        <span class="text-sm text-slate-500">{{ $networkColor }}</span>
                    </div>
                </x-ui.field>

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

    <x-ui.modal name="wallet-provider-form" max-width="sm">
        <form wire:submit="saveProvider" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $providerEditingId ? 'Edit Wallet Provider' : 'Add Wallet Provider' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Provider Name" name="providerName" for="providerName">
                    <x-ui.input wire:model="providerName" id="providerName" placeholder="e.g. JazzCash" autofocus />
                </x-ui.field>

                <x-ui.field label="Image" name="providerImage" for="providerImage" help="Optional">
                    <x-ui.file-input
                        wire:model="providerImage"
                        id="providerImage"
                        :preview="$this->previewUrl($providerImage, $providerExistingImageUrl)"
                    />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeProviderForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveProvider">
                    {{ $providerEditingId ? 'Save Changes' : 'Add Provider' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="shop-account-form" max-width="sm">
        <form wire:submit="saveShopAccount" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $shopAccountEditingId ? 'Edit Shop Account' : 'Add Shop Account' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Account Name" name="shopAccountName" for="shopAccountName" help="e.g. My JazzCash — 03xx-xxxxxxx">
                    <x-ui.input wire:model="shopAccountName" id="shopAccountName" autofocus />
                </x-ui.field>

                <x-ui.field label="Provider / Type" name="shopAccountProviderType" for="shopAccountProviderType" help="e.g. JazzCash, Easypaisa, Bank">
                    <x-ui.input wire:model="shopAccountProviderType" id="shopAccountProviderType" />
                </x-ui.field>
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

    <x-ui.modal name="bill-provider-form" max-width="sm">
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

                <x-ui.field label="Provider Name" name="billProviderName" for="billProviderName" help="e.g. LESCO, K-Electric">
                    <x-ui.input wire:model="billProviderName" id="billProviderName" />
                </x-ui.field>

                <x-ui.field label="Region / Province" name="billProviderRegion" for="billProviderRegion" help="Optional">
                    <x-ui.input wire:model="billProviderRegion" id="billProviderRegion" placeholder="e.g. Punjab" />
                </x-ui.field>
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
                    <div class="grid grid-cols-2 gap-3 rounded-lg border border-slate-200 p-4">
                        @foreach ($screens as $screen)
                            <x-ui.checkbox wire:model="rolePermissions" value="{{ $screen->value }}" :label="$screen->label()" />
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
