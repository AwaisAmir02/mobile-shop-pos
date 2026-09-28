<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Brand;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public string $name = '';

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('brands', 'name')->where('shop_id', Auth::user()->shop_id)],
        ]);

        $brand = Brand::create(['name' => $this->name]);

        $this->toastSuccess('Brand added.');
        $this->dispatch('close-modal', name: 'quick-create-brand');
        $this->dispatch('brand-created', name: $brand->name);
        $this->reset(['name']);
    }

    public function close(): void
    {
        $this->dispatch('close-modal', name: 'quick-create-brand');
        $this->reset(['name']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ui.modal name="quick-create-brand" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">New Brand</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Brand Name" name="name" for="quickBrandName">
                    <x-ui.input wire:model="name" id="quickBrandName" placeholder="e.g. Samsung" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="close">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    Add Brand
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
