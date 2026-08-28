<?php

use App\Actions\CreateShopAccount;
use App\Livewire\Concerns\Toasts;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public string $name = '';
    public string $providerType = '';

    public function save(): void
    {
        $this->validate(CreateShopAccount::rules(Auth::user()->shop_id));

        $account = CreateShopAccount::handle($this->name, $this->providerType);

        $this->toastSuccess('Shop account added.');
        $this->dispatch('close-modal', name: 'quick-create-shop-account');
        $this->dispatch('shop-account-created', shopAccountId: $account->id, shopAccountName: $account->name);
        $this->reset(['name', 'providerType']);
    }

    public function close(): void
    {
        $this->dispatch('close-modal', name: 'quick-create-shop-account');
        $this->reset(['name', 'providerType']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ui.modal name="quick-create-shop-account" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">New Shop Account</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Account Name" name="name" for="quickShopAccountName" help="e.g. My JazzCash — 03xx-xxxxxxx">
                    <x-ui.input wire:model="name" id="quickShopAccountName" autofocus />
                </x-ui.field>

                <x-ui.field label="Provider / Type" name="providerType" for="quickShopAccountProviderType" help="e.g. JazzCash, Easypaisa, Bank">
                    <x-ui.input wire:model="providerType" id="quickShopAccountProviderType" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="close">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    Add Account
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
