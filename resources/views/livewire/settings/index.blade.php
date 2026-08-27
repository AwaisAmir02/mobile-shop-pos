<?php

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\UploadsImages;
use App\Models\AccessoryCategoryOption;
use App\Models\BalanceLoad;
use App\Models\Network;
use App\Models\Product;
use App\Models\WalletLoad;
use App\Models\WalletProvider;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Settings')] class extends Component
{
    use Toasts, UploadsImages, WithFileUploads;

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
    public string $networkColor = '#0c8f76';
    public $networkImage = null;
    public ?string $networkExistingImageUrl = null;

    public function with(): array
    {
        return [
            'accessoryCategories' => AccessoryCategoryOption::query()->orderBy('name')->get(),
            'providers' => WalletProvider::query()->orderBy('name')->get(),
            'networks' => Network::query()->orderBy('name')->get(),
        ];
    }

    // ── Accessory Categories ─────────────────────────────────────────

    public function openAccessoryCategoryCreate(): void
    {
        $this->reset(['accessoryCategoryEditingId', 'accessoryCategoryName', 'accessoryCategoryImage', 'accessoryCategoryExistingImageUrl']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'accessory-category-form');
    }

    public function openAccessoryCategoryEdit(int $id): void
    {
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
        $this->validate([
            'accessoryCategoryName' => ['required', 'string', 'max:255', Rule::unique('accessory_category_options', 'name')->where('shop_id', auth()->user()->shop_id)->ignore($this->accessoryCategoryEditingId)],
            'accessoryCategoryImage' => ['nullable', 'image', 'max:2048'],
        ]);

        $option = $this->accessoryCategoryEditingId ? AccessoryCategoryOption::findOrFail($this->accessoryCategoryEditingId) : new AccessoryCategoryOption;

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
        $this->reset(['providerEditingId', 'providerName', 'providerImage', 'providerExistingImageUrl']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'wallet-provider-form');
    }

    public function openProviderEdit(int $id): void
    {
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
        $this->validate([
            'providerName' => ['required', 'string', 'max:255', Rule::unique('wallet_providers', 'name')->where('shop_id', auth()->user()->shop_id)->ignore($this->providerEditingId)],
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
        $this->reset(['networkEditingId', 'networkName', 'networkImage', 'networkExistingImageUrl']);
        $this->networkColor = '#0c8f76';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'network-form');
    }

    public function openNetworkEdit(int $id): void
    {
        $network = Network::findOrFail($id);

        $this->networkEditingId = $network->id;
        $this->networkName = $network->name;
        $this->networkColor = $network->color ?? '#0c8f76';
        $this->networkImage = null;
        $this->networkExistingImageUrl = $network->imageUrl();

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'network-form');
    }

    public function saveNetwork(): void
    {
        $this->validate([
            'networkName' => ['required', 'string', 'max:255', Rule::unique('networks', 'name')->where('shop_id', auth()->user()->shop_id)->ignore($this->networkEditingId)],
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
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Settings</h1>
    </x-slot>

    <div class="space-y-6">
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
    </div>

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
</div>
