<?php

use App\Actions\CreateAccessoryCategory;
use App\Enums\ShopScreen;
use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\UploadsImages;
use App\Models\AccessoryCategoryOption;
use App\Models\BalanceLoad;
use App\Models\Network;
use App\Models\Product;
use App\Models\Role;
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

    // Accessory Categories
    public ?int $accessoryCategoryEditingId = null;
    public string $accessoryCategoryName = '';
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

    // Roles
    public ?int $roleEditingId = null;
    public string $roleName = '';
    public array $rolePermissions = [];

    public function mount(): void
    {
        $accessible = $this->accessibleTabs();

        if (! in_array($this->tab, $accessible, true)) {
            $this->tab = $accessible[0] ?? 'profile';
        }
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
            $tabs = [...$tabs, 'accessory-categories', 'networks', 'wallet-providers'];
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
            'accessoryCategories' => in_array('accessory-categories', $accessible, true)
                ? AccessoryCategoryOption::query()->orderBy('name')->get()
                : collect(),
            'providers' => in_array('wallet-providers', $accessible, true)
                ? WalletProvider::query()->orderBy('name')->get()
                : collect(),
            'networks' => in_array('networks', $accessible, true)
                ? Network::query()->orderBy('name')->get()
                : collect(),
            'roles' => in_array('roles', $accessible, true)
                ? Role::query()->withCount('users')->orderBy('name')->get()
                : collect(),
            'screens' => ShopScreen::cases(),
        ];
    }

    // ── Accessory Categories ─────────────────────────────────────────

    public function openAccessoryCategoryCreate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $this->reset(['accessoryCategoryEditingId', 'accessoryCategoryName', 'accessoryCategoryImage', 'accessoryCategoryExistingImageUrl']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'accessory-category-form');
    }

    public function openAccessoryCategoryEdit(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings'), 403);

        $option = AccessoryCategoryOption::findOrFail($id);

        $this->accessoryCategoryEditingId = $option->id;
        $this->accessoryCategoryName = $option->name;
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
            'accessoryCategoryImage' => ['nullable', 'image', 'max:2048'],
        ]);

        $option = $this->accessoryCategoryEditingId
            ? AccessoryCategoryOption::findOrFail($this->accessoryCategoryEditingId)
            : CreateAccessoryCategory::handle($this->accessoryCategoryName);

        $option->name = $this->accessoryCategoryName;

        if ($this->accessoryCategoryImage) {
            $option->image_path = $this->storeImage($this->accessoryCategoryImage, 'accessory-categories', $option->image_path);
        }

        $option->save();

        $this->toastSuccess($this->accessoryCategoryEditingId ? 'Accessory category updated.' : 'Accessory category added.');
        $this->dispatch('close-modal', name: 'accessory-category-form');
        $this->reset(['accessoryCategoryEditingId', 'accessoryCategoryName', 'accessoryCategoryImage', 'accessoryCategoryExistingImageUrl']);
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
        $this->toastSuccess('Accessory category deleted.');
    }

    public function closeAccessoryCategoryForm(): void
    {
        $this->dispatch('close-modal', name: 'accessory-category-form');
        $this->reset(['accessoryCategoryEditingId', 'accessoryCategoryName', 'accessoryCategoryImage', 'accessoryCategoryExistingImageUrl']);
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

        $provider = $this->providerEditingId ? WalletProvider::findOrFail($this->providerEditingId) : new WalletProvider;

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
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Settings</h1>
    </x-slot>

    @php
        $tabLabels = [
            'accessory-categories' => 'Accessory Categories',
            'networks' => 'Networks',
            'wallet-providers' => 'Wallet Providers',
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
        <x-ui.card title="Accessory Categories" description="Manage the categories available when adding an Accessory product.">
            <x-slot name="actions">
                <x-ui.button size="sm" wire:click="openAccessoryCategoryCreate">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Add Category
                </x-ui.button>
            </x-slot>

            @if ($accessoryCategories->isEmpty())
                <x-ui.empty-state
                    title="No accessory categories yet"
                    description="Add Case / Cover, Charger, Cable, or any other category your shop uses."
                >
                    <x-slot name="action">
                        <x-ui.button wire:click="openAccessoryCategoryCreate">Add Category</x-ui.button>
                    </x-slot>
                </x-ui.empty-state>
            @else
                <x-ui.table :headers="['Category', '']">
                    @foreach ($accessoryCategories as $option)
                        <x-ui.table-row wire:key="accessory-category-{{ $option->id }}">
                            <x-ui.table-cell class="font-medium text-slate-900">
                                <div class="flex items-center gap-3">
                                    <x-ui.thumbnail :src="$option->imageUrl()" :label="$option->name" />
                                    {{ $option->name }}
                                </div>
                            </x-ui.table-cell>
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

    <x-ui.modal name="accessory-category-form" max-width="sm">
        <form wire:submit="saveAccessoryCategory" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $accessoryCategoryEditingId ? 'Edit Accessory Category' : 'Add Accessory Category' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Category Name" name="accessoryCategoryName" for="accessoryCategoryName">
                    <x-ui.input wire:model="accessoryCategoryName" id="accessoryCategoryName" placeholder="e.g. Charger" autofocus />
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
                    {{ $accessoryCategoryEditingId ? 'Save Changes' : 'Add Category' }}
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
