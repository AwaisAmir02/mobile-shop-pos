<?php

use App\Actions\CreateVendor;
use App\Livewire\Concerns\Toasts;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public string $name = '';
    public string $phone = '';

    public function save(): void
    {
        $this->name = trim($this->name);

        $this->validate([
            'name' => CreateVendor::rules()['name'],
            'phone' => CreateVendor::rules()['phone'],
        ]);

        $vendor = CreateVendor::handle($this->name, $this->phone);

        $this->toastSuccess('Vendor added.');
        $this->dispatch('close-modal', name: 'quick-create-vendor');
        $this->dispatch('vendor-created', vendorId: $vendor->id, vendorName: $vendor->name);
        $this->reset(['name', 'phone']);
    }

    public function close(): void
    {
        $this->dispatch('close-modal', name: 'quick-create-vendor');
        $this->reset(['name', 'phone']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ui.modal name="quick-create-vendor" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">New Vendor</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Name" name="name" for="quickVendorName">
                    <x-ui.input wire:model="name" id="quickVendorName" autofocus />
                </x-ui.field>

                <x-ui.field label="Phone" name="phone" for="quickVendorPhone" help="Optional">
                    <x-ui.input wire:model="phone" id="quickVendorPhone" type="tel" placeholder="03xx-xxxxxxx" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="close">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    Add Vendor
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
