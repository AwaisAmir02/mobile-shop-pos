<?php

use App\Livewire\Concerns\Toasts;
use App\Models\WalletLoad;
use App\Models\WalletProvider;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Settings')] class extends Component
{
    use Toasts;

    public ?int $editingId = null;
    public string $name = '';

    public function with(): array
    {
        return [
            'providers' => WalletProvider::query()->orderBy('name')->get(),
        ];
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', name: 'wallet-provider-form');
    }

    public function openEdit(int $id): void
    {
        $provider = WalletProvider::findOrFail($id);

        $this->editingId = $provider->id;
        $this->name = $provider->name;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'wallet-provider-form');
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('wallet_providers', 'name')->where('shop_id', auth()->user()->shop_id)->ignore($this->editingId)],
        ]);

        $provider = $this->editingId ? WalletProvider::findOrFail($this->editingId) : new WalletProvider;
        $provider->fill(['name' => $this->name])->save();

        $this->toastSuccess($this->editingId ? 'Wallet provider updated.' : 'Wallet provider added.');
        $this->dispatch('close-modal', name: 'wallet-provider-form');
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $provider = WalletProvider::findOrFail($id);

        if (WalletLoad::where('provider', $provider->name)->exists()) {
            $this->toastError("Cannot delete \"{$provider->name}\" — it has wallet load history. Past records are unaffected either way, but the safeguard keeps the list honest.");

            return;
        }

        $provider->delete();
        $this->toastSuccess('Wallet provider deleted.');
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'wallet-provider-form');
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'name']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Settings</h1>
    </x-slot>

    <x-ui.card title="Wallet Providers" description="Manage the providers available on the Wallet Load screen.">
        <x-slot name="actions">
            <x-ui.button size="sm" wire:click="openCreate">
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
                    <x-ui.button wire:click="openCreate">Add Provider</x-ui.button>
                </x-slot>
            </x-ui.empty-state>
        @else
            <x-ui.table :headers="['Provider', '']">
                @foreach ($providers as $provider)
                    <x-ui.table-row wire:key="provider-{{ $provider->id }}">
                        <x-ui.table-cell class="font-medium text-slate-900">{{ $provider->name }}</x-ui.table-cell>
                        <x-ui.table-cell align="right">
                            <div class="flex justify-end gap-2">
                                <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $provider->id }})">
                                    Edit
                                </x-ui.button>
                                <x-ui.button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="delete({{ $provider->id }})"
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

    <x-ui.modal name="wallet-provider-form" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $editingId ? 'Edit Wallet Provider' : 'Add Wallet Provider' }}
            </h2>

            <div class="mt-5">
                <x-ui.field label="Provider Name" name="name" for="name">
                    <x-ui.input wire:model="name" id="name" placeholder="e.g. JazzCash" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingId ? 'Save Changes' : 'Add Provider' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
