<?php

use App\Livewire\Concerns\Toasts;
use App\Models\BalanceLoad;
use App\Models\Network;
use App\Models\WalletLoad;
use App\Models\WalletProvider;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Settings')] class extends Component
{
    use Toasts;

    // Wallet Providers
    public ?int $providerEditingId = null;
    public string $providerName = '';

    // Networks
    public ?int $networkEditingId = null;
    public string $networkName = '';

    public function with(): array
    {
        return [
            'providers' => WalletProvider::query()->orderBy('name')->get(),
            'networks' => Network::query()->orderBy('name')->get(),
        ];
    }

    // ── Wallet Providers ─────────────────────────────────────────────

    public function openProviderCreate(): void
    {
        $this->reset(['providerEditingId', 'providerName']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'wallet-provider-form');
    }

    public function openProviderEdit(int $id): void
    {
        $provider = WalletProvider::findOrFail($id);

        $this->providerEditingId = $provider->id;
        $this->providerName = $provider->name;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'wallet-provider-form');
    }

    public function saveProvider(): void
    {
        $this->validate([
            'providerName' => ['required', 'string', 'max:255', Rule::unique('wallet_providers', 'name')->where('shop_id', auth()->user()->shop_id)->ignore($this->providerEditingId)],
        ]);

        $provider = $this->providerEditingId ? WalletProvider::findOrFail($this->providerEditingId) : new WalletProvider;
        $provider->fill(['name' => $this->providerName])->save();

        $this->toastSuccess($this->providerEditingId ? 'Wallet provider updated.' : 'Wallet provider added.');
        $this->dispatch('close-modal', name: 'wallet-provider-form');
        $this->reset(['providerEditingId', 'providerName']);
    }

    public function deleteProvider(int $id): void
    {
        $provider = WalletProvider::findOrFail($id);

        if (WalletLoad::where('provider', $provider->name)->exists()) {
            $this->toastError("Cannot delete \"{$provider->name}\" — it has wallet load history.");

            return;
        }

        $provider->delete();
        $this->toastSuccess('Wallet provider deleted.');
    }

    public function closeProviderForm(): void
    {
        $this->dispatch('close-modal', name: 'wallet-provider-form');
        $this->reset(['providerEditingId', 'providerName']);
    }

    // ── Networks ─────────────────────────────────────────────────────

    public function openNetworkCreate(): void
    {
        $this->reset(['networkEditingId', 'networkName']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'network-form');
    }

    public function openNetworkEdit(int $id): void
    {
        $network = Network::findOrFail($id);

        $this->networkEditingId = $network->id;
        $this->networkName = $network->name;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'network-form');
    }

    public function saveNetwork(): void
    {
        $this->validate([
            'networkName' => ['required', 'string', 'max:255', Rule::unique('networks', 'name')->where('shop_id', auth()->user()->shop_id)->ignore($this->networkEditingId)],
        ]);

        $network = $this->networkEditingId ? Network::findOrFail($this->networkEditingId) : new Network;
        $network->fill(['name' => $this->networkName])->save();

        $this->toastSuccess($this->networkEditingId ? 'Network updated.' : 'Network added.');
        $this->dispatch('close-modal', name: 'network-form');
        $this->reset(['networkEditingId', 'networkName']);
    }

    public function deleteNetwork(int $id): void
    {
        $network = Network::findOrFail($id);

        if (BalanceLoad::where('network', $network->name)->exists()) {
            $this->toastError("Cannot delete \"{$network->name}\" — it has balance load history.");

            return;
        }

        $network->delete();
        $this->toastSuccess('Network deleted.');
    }

    public function closeNetworkForm(): void
    {
        $this->dispatch('close-modal', name: 'network-form');
        $this->reset(['networkEditingId', 'networkName']);
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Settings</h1>
    </x-slot>

    <div class="space-y-6">
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
                            <x-ui.table-cell class="font-medium text-slate-900">{{ $network->name }}</x-ui.table-cell>
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
                            <x-ui.table-cell class="font-medium text-slate-900">{{ $provider->name }}</x-ui.table-cell>
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

    <x-ui.modal name="network-form" max-width="sm">
        <form wire:submit="saveNetwork" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $networkEditingId ? 'Edit Network' : 'Add Network' }}
            </h2>

            <div class="mt-5">
                <x-ui.field label="Network Name" name="networkName" for="networkName">
                    <x-ui.input wire:model="networkName" id="networkName" placeholder="e.g. Jazz" autofocus />
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

            <div class="mt-5">
                <x-ui.field label="Provider Name" name="providerName" for="providerName">
                    <x-ui.input wire:model="providerName" id="providerName" placeholder="e.g. JazzCash" autofocus />
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
