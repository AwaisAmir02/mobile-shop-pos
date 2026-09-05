<?php

use App\Actions\CreateWalletProvider;
use App\Livewire\Concerns\Toasts;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public string $name = '';

    public function save(): void
    {
        $this->validate(CreateWalletProvider::rules(Auth::user()->shop_id));

        $provider = CreateWalletProvider::handle($this->name);

        $this->toastSuccess('Wallet provider added.');
        $this->dispatch('close-modal', name: 'quick-create-wallet-provider');
        $this->dispatch('wallet-provider-created', providerName: $provider->name);
        $this->reset(['name']);
    }

    public function close(): void
    {
        $this->dispatch('close-modal', name: 'quick-create-wallet-provider');
        $this->reset(['name']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ui.modal name="quick-create-wallet-provider" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">New Wallet Provider</h2>

            <div class="mt-5">
                <x-ui.field label="Provider Name" name="name" for="quickWalletProviderName">
                    <x-ui.input wire:model="name" id="quickWalletProviderName" placeholder="e.g. SadaPay" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="close">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    Add Provider
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
